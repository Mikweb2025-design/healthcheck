<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Relations between metrics computed in code (Pearson r with sample size), so the AI only narrates numbers that exist.
 * A correlation is not a cause; pairs with fewer than MIN_N observations are not reported. Pure.
 */
final class Correlations {
	public const MIN_N = 20;

	public static function pearson(array $xs, array $ys): ?float {
		$n = count($xs);
		if ($n < 3 || $n !== count($ys)) {
			return null;
		}
		$mx = array_sum($xs) / $n;
		$my = array_sum($ys) / $n;
		$sxy = 0.0;
		$sxx = 0.0;
		$syy = 0.0;
		for ($i = 0; $i < $n; $i++) {
			$sxy += ($xs[$i] - $mx) * ($ys[$i] - $my);
			$sxx += ($xs[$i] - $mx) ** 2;
			$syy += ($ys[$i] - $my) ** 2;
		}
		return ($sxx < 1e-12 || $syy < 1e-12) ? null : $sxy / sqrt($sxx * $syy);
	}

	public static function strength(float $r): string {
		$a = abs($r);
		return $a < 0.1 ? 'none' : ($a < 0.3 ? 'weak' : ($a < 0.5 ? 'moderate' : 'strong'));
	}

	/** pairs (x of day d, y of day d + lag) */
	private static function pair(array $byDay, callable $x, callable $y, int $lag, string $from, string $to): array {
		$xs = [];
		$ys = [];
		foreach ($byDay as $day => $r) {
			if ($day < $from || $day > $to) {
				continue;
			}
			$o = $byDay[Insights::addDays($day, $lag)] ?? null;
			if ($o === null) {
				continue;
			}
			$a = $x($r);
			$b = $y($o);
			if ($a !== null && $b !== null) {
				$xs[] = (float)$a;
				$ys[] = (float)$b;
			}
		}
		return [$xs, $ys];
	}

	private static function result(string $key, array $xy, string $x, string $y, string $unitX, string $unitY): ?array {
		[$xs, $ys] = $xy;
		$n = count($xs);
		if ($n < self::MIN_N) {
			return null;
		}
		$r = self::pearson($xs, $ys);
		if ($r === null) {
			return null;
		}
		// slope of y per unit x, to make the relation tangible
		$mx = array_sum($xs) / $n;
		$my = array_sum($ys) / $n;
		$num = 0.0;
		$den = 0.0;
		foreach ($xs as $i => $v) {
			$num += ($v - $mx) * ($ys[$i] - $my);
			$den += ($v - $mx) ** 2;
		}
		return ['key' => $key, 'x' => $x, 'y' => $y, 'r' => round($r, 2), 'n' => $n, 'strength' => self::strength($r),
			'slope' => $den < 1e-12 ? null : round($num / $den, 4), 'unit_x' => $unitX, 'unit_y' => $unitY];
	}

	/**
	 * @param list<array> $rows daily rows, $workouts workouts; window: last $days days up to the last row
	 * @return array{from:string,to:string,pairs:list<array>,weekday:array}
	 */
	public static function compute(array $rows, array $workouts, int $days = 365): array {
		$to = $rows === [] ? gmdate('Y-m-d') : $rows[count($rows) - 1]['day'];
		$from = Insights::addDays($to, -($days - 1));
		$by = [];
		foreach ($rows as $r) {
			$by[$r['day']] = $r;
		}
		$wmin = [];
		foreach ($workouts as $w) {
			$wmin[$w['day']] = ($wmin[$w['day']] ?? 0) + (float)$w['duration_min'];
		}
		$g = fn (string $c) => fn ($r) => $r[$c] ?? null;
		$deepShare = fn ($r) => (($r['sleep_deep'] ?? null) !== null && (int)($r['sleep_total'] ?? 0) > 0) ? $r['sleep_deep'] / $r['sleep_total'] : null;
		$pairs = array_values(array_filter([
			self::result('sleep_rhr', self::pair($by, $g('sleep_total'), $g('resting_hr'), 0, $from, $to), 'sleep_total', 'resting_hr', 'min', 'bpm'),
			self::result('sleep_rhr_next', self::pair($by, $g('sleep_total'), $g('resting_hr'), 1, $from, $to), 'sleep_total', 'resting_hr_next_day', 'min', 'bpm'),
			self::result('steps_deep', self::pair($by, $g('steps'), $deepShare, 1, $from, $to), 'steps', 'deep_share_next_night', 'steps', 'fraction'),
			self::result('steps_sleep', self::pair($by, $g('steps'), $g('sleep_total'), 1, $from, $to), 'steps', 'sleep_next_night', 'steps', 'min'),
			self::result('kcal_rhr_next', self::pair($by, $g('active_kcal'), $g('resting_hr'), 1, $from, $to), 'active_kcal', 'resting_hr_next_day', 'kcal', 'bpm'),
			self::result('bedtime_sleep', self::pair($by, fn ($r) => self::bedMinutes($r), $g('sleep_total'), 0, $from, $to), 'bedtime_minutes_after_20h', 'sleep_total', 'min', 'min'),
			self::weeklyWorkoutWeight($rows, $wmin, $from, $to),
		]));
		return ['from' => $from, 'to' => $to, 'pairs' => $pairs, 'weekday' => self::weekday(Insights::range($rows, $from, $to))];
	}

	/** minutes after 20:00 local (Europe/Berlin) of the sleep start; null without sleep */
	public static function bedMinutes(array $r): ?int {
		if (($r['sleep_start'] ?? null) === null) {
			return null;
		}
		$t = (new \DateTimeImmutable('@' . (int)$r['sleep_start']))->setTimezone(new \DateTimeZone('Europe/Berlin'));
		$m = (int)$t->format('G') * 60 + (int)$t->format('i') - 20 * 60;
		return $m < 0 ? $m + 24 * 60 : $m;
	}

	/** workout minutes of a week vs weight change to the next week (weekly means) */
	private static function weeklyWorkoutWeight(array $rows, array $wmin, string $from, string $to): ?array {
		$weeks = [];
		foreach (Insights::range($rows, $from, $to) as $r) {
			$m = Agent::monday($r['day']);
			$weeks[$m]['w'][] = $r['weight'] ?? null;
			$weeks[$m]['min'] = ($weeks[$m]['min'] ?? 0) + ($wmin[$r['day']] ?? 0);
		}
		$xs = [];
		$ys = [];
		foreach ($weeks as $m => $wk) {
			$next = $weeks[Insights::addDays($m, 7)] ?? null;
			$a = array_filter($wk['w'], fn ($v) => $v !== null);
			$b = $next === null ? [] : array_filter($next['w'], fn ($v) => $v !== null);
			if ($a !== [] && $b !== []) {
				$xs[] = (float)$wk['min'];
				$ys[] = array_sum($b) / count($b) - array_sum($a) / count($a);
			}
		}
		$min = self::MIN_N;
		if (count($xs) < 8) {
			return null;
		}
		$r = self::pearson($xs, $ys);
		return $r === null ? null : ['key' => 'workout_weight', 'x' => 'workout_min_week', 'y' => 'weight_change_next_week', 'r' => round($r, 2), 'n' => count($xs),
			'strength' => self::strength($r), 'slope' => null, 'unit_x' => 'min', 'unit_y' => 'kg', 'note' => 'weeks, min ' . 8 . ' (daily pairs need ' . $min . ')'];
	}

	/** mean steps, sleep and resting HR per weekday (0 = Monday) */
	public static function weekday(array $rows): array {
		$acc = [];
		foreach ($rows as $r) {
			$wd = ((int)(new \DateTimeImmutable($r['day'] . ' 12:00 UTC'))->format('N')) - 1;
			foreach (['steps', 'sleep_total', 'resting_hr', 'active_kcal'] as $c) {
				if (($r[$c] ?? null) !== null) {
					$acc[$wd][$c][] = (float)$r[$c];
				}
			}
		}
		$out = [];
		for ($wd = 0; $wd < 7; $wd++) {
			$o = ['weekday' => $wd];
			foreach (['steps' => 0, 'sleep_total' => 0, 'resting_hr' => 1, 'active_kcal' => 0] as $c => $d) {
				$v = $acc[$wd][$c] ?? [];
				$o[$c] = $v === [] ? null : round(array_sum($v) / count($v), $d);
				$o[$c . '_n'] = count($v);
			}
			$out[] = $o;
		}
		return $out;
	}
}
