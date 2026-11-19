<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Data of the infographic for a week, month or year, computed only from the daily table and the workouts. Every number
 * carries its period and the number of days with data, so nothing is extrapolated silently. Pure.
 */
final class Infographic {
	public const PERIODS = ['week' => 7, 'month' => 30, 'year' => 365];

	private static function block(array $rows): array {
		$v = fn (string $c) => Insights::values($rows, $c);
		$avg = fn (string $c, int $d = 1) => ($a = Insights::avg($v($c))) === null ? null : round($a, $d);
		$sleepRows = array_values(array_filter($rows, fn ($r) => ($r['sleep_total'] ?? null) !== null && (int)$r['sleep_total'] > 0));
		$share = function (string $col) use ($sleepRows) {
			$tot = 0;
			$part = 0;
			foreach ($sleepRows as $r) {
				if (($r[$col] ?? null) !== null) {
					$tot += (int)$r['sleep_total'];
					$part += (int)$r[$col];
				}
			}
			return $tot === 0 ? null : round($part / $tot, 3);
		};
		return [
			'days_with_data' => count($rows),
			'sleep_avg' => $avg('sleep_total', 0), 'sleep_n' => count($sleepRows),
			'deep_share' => $share('sleep_deep'), 'rem_share' => $share('sleep_rem'), 'core_share' => $share('sleep_core'),
			'resting_hr' => $avg('resting_hr'), 'resting_hr_n' => count($v('resting_hr')),
			'spo2' => ($a = Insights::avg($v('spo2_avg'))) === null ? null : round($a * 100, 1), 'spo2_n' => count($v('spo2_avg')),
			'steps_avg' => $avg('steps', 0), 'steps_total' => (int)array_sum($v('steps')), 'steps_n' => count($v('steps')),
			'kcal_avg' => $avg('active_kcal', 0), 'kcal_n' => count($v('active_kcal')),
			'distance_km' => round(array_sum($v('distance_m')) / 1000, 1),
			'vo2max' => ($r = Insights::latestWith($rows, ['vo2max'])) ? round((float)$r['vo2max'], 1) : null,
			'weight' => ($r = Insights::latestWith($rows, ['weight'])) ? round((float)$r['weight'], 1) : null,
			'bodyfat' => ($r = Insights::latestWith($rows, ['bodyfat'])) ? round((float)$r['bodyfat'] * 100, 1) : null,
		];
	}

	private static function sports(array $workouts): array {
		$by = [];
		foreach ($workouts as $w) {
			$a = (string)$w['activity'];
			$b = $by[$a] ?? ['activity' => $a, 'count' => 0, 'minutes' => 0.0, 'km' => 0.0, 'km_minutes' => 0.0, 'kcal' => 0];
			$b['count']++;
			$b['minutes'] += (float)$w['duration_min'];
			if (($w['distance_m'] ?? null) !== null) {
				$b['km'] += (float)$w['distance_m'] / 1000;
				$b['km_minutes'] += (float)$w['duration_min'];
			}
			$b['kcal'] += (int)($w['kcal'] ?? 0);
			$by[$a] = $b;
		}
		$out = [];
		foreach ($by as $b) {
			$out[] = ['activity' => $b['activity'], 'count' => $b['count'], 'hours' => round($b['minutes'] / 60, 1), 'km' => round($b['km'], 1),
				'kmh' => $b['km_minutes'] > 0 ? round($b['km'] / ($b['km_minutes'] / 60), 1) : null, 'kcal' => $b['kcal']];
		}
		usort($out, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['activity'], $b['activity']));
		return $out;
	}

	private static function inRange(array $workouts, string $from, string $to): array {
		return array_values(array_filter($workouts, fn ($w) => $w['day'] >= $from && $w['day'] <= $to));
	}

	/** all-time personal records */
	public static function records(array $rows, array $workouts): array {
		$max = function (array $list, callable $val) {
			$best = null;
			$bv = null;
			foreach ($list as $x) {
				$v = $val($x);
				if ($v !== null && ($bv === null || $v > $bv)) {
					$bv = $v;
					$best = $x;
				}
			}
			return $best;
		};
		$ride = $max(array_filter($workouts, fn ($w) => $w['activity'] === 'Cycling'), fn ($w) => $w['distance_m'] ?? null);
		$run = $max(array_filter($workouts, fn ($w) => $w['activity'] === 'Running'), fn ($w) => $w['distance_m'] ?? null);
		$longest = $max($workouts, fn ($w) => (float)$w['duration_min']);
		$sleep = $max($rows, fn ($r) => ($r['sleep_total'] ?? null) === null || (int)$r['sleep_total'] > 720 ? null : (int)$r['sleep_total']);
		$deep = $max($rows, fn ($r) => ($r['sleep_deep'] ?? null) === null ? null : (int)$r['sleep_deep']);
		$steps = $max($rows, fn ($r) => $r['steps'] ?? null);
		$rhr = $max($rows, fn ($r) => ($r['resting_hr'] ?? null) === null ? null : -(float)$r['resting_hr']);
		// best ISO week by steps (weeks with at least 5 days of data)
		$weeks = [];
		foreach ($rows as $r) {
			if (($r['steps'] ?? null) !== null) {
				$m = Agent::monday($r['day']);
				$weeks[$m] = [($weeks[$m][0] ?? 0) + (int)$r['steps'], ($weeks[$m][1] ?? 0) + 1];
			}
		}
		$bw = null;
		foreach ($weeks as $m => [$sum, $n]) {
			if ($n >= 5 && ($bw === null || $sum > $bw['steps'])) {
				$bw = ['week_start' => $m, 'steps' => $sum, 'days' => $n];
			}
		}
		$w = fn ($x) => $x === null ? null : ['day' => $x['day'], 'km' => ($x['distance_m'] ?? null) === null ? null : round((float)$x['distance_m'] / 1000, 1),
			'minutes' => (int)round((float)$x['duration_min']), 'activity' => $x['activity']];
		return [
			'longest_ride' => $w($ride), 'longest_run' => $w($run), 'longest_workout' => $w($longest), 'best_week_steps' => $bw,
			'most_steps' => $steps ? ['day' => $steps['day'], 'steps' => (int)$steps['steps']] : null,
			'best_sleep' => $sleep ? ['day' => $sleep['day'], 'minutes' => (int)$sleep['sleep_total']] : null,
			'most_deep' => $deep ? ['day' => $deep['day'], 'minutes' => (int)$deep['sleep_deep']] : null,
			'lowest_rhr' => $rhr ? ['day' => $rhr['day'], 'bpm' => (float)$rhr['resting_hr']] : null,
		];
	}

	/** activity level 0..4 per day of the last 365 days (GitHub-style heatmap) */
	public static function heatmap(array $rows, array $workouts, string $end, int $days = 365): array {
		$wmin = [];
		foreach ($workouts as $w) {
			$wmin[$w['day']] = ($wmin[$w['day']] ?? 0) + (float)$w['duration_min'];
		}
		$byDay = [];
		foreach ($rows as $r) {
			$byDay[$r['day']] = $r;
		}
		$out = [];
		for ($i = $days - 1; $i >= 0; $i--) {
			$d = Insights::addDays($end, -$i);
			$steps = isset($byDay[$d]['steps']) ? (int)$byDay[$d]['steps'] : null;
			$m = $wmin[$d] ?? 0;
			if ($steps === null && $m == 0) {
				$level = null;
			} else {
				$pts = ($steps ?? 0) / 1000 + $m / 10; // 1 point per 1000 steps or 10 workout minutes
				$level = $pts < 3 ? 0 : ($pts < 6 ? 1 : ($pts < 9 ? 2 : ($pts < 13 ? 3 : 4)));
			}
			$out[] = ['day' => $d, 'level' => $level, 'steps' => $steps, 'workout_min' => (int)round($m)];
		}
		return $out;
	}

	/** share of days with data on which at least 2 of 3 daily goals (sleep, steps, active energy) were met */
	public static function consistency(array $rows, array $goals): array {
		$n = 0;
		$ok = 0;
		foreach ($rows as $r) {
			$checks = [];
			foreach ([['sleep_total', $goals['sleep_min']], ['steps', $goals['steps']], ['active_kcal', $goals['active_kcal']]] as [$c, $t]) {
				if (($r[$c] ?? null) !== null) {
					$checks[] = (float)$r[$c] >= $t;
				}
			}
			if (count($checks) >= 2) {
				$n++;
				$ok += count(array_filter($checks)) >= 2 ? 1 : 0;
			}
		}
		return ['score' => $n === 0 ? null : (int)round($ok / $n * 100), 'days_met' => $ok, 'days' => $n];
	}

	/** last known value of rarely measured metrics (VO2max, scale) with its day, when the period itself has none */
	public static function latest(array $rows): array {
		$out = [];
		foreach (['vo2max' => 1, 'weight' => 1, 'bodyfat' => 3] as $c => $d) {
			$r = Insights::latestWith($rows, [$c]);
			$out[$c] = $r === null ? null : ['value' => round((float)$r[$c] * ($c === 'bodyfat' ? 100 : 1), 1), 'day' => $r['day']];
		}
		return $out;
	}

	public static function build(array $rows, array $workouts, array $goals, string $period, ?string $end = null): array {
		$len = self::PERIODS[$period] ?? 7;
		$end ??= $rows === [] ? gmdate('Y-m-d') : $rows[count($rows) - 1]['day'];
		$from = Insights::addDays($end, -($len - 1));
		$pFrom = Insights::addDays($from, -$len);
		$pTo = Insights::addDays($from, -1);
		$bFrom = Insights::addDays($from, -365);
		$cur = Insights::range($rows, $from, $end);
		$wcur = self::inRange($workouts, $from, $end);
		$hist = Insights::range($rows, '0000-00-00', $end);
		return [
			'period' => ['kind' => $period, 'from' => $from, 'to' => $end, 'days' => $len],
			'current' => self::block($cur) + ['workouts' => count($wcur), 'workout_hours' => round(array_sum(array_column($wcur, 'duration_min')) / 60, 1)],
			'previous' => ['from' => $pFrom, 'to' => $pTo] + self::block(Insights::range($rows, $pFrom, $pTo)) + ['workouts' => count(self::inRange($workouts, $pFrom, $pTo))],
			'baseline' => ['from' => $bFrom, 'to' => $pTo, 'label' => '365d'] + self::block(Insights::range($rows, $bFrom, $pTo)),
			'sports' => self::sports($wcur),
			'records' => self::records($hist, self::inRange($workouts, '0000-00-00', $end)),
			'streaks' => ['steps' => Agent::streak($hist, 'steps', $goals['steps']), 'sleep' => Agent::streak($hist, 'sleep_total', $goals['sleep_min'])],
			'consistency' => self::consistency($cur, $goals),
			'latest' => self::latest($hist),
			'heatmap' => self::heatmap($rows, $workouts, $end, $period === 'year' ? 365 : ($period === 'month' ? 91 : 35)),
			'goals' => $goals,
		];
	}
}
