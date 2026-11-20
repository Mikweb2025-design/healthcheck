<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Rule-based coaching from the person's own daily rows: sleep regularity, social jet lag, load versus recovery and up to
 * three plain tips. Pure functions, no AI, no diagnoses; every tip compares the person only with their own recent past or
 * with their own goals. Texts live in the frontend (keys + params), so the language never leaks into the domain.
 */
final class Coach {
	/** @return array{regularity:?array,jetlag:?array,load:?array,tips:list<array{key:string,params:array<string,mixed>}>} */
	public static function compute(array $rows, array $goals, ?string $end, string $tz): array {
		if ($end === null || $rows === []) {
			return ['regularity' => null, 'jetlag' => null, 'load' => null, 'tips' => []];
		}
		$reg = self::regularity($rows, $end, $tz);
		$jet = self::jetlag($rows, $end, $tz);
		$load = self::load($rows, $end);
		return ['regularity' => $reg, 'jetlag' => $jet, 'load' => $load, 'tips' => self::tips($rows, $goals, $end, $reg, $jet, $load)];
	}

	/** minutes after $originHour (local) of a unix time, 0..1439 */
	public static function minutesAfter(int $ts, string $tz, int $originHour): int {
		$t = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($tz));
		$m = (int)$t->format('G') * 60 + (int)$t->format('i') - $originHour * 60;
		return $m < 0 ? $m + 1440 : $m;
	}

	/** @param list<float|int> $v */
	public static function sd(array $v): ?float {
		$n = count($v);
		if ($n < 2) {
			return null;
		}
		$m = array_sum($v) / $n;
		return sqrt(array_sum(array_map(fn ($x) => ($x - $m) ** 2, $v)) / ($n - 1));
	}

	/** bedtime and wake-up time spread over the last 14 nights; needs 7 nights */
	public static function regularity(array $rows, string $end, string $tz, int $days = 14): ?array {
		$bed = [];
		$wake = [];
		foreach (Insights::range($rows, Insights::addDays($end, -($days - 1)), $end) as $r) {
			if (($r['sleep_start'] ?? null) === null || ($r['sleep_end'] ?? null) === null || (float)($r['sleep_total'] ?? 0) < 120) {
				continue;
			}
			$bed[] = self::minutesAfter((int)$r['sleep_start'], $tz, 18);
			$wake[] = self::minutesAfter((int)$r['sleep_end'], $tz, 0);
		}
		if (count($bed) < 7) {
			return null;
		}
		$avgBed = (int)round(array_sum($bed) / count($bed));
		return ['n' => count($bed), 'bed_sd' => (int)round((float)self::sd($bed)), 'wake_sd' => (int)round((float)self::sd($wake)),
			'bed_avg' => sprintf('%02d:%02d', intdiv(($avgBed + 18 * 60) % 1440, 60), ($avgBed + 18 * 60) % 60)];
	}

	/** sleep midpoint on free days (wake-up on Saturday or Sunday) minus work days, last 28 days; needs 3 and 8 nights */
	public static function jetlag(array $rows, string $end, string $tz, int $days = 28): ?array {
		$free = [];
		$work = [];
		foreach (Insights::range($rows, Insights::addDays($end, -($days - 1)), $end) as $r) {
			if (($r['sleep_start'] ?? null) === null || ($r['sleep_end'] ?? null) === null || (float)($r['sleep_total'] ?? 0) < 120) {
				continue;
			}
			$mid = (self::minutesAfter((int)$r['sleep_start'], $tz, 18) + (self::minutesAfter((int)$r['sleep_start'], $tz, 18) + intdiv((int)$r['sleep_end'] - (int)$r['sleep_start'], 60))) / 2;
			(int)(new \DateTimeImmutable($r['day']))->format('N') >= 6 ? $free[] = $mid : $work[] = $mid;
		}
		if (count($free) < 3 || count($work) < 8) {
			return null;
		}
		return ['free_n' => count($free), 'work_n' => count($work), 'diff_min' => (int)round(array_sum($free) / count($free) - array_sum($work) / count($work))];
	}

	/** active energy of the last 7 days against the 21 days before, and the resting heart rate of the last 3 days against the 28 before */
	public static function load(array $rows, string $end): ?array {
		$k7 = Insights::values(Insights::range($rows, Insights::addDays($end, -6), $end), 'active_kcal');
		$kb = Insights::values(Insights::range($rows, Insights::addDays($end, -27), Insights::addDays($end, -7)), 'active_kcal');
		$r3 = Insights::values(Insights::range($rows, Insights::addDays($end, -2), $end), 'resting_hr');
		$rb = Insights::values(Insights::range($rows, Insights::addDays($end, -30), Insights::addDays($end, -3)), 'resting_hr');
		if (count($k7) < 4 || count($kb) < 10) {
			return null;
		}
		$ratio = Insights::avg($k7) / max(1.0, (float)Insights::avg($kb));
		$delta = (count($r3) >= 2 && count($rb) >= 10) ? Insights::avg($r3) - Insights::avg($rb) : null;
		$status = ($ratio >= 1.1 && $delta !== null && $delta >= 3) ? 'rest' : ($ratio >= 1.25 ? 'high' : ($ratio < 0.7 ? 'low' : 'balanced'));
		return ['kcal7' => (int)round((float)Insights::avg($k7)), 'kcal_base' => (int)round((float)Insights::avg($kb)), 'ratio' => round($ratio, 2),
			'rhr_delta' => $delta === null ? null : round($delta, 1), 'status' => $status];
	}

	/** @return list<array{key:string,params:array<string,mixed>}> */
	public static function tips(array $rows, array $goals, string $end, ?array $reg, ?array $jet, ?array $load): array {
		$tips = [];
		if ($load !== null && $load['status'] === 'rest') {
			$tips[] = ['key' => 'tip_rest', 'params' => ['delta' => $load['rhr_delta']]];
		}
		$sleep = Insights::values(Insights::range($rows, Insights::addDays($end, -6), $end), 'sleep_total');
		$goalSleep = (int)($goals['sleep_min'] ?? 450);
		if (count($sleep) >= 4 && Insights::avg($sleep) < $goalSleep - 30) {
			$tips[] = ['key' => 'tip_sleep_short', 'params' => ['avg' => (int)round((float)Insights::avg($sleep)), 'goal' => $goalSleep]];
		}
		if ($reg !== null && $reg['bed_sd'] >= 60) {
			$tips[] = ['key' => 'tip_bed_regular', 'params' => ['sd' => $reg['bed_sd'], 'avg' => $reg['bed_avg']]];
		}
		if ($jet !== null && abs($jet['diff_min']) >= 60) {
			$tips[] = ['key' => 'tip_jetlag', 'params' => ['min' => abs($jet['diff_min'])]];
		}
		$steps = Insights::values(Insights::range($rows, Insights::addDays($end, -6), $end), 'steps');
		$goalSteps = (int)($goals['steps'] ?? 8000);
		if (count($steps) >= 4 && $goalSteps > 0 && Insights::avg($steps) < $goalSteps * 0.8) {
			$tips[] = ['key' => 'tip_move', 'params' => ['avg' => (int)round((float)Insights::avg($steps)), 'goal' => $goalSteps]];
		}
		if ($load !== null && $load['status'] === 'low') {
			$tips[] = ['key' => 'tip_load_low', 'params' => ['pct' => (int)round((1 - $load['ratio']) * 100)]];
		}
		$tips = array_slice($tips, 0, 3);
		return $tips === [] ? [['key' => 'tip_good', 'params' => []]] : $tips;
	}
}
