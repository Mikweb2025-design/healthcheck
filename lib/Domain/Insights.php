<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Transparent scores, neutral early hints and period summaries from daily rows. Pure.
 *
 * Rows: list of ['day' => 'YYYY-MM-DD', <Aggregator::COLUMNS>...] sorted ascending.
 * Nothing here is a diagnosis: the hints only compare a person with their own recent past.
 */
final class Insights {
	/** targets used by the scores (documented in the README and shown in the app) */
	public const SLEEP_TARGET_MIN = 450;   // 7.5 h
	public const DEEP_TARGET = 0.15;       // share of deep sleep
	public const REM_TARGET = 0.20;
	public const STEPS_TARGET = 8000;
	public const KCAL_TARGET = 400;
	public const BASELINE_DAYS = 28;
	public const METRICS = ['resting_hr', 'hr_avg', 'spo2_avg', 'hrv', 'sleep_total', 'sleep_deep', 'sleep_rem', 'steps', 'distance_m',
		'active_kcal', 'flights', 'weight', 'bodyfat', 'vo2max'];

	public static function addDays(string $day, int $n): string {
		return (new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC')))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
	}

	/** @return list<array> rows with from <= day <= to */
	public static function range(array $rows, string $from, string $to): array {
		return array_values(array_filter($rows, fn ($r) => $r['day'] >= $from && $r['day'] <= $to));
	}

	/** @return list<float> non-null values of a column */
	public static function values(array $rows, string $col): array {
		$out = [];
		foreach ($rows as $r) {
			if (isset($r[$col]) && $r[$col] !== null && $r[$col] !== '') {
				$out[] = (float)$r[$col];
			}
		}
		return $out;
	}

	public static function avg(array $v): ?float {
		return $v === [] ? null : array_sum($v) / count($v);
	}

	public static function median(array $v): ?float {
		if ($v === []) {
			return null;
		}
		sort($v);
		$n = count($v);
		return $n % 2 ? (float)$v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
	}

	private static function clamp(float $v): int {
		return (int)round(max(0, min(100, $v)));
	}

	/** rows of the BASELINE_DAYS days before $day (not including it) */
	public static function baselineRows(array $rows, string $day): array {
		return self::range($rows, self::addDays($day, -self::BASELINE_DAYS), self::addDays($day, -1));
	}

	/** @return array{score:?int,parts:list<array>} */
	public static function sleepScore(?array $row): array {
		$t = $row['sleep_total'] ?? null;
		if ($t === null || (int)$t <= 0) {
			return ['score' => null, 'parts' => []];
		}
		$t = (int)$t;
		$parts = [['key' => 'duration', 'value' => $t, 'target' => self::SLEEP_TARGET_MIN, 'weight' => 60, 'points' => self::clamp($t / self::SLEEP_TARGET_MIN * 100)]];
		// stage shares count fully only for a night of normal length (a 3 h night cannot score high through its shares)
		$len = min(1.0, $t / self::SLEEP_TARGET_MIN);
		if (($row['sleep_deep'] ?? null) !== null) {
			$share = (int)$row['sleep_deep'] / $t;
			$parts[] = ['key' => 'deep', 'value' => round($share, 3), 'target' => self::DEEP_TARGET, 'weight' => 25, 'points' => self::clamp(min(100, $share / self::DEEP_TARGET * 100) * $len)];
		}
		if (($row['sleep_rem'] ?? null) !== null) {
			$share = (int)$row['sleep_rem'] / $t;
			$parts[] = ['key' => 'rem', 'value' => round($share, 3), 'target' => self::REM_TARGET, 'weight' => 15, 'points' => self::clamp(min(100, $share / self::REM_TARGET * 100) * $len)];
		}
		$score = self::weighted($parts);
		$awake = (int)($row['sleep_awake'] ?? 0);
		if ($awake > 60) {
			$parts[] = ['key' => 'awake', 'value' => $awake, 'target' => 60, 'weight' => 0, 'points' => -10];
			$score -= 10;
		}
		return ['score' => max(0, $score), 'parts' => $parts];
	}

	/** resting HR against the own 28-day median (70 %) and SpO2 (30 %) */
	public static function recoveryScore(?array $row, array $baselineRows): array {
		$rhr = $row['resting_hr'] ?? null;
		$base = self::median(self::values($baselineRows, 'resting_hr'));
		$parts = [];
		if ($rhr !== null && $base !== null) {
			$diff = (float)$rhr - $base;
			$parts[] = ['key' => 'rhr', 'value' => (float)$rhr, 'target' => round($base, 1), 'weight' => 70, 'points' => self::clamp(100 - max(0.0, $diff) * 10)];
		}
		$spo2 = $row['spo2_avg'] ?? null;
		if ($spo2 !== null) {
			$parts[] = ['key' => 'spo2', 'value' => (float)$spo2, 'target' => 0.97, 'weight' => 30, 'points' => self::clamp(((float)$spo2 - 0.92) / 0.05 * 100)];
		}
		if (($row['hrv'] ?? null) !== null) {
			$hb = self::median(self::values($baselineRows, 'hrv'));
			if ($hb !== null && $hb > 0) {
				$parts[] = ['key' => 'hrv', 'value' => (float)$row['hrv'], 'target' => round($hb, 1), 'weight' => 30, 'points' => self::clamp((float)$row['hrv'] / $hb * 100)];
			}
		}
		return ['score' => $parts === [] || $parts[0]['key'] !== 'rhr' ? null : self::weighted($parts), 'parts' => $parts];
	}

	public static function movementScore(?array $row): array {
		$parts = [];
		if (($row['steps'] ?? null) !== null) {
			$parts[] = ['key' => 'steps', 'value' => (int)$row['steps'], 'target' => self::STEPS_TARGET, 'weight' => 60, 'points' => self::clamp((int)$row['steps'] / self::STEPS_TARGET * 100)];
		}
		if (($row['active_kcal'] ?? null) !== null) {
			$parts[] = ['key' => 'kcal', 'value' => (int)$row['active_kcal'], 'target' => self::KCAL_TARGET, 'weight' => 40, 'points' => self::clamp((int)$row['active_kcal'] / self::KCAL_TARGET * 100)];
		}
		return ['score' => $parts === [] ? null : self::weighted($parts), 'parts' => $parts];
	}

	private static function weighted(array $parts): int {
		$w = 0;
		$s = 0;
		foreach ($parts as $p) {
			if ($p['weight'] > 0) {
				$w += $p['weight'];
				$s += $p['weight'] * $p['points'];
			}
		}
		return $w === 0 ? 0 : (int)round($s / $w);
	}

	/** last row (<= $day) that has a value in one of the columns */
	public static function latestWith(array $rows, array $cols, ?string $day = null): ?array {
		for ($i = count($rows) - 1; $i >= 0; $i--) {
			if ($day !== null && $rows[$i]['day'] > $day) {
				continue;
			}
			foreach ($cols as $c) {
				if (($rows[$i][$c] ?? null) !== null) {
					return $rows[$i];
				}
			}
		}
		return null;
	}

	/** the three rings for the most recent data */
	public static function rings(array $rows): array {
		$sleepRow = self::latestWith($rows, ['sleep_total']);
		$recRow = self::latestWith($rows, ['resting_hr']);
		// movement: the last complete day (the export day itself is usually cut off in the evening)
		$movRow = null;
		$cands = array_values(array_filter($rows, fn ($r) => ($r['steps'] ?? null) !== null));
		if ($cands !== []) {
			$movRow = count($cands) >= 2 ? $cands[count($cands) - 2] : $cands[0];
			$last = $cands[count($cands) - 1];
			if ($recRow !== null && $last['day'] <= $recRow['day']) {
				$movRow = $last;
			}
		}
		return [
			'sleep' => ['day' => $sleepRow['day'] ?? null] + self::sleepScore($sleepRow),
			'recovery' => ['day' => $recRow['day'] ?? null] + self::recoveryScore($recRow, $recRow ? self::baselineRows($rows, $recRow['day']) : []),
			'movement' => ['day' => $movRow['day'] ?? null] + self::movementScore($movRow),
		];
	}

	/**
	 * Neutral early hints, comparing the last days with the own 28-day baseline. Each: key, level, day, params.
	 * @return list<array{key:string,level:string,day:string,params:array}>
	 */
	public static function warnings(array $rows, ?string $day = null): array {
		$out = [];
		if ($rows === []) {
			return $out;
		}
		$day ??= $rows[count($rows) - 1]['day'];
		// resting heart rate >= baseline + 5 on the last two measured days
		$rhrRows = array_values(array_filter($rows, fn ($r) => $r['day'] <= $day && ($r['resting_hr'] ?? null) !== null));
		if (count($rhrRows) >= 2) {
			$last2 = array_slice($rhrRows, -2);
			$base = self::values(self::baselineRows($rows, $last2[0]['day']), 'resting_hr');
			if (count($base) >= 10) {
				$m = self::median($base);
				if ((float)$last2[0]['resting_hr'] >= $m + 5 && (float)$last2[1]['resting_hr'] >= $m + 5
					&& $last2[1]['day'] >= self::addDays($day, -3)) {
					$out[] = ['key' => 'rhr_up', 'level' => 'hint', 'day' => $last2[1]['day'],
						'params' => ['value' => round((float)$last2[1]['resting_hr']), 'base' => round($m, 1), 'days' => 2]];
				}
			}
		}
		$recent = fn (int $n) => self::range($rows, self::addDays($day, -($n - 1)), $day);
		$baseRows = self::baselineRows($rows, self::addDays($day, -6));
		// SpO2: the last 3 nights clearly lower than usual, or one below 93 %
		$s3 = self::values($recent(3), 'spo2_avg');
		$sb = self::values($baseRows, 'spo2_avg');
		if ($s3 !== []) {
			$a = self::avg($s3);
			$b = count($sb) >= 10 ? self::median($sb) : null;
			if (min($s3) < 0.93 || ($b !== null && $a <= $b - 0.015)) {
				$out[] = ['key' => 'spo2_drop', 'level' => 'hint', 'day' => $day,
					'params' => ['value' => round($a * 100, 1), 'min' => round(min($s3) * 100, 1), 'base' => $b === null ? null : round($b * 100, 1)]];
			}
		}
		// deep sleep share of the last 7 nights < 70 % of the usual share
		$share = function (array $rs): array {
			$v = [];
			foreach ($rs as $r) {
				if (($r['sleep_deep'] ?? null) !== null && (int)($r['sleep_total'] ?? 0) > 0) {
					$v[] = (int)$r['sleep_deep'] / (int)$r['sleep_total'];
				}
			}
			return $v;
		};
		$d7 = $share($recent(7));
		$db = $share($baseRows);
		if (count($d7) >= 4 && count($db) >= 14) {
			$a = self::avg($d7);
			$b = self::median($db);
			if ($b >= 0.08 && $a < 0.7 * $b) {
				$out[] = ['key' => 'deep_drop', 'level' => 'hint', 'day' => $day, 'params' => ['value' => round($a * 100), 'base' => round($b * 100)]];
			}
		}
		// short sleep on average over the last 7 nights
		$t7 = self::values($recent(7), 'sleep_total');
		if (count($t7) >= 4 && self::avg($t7) < 360) {
			$out[] = ['key' => 'sleep_short', 'level' => 'hint', 'day' => $day, 'params' => ['value' => (int)round(self::avg($t7)), 'nights' => count($t7)]];
		}
		// clearly less movement than usual
		$st7 = self::values(self::range($rows, self::addDays($day, -7), self::addDays($day, -1)), 'steps');
		$stb = self::values($baseRows, 'steps');
		if (count($st7) >= 5 && count($stb) >= 14) {
			$a = self::avg($st7);
			$b = self::avg($stb);
			if ($b > 1000 && $a < 0.5 * $b) {
				$out[] = ['key' => 'steps_drop', 'level' => 'hint', 'day' => $day, 'params' => ['value' => (int)round($a), 'base' => (int)round($b)]];
			}
		}
		// weight change of 3 % or more within about 30 days
		$wNow = self::latestWith($rows, ['weight'], $day);
		if ($wNow !== null && $wNow['day'] >= self::addDays($day, -14)) {
			$old = self::latestWith($rows, ['weight'], self::addDays($wNow['day'], -25));
			if ($old !== null && $old['day'] >= self::addDays($wNow['day'], -45)) {
				$ch = ((float)$wNow['weight'] - (float)$old['weight']) / (float)$old['weight'];
				if (abs($ch) >= 0.03) {
					$out[] = ['key' => 'weight_change', 'level' => 'hint', 'day' => $wNow['day'],
						'params' => ['value' => round((float)$wNow['weight'], 1), 'base' => round((float)$old['weight'], 1), 'pct' => round($ch * 100, 1), 'since' => $old['day']]];
				}
			}
		}
		return $out;
	}

	/** statistics of each metric over rows */
	public static function stats(array $rows): array {
		$out = [];
		foreach (self::METRICS as $m) {
			$v = self::values($rows, $m);
			$out[$m] = $v === [] ? null : ['avg' => round(self::avg($v), 4), 'min' => round(min($v), 4), 'max' => round(max($v), 4), 'n' => count($v)];
		}
		return $out;
	}

	/**
	 * Aggregates only (never single records): the last $days days against the BASELINE_DAYS days before, weekly
	 * averages of the last $trendDays days, workouts, scores and hints.
	 */
	public static function summary(array $rows, array $workouts, ?string $end = null, int $days = 7, int $trendDays = 90): array {
		$end ??= $rows === [] ? gmdate('Y-m-d') : $rows[count($rows) - 1]['day'];
		$from = self::addDays($end, -($days - 1));
		$cur = self::range($rows, $from, $end);
		$base = self::range($rows, self::addDays($from, -self::BASELINE_DAYS), self::addDays($from, -1));
		$sc = self::stats($cur);
		$sb = self::stats($base);
		$metrics = [];
		foreach (self::METRICS as $m) {
			$c = $sc[$m]['avg'] ?? null;
			$b = $sb[$m]['avg'] ?? null;
			$metrics[$m] = [
				'period' => $sc[$m], 'baseline' => $sb[$m] === null ? null : ['avg' => $sb[$m]['avg'], 'n' => $sb[$m]['n']],
				'delta' => ($c === null || $b === null) ? null : round($c - $b, 4),
				'delta_pct' => ($c === null || $b === null || abs($b) < 1e-9) ? null : round(($c - $b) / $b * 100, 1),
			];
		}
		// weekly trend (week = 7-day blocks ending at $end)
		$weeks = [];
		for ($i = intdiv($trendDays, 7) - 1; $i >= 0; $i--) {
			$we = self::addDays($end, -7 * $i);
			$ws = self::addDays($we, -6);
			$r = self::range($rows, $ws, $we);
			$wk = ['from' => $ws, 'to' => $we];
			foreach (['resting_hr', 'spo2_avg', 'sleep_total', 'sleep_deep', 'steps', 'active_kcal', 'weight', 'vo2max'] as $m) {
				$a = self::avg(self::values($r, $m));
				$wk[$m] = $a === null ? null : round($a, $m === 'spo2_avg' ? 4 : 1);
			}
			$weeks[] = $wk;
		}
		$fromTs = strtotime($from . ' 00:00:00 UTC') - 14 * 3600;
		$wsum = ['count' => 0, 'minutes' => 0, 'distance_km' => 0.0, 'kcal' => 0, 'by_activity' => []];
		foreach ($workouts as $w) {
			$d = $w['day'] ?? gmdate('Y-m-d', (int)$w['start_ts']);
			if ($d < $from || $d > $end || (int)$w['start_ts'] < $fromTs) {
				continue;
			}
			$wsum['count']++;
			$wsum['minutes'] += (int)round((float)$w['duration_min']);
			$wsum['distance_km'] += ((float)($w['distance_m'] ?? 0)) / 1000;
			$wsum['kcal'] += (int)($w['kcal'] ?? 0);
			$a = (string)$w['activity'];
			$wsum['by_activity'][$a] = ($wsum['by_activity'][$a] ?? 0) + 1;
		}
		$wsum['distance_km'] = round($wsum['distance_km'], 1);
		return [
			'period' => ['from' => $from, 'to' => $end, 'days' => $days, 'days_with_data' => count($cur)],
			'baseline' => ['from' => self::addDays($from, -self::BASELINE_DAYS), 'to' => self::addDays($from, -1), 'days_with_data' => count($base)],
			'metrics' => $metrics,
			'weeks' => $weeks,
			'workouts' => $wsum,
			'rings' => self::rings(self::range($rows, '0000-00-00', $end)),
			'warnings' => self::warnings($rows, $end),
			'units' => ['resting_hr' => 'bpm', 'hr_avg' => 'bpm', 'spo2_avg' => 'fraction 0-1', 'hrv' => 'ms SDNN', 'sleep_total' => 'minutes', 'sleep_deep' => 'minutes',
				'sleep_rem' => 'minutes', 'steps' => 'count/day', 'distance_m' => 'metres/day', 'active_kcal' => 'kcal/day', 'flights' => 'floors/day',
				'weight' => 'kg', 'bodyfat' => 'fraction 0-1', 'vo2max' => 'ml/kg/min'],
		];
	}
}
