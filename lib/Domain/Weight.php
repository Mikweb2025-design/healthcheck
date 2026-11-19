<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Weight as a first-class metric: current value, changes over 7/30/90 days, BMI with its WHO category, body fat,
 * 7-day moving average and an ETA towards the target weight at the current rate. Pure.
 */
final class Weight {
	/** WHO adult categories (a label, not an assessment) */
	public static function bmiCategory(?float $bmi): ?string {
		if ($bmi === null) {
			return null;
		}
		return $bmi < 18.5 ? 'under' : ($bmi < 25 ? 'normal' : ($bmi < 30 ? 'over' : 'obese'));
	}

	/** @return list<array{day:string,weight:float}> */
	public static function points(array $rows): array {
		$out = [];
		foreach ($rows as $r) {
			if (($r['weight'] ?? null) !== null) {
				$out[] = ['day' => $r['day'], 'weight' => (float)$r['weight']];
			}
		}
		return $out;
	}

	/** least squares slope in kg per day over points [day, weight] */
	public static function slope(array $pts): ?float {
		$n = count($pts);
		if ($n < 3) {
			return null;
		}
		$t0 = strtotime($pts[0]['day'] . ' 12:00 UTC');
		$xs = array_map(fn ($p) => (strtotime($p['day'] . ' 12:00 UTC') - $t0) / 86400, $pts);
		$ys = array_column($pts, 'weight');
		$mx = array_sum($xs) / $n;
		$my = array_sum($ys) / $n;
		$num = 0.0;
		$den = 0.0;
		foreach ($xs as $i => $x) {
			$num += ($x - $mx) * ($ys[$i] - $my);
			$den += ($x - $mx) ** 2;
		}
		return $den < 1e-9 ? null : $num / $den;
	}

	/** 7-day trailing moving average over weigh-in days */
	public static function movingAverage(array $pts, int $days = 7): array {
		$out = [];
		foreach ($pts as $i => $p) {
			$from = Insights::addDays($p['day'], -($days - 1));
			$win = array_filter(array_slice($pts, max(0, $i - 40), min($i + 1, 41)), fn ($q) => $q['day'] >= $from && $q['day'] <= $p['day']);
			$out[] = ['day' => $p['day'], 'weight' => round($p['weight'], 2), 'avg' => round(array_sum(array_column($win, 'weight')) / count($win), 2)];
		}
		return $out;
	}

	public static function stats(array $rows, ?float $goal, string $today): array {
		$pts = self::points($rows);
		if ($pts === []) {
			return ['has' => false, 'goal' => $goal];
		}
		$last = $pts[count($pts) - 1];
		$change = [];
		foreach ([7, 30, 90] as $d) {
			$ref = null;
			foreach ($pts as $p) {
				if ($p['day'] <= Insights::addDays($last['day'], -$d)) {
					$ref = $p;
				}
			}
			$change[$d] = $ref === null || $ref['day'] < Insights::addDays($last['day'], -($d + 30)) ? null
				: ['kg' => round($last['weight'] - $ref['weight'], 1), 'from' => $ref['day']];
		}
		$bmiRow = Insights::latestWith($rows, ['bmi']);
		$fatRow = Insights::latestWith($rows, ['bodyfat']);
		// rate from the weigh-ins of the last 60 days before the last one
		$recent = array_values(array_filter($pts, fn ($p) => $p['day'] >= Insights::addDays($last['day'], -60)));
		$slope = self::slope($recent);
		$eta = null;
		if ($goal !== null && $slope !== null) {
			$diff = $goal - $last['weight'];
			if (abs($diff) < 0.3) {
				$eta = ['reached' => true];
			} elseif (abs($slope) > 0.005 && ($diff > 0) === ($slope > 0)) {
				$days = (int)ceil($diff / $slope);
				if ($days <= 3 * 365) {
					$eta = ['reached' => false, 'days' => $days, 'date' => Insights::addDays($last['day'], $days)];
				}
			} else {
				$eta = ['reached' => false, 'days' => null, 'direction' => 'away'];
			}
		}
		$age = (int)round((strtotime($today . ' 12:00 UTC') - strtotime($last['day'] . ' 12:00 UTC')) / 86400);
		return [
			'has' => true,
			'last' => ['day' => $last['day'], 'kg' => round($last['weight'], 1), 'manual' => ($rows[array_search($last['day'], array_column($rows, 'day'), true)]['weight_src'] ?? null) === 'manual'],
			'age_days' => $age,
			'reminder' => $age > 7,
			'change' => $change,
			'bmi' => $bmiRow === null ? null : ['value' => round((float)$bmiRow['bmi'], 1), 'day' => $bmiRow['day'], 'category' => self::bmiCategory((float)$bmiRow['bmi'])],
			'bodyfat' => $fatRow === null ? null : ['pct' => round((float)$fatRow['bodyfat'] * ((float)$fatRow['bodyfat'] <= 1 ? 100 : 1), 1), 'day' => $fatRow['day']],
			'rate_kg_week' => $slope === null ? null : round($slope * 7, 2),
			'rate_n' => count($recent),
			'goal' => $goal,
			'eta' => $eta,
			'count' => count($pts),
			'first_day' => $pts[0]['day'],
		];
	}
}
