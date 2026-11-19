<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * The "health agent" logic that needs no AI: goals, adaptive weekly targets, streaks, evaluation of last week's plan
 * and rule based nudges after an import. Pure.
 */
final class Agent {
	public const DEFAULT_GOALS = ['sleep_min' => 450, 'steps' => 8000, 'workouts_week' => 3, 'active_kcal' => 400, 'weight' => null];

	/** @return array{sleep_min:int,steps:int,workouts_week:int,active_kcal:int,weight:?float} */
	public static function goals(?array $g): array {
		$g = is_array($g) ? $g : [];
		$out = self::DEFAULT_GOALS;
		$lim = ['sleep_min' => [240, 720], 'steps' => [1000, 40000], 'workouts_week' => [0, 14], 'active_kcal' => [50, 3000]];
		foreach ($lim as $k => [$lo, $hi]) {
			if (isset($g[$k]) && is_numeric($g[$k])) {
				$out[$k] = (int)max($lo, min($hi, (int)$g[$k]));
			}
		}
		$w = $g['weight'] ?? null;
		$out['weight'] = (is_numeric($w) && (float)$w >= 30 && (float)$w <= 250) ? round((float)$w, 1) : null;
		return $out;
	}

	/** Monday of the ISO week of $day */
	public static function monday(string $day): string {
		$dow = (int)(new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC')))->format('N');
		return Insights::addDays($day, 1 - $dow);
	}

	private static function countWorkouts(array $workouts, string $from, string $to): int {
		$n = 0;
		foreach ($workouts as $w) {
			if ($w['day'] >= $from && $w['day'] <= $to && (float)$w['duration_min'] >= 10) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Weekly targets that move towards the goal step by step: from the average of the last 4 weeks, at most +10 %
	 * steps / +15 min sleep / +1 workout per week, never above the goal.
	 */
	public static function weeklyTargets(array $goals, array $rows, array $workouts, string $weekStart): array {
		$from = Insights::addDays($weekStart, -28);
		$to = Insights::addDays($weekStart, -1);
		$past = Insights::range($rows, $from, $to);
		$steps = Insights::avg(Insights::values($past, 'steps'));
		$sleep = Insights::avg(Insights::values($past, 'sleep_total'));
		$kcal = Insights::avg(Insights::values($past, 'active_kcal'));
		$wpw = self::countWorkouts($workouts, $from, $to) / 4;
		$step = fn (?float $avg, int $goal, float $inc, int $round) => $avg === null || $avg >= $goal ? $goal
			: (int)min($goal, max($round, round(($avg + $inc) / $round) * $round));
		return [
			'week_start' => $weekStart,
			'steps' => $step($steps, $goals['steps'], ($steps ?? 0) * 0.10, 100),
			'sleep_min' => $step($sleep, $goals['sleep_min'], 15, 5),
			'active_kcal' => $step($kcal, $goals['active_kcal'], ($kcal ?? 0) * 0.10, 10),
			'workouts' => (int)min($goals['workouts_week'], (int)floor($wpw) + 1),
			'basis' => ['steps' => $steps === null ? null : (int)round($steps), 'sleep_min' => $sleep === null ? null : (int)round($sleep),
				'active_kcal' => $kcal === null ? null : (int)round($kcal), 'workouts_per_week' => round($wpw, 1), 'from' => $from, 'to' => $to],
		];
	}

	/** progress of the week that contains $day against its adaptive targets */
	public static function progress(array $goals, array $rows, array $workouts, string $day): array {
		$ws = self::monday($day);
		$t = self::weeklyTargets($goals, $rows, $workouts, $ws);
		$cur = Insights::range($rows, $ws, $day);
		$days = fn (string $col, int $target) => count(array_filter($cur, fn ($r) => ($r[$col] ?? null) !== null && (float)$r[$col] >= $target));
		$avg = fn (string $col) => ($a = Insights::avg(Insights::values($cur, $col))) === null ? null : (int)round($a);
		return [
			'week_start' => $ws, 'day' => $day, 'targets' => $t,
			'steps' => ['avg' => $avg('steps'), 'days_met' => $days('steps', $t['steps'])],
			'sleep' => ['avg' => $avg('sleep_total'), 'days_met' => $days('sleep_total', $t['sleep_min'])],
			'active_kcal' => ['avg' => $avg('active_kcal'), 'days_met' => $days('active_kcal', $t['active_kcal'])],
			'workouts' => ['done' => self::countWorkouts($workouts, $ws, Insights::addDays($ws, 6)), 'target' => $t['workouts']],
			'weight' => ['goal' => $goals['weight'], 'last' => ($r = Insights::latestWith($rows, ['weight'], $day)) ? (float)$r['weight'] : null],
			'days_in_week' => count($cur),
		];
	}

	/** consecutive days up to the last day with data on which $col >= $target; and the longest such run */
	public static function streak(array $rows, string $col, float $target): array {
		$cur = 0;
		$best = 0;
		$run = 0;
		$prev = null;
		foreach ($rows as $r) {
			$ok = ($r[$col] ?? null) !== null && (float)$r[$col] >= $target;
			$contiguous = $prev !== null && Insights::addDays($prev, 1) === $r['day'];
			$run = $ok ? ($contiguous ? $run + 1 : 1) : 0;
			$best = max($best, $run);
			$prev = $r['day'];
		}
		$cur = $run;
		return ['current' => $cur, 'best' => $best];
	}

	/**
	 * Was last week's plan followed? plan = {days:[{date, kind: train|easy|rest}], bedtime:"HH:MM"}
	 * train: a workout of >= 20 min that day; rest: no workout >= 30 min; easy: no workout >= 60 min.
	 * bedtime: sleep started at most 30 min after the target (time zone of $tz).
	 */
	public static function evaluatePlan(?array $plan, array $rows, array $workouts, string $tz = 'Europe/Berlin'): ?array {
		if (!is_array($plan) || !is_array($plan['days'] ?? null) || $plan['days'] === []) {
			return null;
		}
		$byDay = [];
		foreach ($workouts as $w) {
			$byDay[$w['day']][] = (float)$w['duration_min'];
		}
		$rowsByDay = [];
		foreach ($rows as $r) {
			$rowsByDay[$r['day']] = $r;
		}
		$details = [];
		$met = 0;
		$n = 0;
		foreach ($plan['days'] as $d) {
			$date = (string)($d['date'] ?? '');
			$kind = (string)($d['kind'] ?? '');
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($kind, ['train', 'easy', 'rest'], true)) {
				continue;
			}
			$longest = max([0.0] + ($byDay[$date] ?? []));
			$known = isset($rowsByDay[$date]) || isset($byDay[$date]);
			$ok = !$known ? null : match ($kind) { 'train' => $longest >= 20, 'rest' => $longest < 30, default => $longest < 60 };
			if ($ok !== null) {
				$n++;
				$met += $ok ? 1 : 0;
			}
			$details[] = ['date' => $date, 'kind' => $kind, 'ok' => $ok, 'workout_min' => (int)round($longest)];
		}
		$bed = null;
		if (preg_match('/^(\d{1,2}):(\d{2})$/', (string)($plan['bedtime'] ?? ''), $m)) {
			$target = (int)$m[1] * 60 + (int)$m[2];
			$hit = 0;
			$nights = 0;
			foreach ($details as $d) {
				$r = $rowsByDay[$d['date']] ?? null;
				if ($r === null || ($r['sleep_start'] ?? null) === null) {
					continue;
				}
				$t = (new \DateTimeImmutable('@' . (int)$r['sleep_start']))->setTimezone(new \DateTimeZone($tz));
				$min = (int)$t->format('G') * 60 + (int)$t->format('i');
				if ($min < 12 * 60) {
					$min += 24 * 60; // after midnight
				}
				$tt = $target < 12 * 60 ? $target + 24 * 60 : $target;
				$nights++;
				$hit += $min <= $tt + 30 ? 1 : 0;
			}
			$bed = ['target' => sprintf('%02d:%02d', intdiv($target, 60), $target % 60), 'nights' => $nights, 'on_time' => $hit];
		}
		return ['days_evaluated' => $n, 'days_met' => $met, 'adherence_pct' => $n === 0 ? null : (int)round($met / $n * 100), 'details' => $details, 'bedtime' => $bed];
	}

	/** keeps only well-formed plan entries coming from the AI (dates, kinds, short notes) */
	public static function sanitizePlan(mixed $plan): ?array {
		if (!is_array($plan)) {
			return null;
		}
		$days = [];
		foreach (array_slice(is_array($plan['days'] ?? null) ? $plan['days'] : [], 0, 7) as $d) {
			if (!is_array($d)) {
				continue;
			}
			$date = (string)($d['date'] ?? '');
			$kind = strtolower((string)($d['kind'] ?? ''));
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && in_array($kind, ['train', 'easy', 'rest'], true)) {
				$days[] = ['date' => $date, 'kind' => $kind, 'note' => mb_substr(trim((string)($d['note'] ?? '')), 0, 160)];
			}
		}
		if ($days === []) {
			return null;
		}
		$bed = preg_match('/^\d{1,2}:\d{2}$/', (string)($plan['bedtime'] ?? '')) ? (string)$plan['bedtime'] : null;
		return ['days' => $days, 'bedtime' => $bed, 'focus' => mb_substr(trim((string)($plan['focus'] ?? '')), 0, 300)];
	}

	/**
	 * Rule based nudges after an import. Returns [key, params] pairs; the caller sends each key at most once per day.
	 * @return list<array{key:string,params:array}>
	 */
	public static function nudges(array $rows, array $goals): array {
		if ($rows === []) {
			return [];
		}
		$out = [];
		$rings = Insights::rings($rows);
		$warn = array_column(Insights::warnings($rows), null, 'key');
		$rec = $rings['recovery']['score'];
		if (isset($warn['rhr_up']) || ($rec !== null && $rec < 50)) {
			$out[] = ['key' => 'rest_day', 'params' => ['score' => $rec ?? '', 'rhr' => $warn['rhr_up']['params']['value'] ?? '']];
		}
		$w = Weight::stats($rows, $goals['weight'] ?? null, $rows[count($rows) - 1]['day']);
		if ($w['has'] && $w['reminder'] && ($goals['weight'] ?? null) !== null) {
			$out[] = ['key' => 'weigh_in', 'params' => ['days' => $w['age_days'], 'last' => $w['last']['kg']]];
		}
		foreach (['steps' => ['steps', $goals['steps']], 'sleep' => ['sleep_total', $goals['sleep_min']]] as $name => [$col, $target]) {
			$s = self::streak($rows, $col, (float)$target);
			if ($s['current'] >= 7 && $s['current'] % 7 === 0) {
				$out[] = ['key' => 'streak_' . $name, 'params' => ['days' => $s['current']]];
			}
		}
		return $out;
	}
}
