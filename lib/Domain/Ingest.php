<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Tolerant parser for the daily push from Apple Shortcuts ("Find Health Samples" + "Get Details of Health Samples").
 *
 * Accepted payload shapes (JSON object):
 *  1. columnar, flat (what the provided shortcut sends): "<type>_values", "<type>_starts", "<type>_ends", "<type>_units",
 *     "<type>_sources" as newline separated texts of equal length (units/sources may be a single line)
 *  2. "samples": [{type, value, unit, start, end, source}, ...]
 *  3. "lines": "type;value;unit;start;end;source\n..."
 *  plus "workout_*" columns or "workouts": [{type, start, end, duration, distance, energy, source}]
 * Types may be our keys (heart_rate, ...), HealthKit identifiers or localized display names (en/de/it).
 * Dates: ISO 8601, Apple export format, dd.mm.yyyy[, hh:mm], dd/mm/yyyy, "18 nov 2026, 23:41", "18. Nov. 2026 um 23:41".
 * Numbers: decimal comma or point, thousands separators, units glued to the value ("62 bpm", "97 %").
 */
final class Ingest {
	public const MAX_BYTES = 3_000_000;
	public const MAX_SAMPLES = 40000;

	/** our key => HealthKit identifier */
	public const TYPES = [
		'heart_rate' => 'HKQuantityTypeIdentifierHeartRate',
		'resting_hr' => 'HKQuantityTypeIdentifierRestingHeartRate',
		'spo2' => 'HKQuantityTypeIdentifierOxygenSaturation',
		'hrv' => 'HKQuantityTypeIdentifierHeartRateVariabilitySDNN',
		'steps' => 'HKQuantityTypeIdentifierStepCount',
		'distance' => 'HKQuantityTypeIdentifierDistanceWalkingRunning',
		'cycling' => 'HKQuantityTypeIdentifierDistanceCycling',
		'active_energy' => 'HKQuantityTypeIdentifierActiveEnergyBurned',
		'flights' => 'HKQuantityTypeIdentifierFlightsClimbed',
		'weight' => 'HKQuantityTypeIdentifierBodyMass',
		'bmi' => 'HKQuantityTypeIdentifierBodyMassIndex',
		'body_fat' => 'HKQuantityTypeIdentifierBodyFatPercentage',
		'vo2max' => 'HKQuantityTypeIdentifierVO2Max',
		'sleep' => 'HKCategoryTypeIdentifierSleepAnalysis',
	];

	/** localized display names (lower case, en/de/it) => our key */
	private const NAMES = [
		'heart rate' => 'heart_rate', 'herzfrequenz' => 'heart_rate', 'frequenza cardiaca' => 'heart_rate', 'battito cardiaco' => 'heart_rate',
		'resting heart rate' => 'resting_hr', 'ruheherzfrequenz' => 'resting_hr', 'ruhepuls' => 'resting_hr', 'frequenza cardiaca a riposo' => 'resting_hr',
		'oxygen saturation' => 'spo2', 'blood oxygen' => 'spo2', 'blutsauerstoff' => 'spo2', 'sauerstoffsättigung' => 'spo2', 'ossigeno nel sangue' => 'spo2', 'saturazione ossigeno' => 'spo2', 'saturazione dell’ossigeno' => 'spo2',
		'heart rate variability' => 'hrv', 'herzfrequenzvariabilität' => 'hrv', 'variabilità della frequenza cardiaca' => 'hrv',
		'steps' => 'steps', 'schritte' => 'steps', 'passi' => 'steps',
		'walking + running distance' => 'distance', 'distanza camminata + corsa' => 'distance', 'distanz (gehen und laufen)' => 'distance', 'gehen + laufen distanz' => 'distance',
		'cycling distance' => 'cycling', 'distanza in bicicletta' => 'cycling', 'distanz (radfahren)' => 'cycling',
		'active energy' => 'active_energy', 'aktive energie' => 'active_energy', 'energia attiva' => 'active_energy',
		'flights climbed' => 'flights', 'etagen' => 'flights', 'gestiegene etagen' => 'flights', 'piani saliti' => 'flights',
		'weight' => 'weight', 'body mass' => 'weight', 'gewicht' => 'weight', 'peso' => 'weight',
		'body mass index' => 'bmi', 'body-mass-index' => 'bmi', 'indice di massa corporea' => 'bmi',
		'body fat percentage' => 'body_fat', 'körperfettanteil' => 'body_fat', 'percentuale di grasso corporeo' => 'body_fat', 'massa grassa' => 'body_fat',
		'vo2 max' => 'vo2max', 'vo2max' => 'vo2max', 'cardio fitness' => 'vo2max', 'cardiofitness' => 'vo2max',
		'sleep' => 'sleep', 'sleep analysis' => 'sleep', 'schlaf' => 'sleep', 'schlafanalyse' => 'sleep', 'sonno' => 'sleep', 'analisi del sonno' => 'sleep',
	];

	private const MONTHS = ['jan' => 1, 'gen' => 1, 'feb' => 2, 'mar' => 3, 'mär' => 3, 'mrz' => 3, 'apr' => 4, 'may' => 5, 'mai' => 5, 'mag' => 5, 'jun' => 6, 'giu' => 6,
		'jul' => 7, 'lug' => 7, 'aug' => 8, 'ago' => 8, 'sep' => 9, 'set' => 9, 'oct' => 10, 'okt' => 10, 'ott' => 10, 'nov' => 11, 'dec' => 12, 'dez' => 12, 'dic' => 12];

	public static function typeKey(string $t): ?string {
		$t = trim($t);
		if (isset(self::TYPES[$t])) {
			return $t;
		}
		$hk = array_search($t, self::TYPES, true);
		if ($hk !== false) {
			return $hk;
		}
		$l = mb_strtolower(str_replace(['’', "'"], ['’', '’'], $t));
		return self::NAMES[$l] ?? null;
	}

	/** "62,5" / "1.234,5" / "1,234.5" / "62 bpm" / "97 %" => float */
	public static function number(mixed $v): ?float {
		if (is_int($v) || is_float($v)) {
			return (float)$v;
		}
		$s = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', (string)$v));
		if (!preg_match('/^[-+]?[\d.,]+/', $s, $m)) {
			return null;
		}
		$n = $m[0];
		$lastComma = strrpos($n, ',');
		$lastDot = strrpos($n, '.');
		if ($lastComma !== false && $lastDot !== false) {
			$n = $lastComma > $lastDot ? str_replace(['.', ','], ['', '.'], $n) : str_replace(',', '', $n);
		} elseif ($lastComma !== false) {
			// "1,234" with exactly 3 decimals and a single comma is ambiguous: Shortcuts in it/de uses comma decimals
			$n = substr_count($n, ',') > 1 ? str_replace(',', '', $n) : str_replace(',', '.', $n);
		} elseif (substr_count($n, '.') > 1) {
			$n = str_replace('.', '', $n);
		}
		return is_numeric($n) ? (float)$n : null;
	}

	/** unit text glued to a value, e.g. "62 bpm" => "bpm" */
	public static function unitOf(string $v): string {
		return trim((string)preg_replace('/^[-+]?[\d.,\s\x{00A0}\x{202F}]+/u', '', $v));
	}

	public static function date(mixed $v, \DateTimeZone $tz): ?int {
		if (is_int($v) || (is_numeric($v) && (float)$v > 1e9)) {
			$n = (float)$v;
			return (int)($n > 1e12 ? $n / 1000 : $n);
		}
		$s = trim((string)$v);
		if ($s === '') {
			return null;
		}
		foreach (['Y-m-d H:i:s O', DATE_ATOM, 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:sO', 'Y-m-d\TH:i:s.vO', 'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:sP'] as $f) {
			$d = \DateTimeImmutable::createFromFormat($f, $s, $tz);
			if ($d !== false) {
				return $d->getTimestamp();
			}
		}
		$l = mb_strtolower($s);
		$l = str_replace([' um ', ' alle ', ' at ', ' ore ', ','], ' ', $l);
		$l = (string)preg_replace('/\s+/', ' ', trim($l));
		$h = 0;
		$mi = 0;
		$se = 0;
		if (preg_match('/(?<=\s)(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?\s*(am|pm)?(?:\s*(?:uhr|h))?\s*$/', $l, $tm, PREG_OFFSET_CAPTURE)) {
			$h = (int)$tm[1][0];
			$mi = (int)$tm[2][0];
			$se = isset($tm[3]) && $tm[3][0] !== '' ? (int)$tm[3][0] : 0;
			if (isset($tm[4]) && $tm[4][0] === 'pm' && $h < 12) {
				$h += 12;
			} elseif (isset($tm[4]) && $tm[4][0] === 'am' && $h === 12) {
				$h = 0;
			}
			$l = trim(substr($l, 0, $tm[0][1]));
		}
		$y = $mo = $d = null;
		if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $l, $m)) {
			[$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
		} elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})/', $l, $m)) {
			[$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
		} elseif (preg_match('/^(\d{1,2})\.?\s*([a-zäéû]{3,})\.?\s*(\d{4})/u', $l, $m)) {
			$mo = self::MONTHS[mb_substr($m[2], 0, 3)] ?? null;
			[$d, $y] = [(int)$m[1], (int)$m[3]];
		} elseif (preg_match('/^([a-z]{3,})\.?\s*(\d{1,2}),?\s*(\d{4})/', $l, $m)) {
			$mo = self::MONTHS[substr($m[1], 0, 3)] ?? null;
			[$d, $y] = [(int)$m[2], (int)$m[3]];
		}
		if ($y === null || $mo === null || $d === null) {
			return null;
		}
		if ($y < 100) {
			$y += 2000;
		}
		if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59) {
			return null;
		}
		return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $se), $tz))->getTimestamp();
	}

	/** sleep sample value (also localized) => HealthKit category value, null for "in bed"/unknown */
	public static function sleepValue(string $v): ?string {
		$l = mb_strtolower(trim($v));
		if (str_starts_with($l, 'hkcategoryvaluesleepanalysis')) {
			return 'HKCategoryValueSleepAnalysis' . substr($v, strlen('HKCategoryValueSleepAnalysis'));
		}
		$map = [
			'deep' => 'AsleepDeep', 'tief' => 'AsleepDeep', 'profond' => 'AsleepDeep',
			'rem' => 'AsleepREM',
			'core' => 'AsleepCore', 'kern' => 'AsleepCore', 'leggero' => 'AsleepCore', 'base' => 'AsleepCore', 'light' => 'AsleepCore',
			'awake' => 'Awake', 'wach' => 'Awake', 'svegli' => 'Awake', 'sveglio' => 'Awake',
			'in bed' => null, 'im bett' => null, 'a letto' => null, 'nel letto' => null,
			'asleep' => 'AsleepUnspecified', 'schlaf' => 'AsleepUnspecified', 'sonno' => 'AsleepUnspecified', 'addormentat' => 'AsleepUnspecified', 'dorm' => 'AsleepUnspecified',
		];
		foreach ($map as $k => $hk) {
			if (str_contains($l, $k)) {
				return $hk === null ? null : 'HKCategoryValueSleepAnalysis' . $hk;
			}
		}
		if (is_numeric($l)) { // raw HealthKit enum: 0 in bed, 1 asleep, 2 awake, 3 core, 4 deep, 5 rem
			return [1 => 'HKCategoryValueSleepAnalysisAsleepUnspecified', 2 => 'HKCategoryValueSleepAnalysisAwake', 3 => 'HKCategoryValueSleepAnalysisAsleepCore',
				4 => 'HKCategoryValueSleepAnalysisAsleepDeep', 5 => 'HKCategoryValueSleepAnalysisAsleepREM'][(int)$l] ?? null;
		}
		return null;
	}

	private static function lines(mixed $v): array {
		if (is_array($v)) {
			return array_values(array_map(fn ($x) => is_scalar($x) ? (string)$x : '', $v));
		}
		$s = str_replace(["\r\n", "\r"], "\n", (string)$v);
		return $s === '' ? [] : explode("\n", $s);
	}

	/**
	 * @return array{samples:list<array{type:string,hk:string,value:string,unit:string,start:int,end:int,source:string}>,workouts:list<array>,rejected:int}
	 */
	public static function parse(array $p, \DateTimeZone $tz): array {
		$raw = [];
		// shape 2
		foreach (is_array($p['samples'] ?? null) ? $p['samples'] : [] as $s) {
			if (is_array($s)) {
				$raw[] = $s;
			}
		}
		// shape 3
		foreach (self::lines($p['lines'] ?? '') as $line) {
			$c = str_getcsv($line, ';', '"', '');
			if (count($c) >= 4) {
				$raw[] = ['type' => $c[0], 'value' => $c[1], 'unit' => $c[2] ?? '', 'start' => $c[3], 'end' => $c[4] ?? $c[3], 'source' => $c[5] ?? ''];
			}
		}
		// shape 1
		foreach ($p as $k => $v) {
			if (!is_string($k) || !str_ends_with($k, '_values')) {
				continue;
			}
			$t = substr($k, 0, -7);
			if ($t === 'workout') {
				continue;
			}
			$vals = self::lines($v);
			$starts = self::lines($p[$t . '_starts'] ?? '');
			$ends = self::lines($p[$t . '_ends'] ?? '');
			$units = self::lines($p[$t . '_units'] ?? '');
			$srcs = self::lines($p[$t . '_sources'] ?? '');
			foreach ($vals as $i => $val) {
				if (trim($val) === '') {
					continue;
				}
				$raw[] = ['type' => $t, 'value' => $val, 'start' => $starts[$i] ?? '', 'end' => $ends[$i] ?? ($starts[$i] ?? ''),
					'unit' => $units[$i] ?? ($units[0] ?? ''), 'source' => $srcs[$i] ?? (count($srcs) === 1 ? $srcs[0] : '')];
			}
		}
		$samples = [];
		$rejected = 0;
		foreach (array_slice($raw, 0, self::MAX_SAMPLES) as $s) {
			$key = self::typeKey((string)($s['type'] ?? ''));
			$start = self::date($s['start'] ?? ($s['startDate'] ?? null), $tz);
			$end = self::date($s['end'] ?? ($s['endDate'] ?? null), $tz) ?? $start;
			$valRaw = $s['value'] ?? '';
			if ($key === null || $start === null || $end < $start) {
				$rejected++;
				continue;
			}
			$unit = trim((string)($s['unit'] ?? ''));
			if ($key === 'sleep') {
				$val = self::sleepValue((string)$valRaw);
				if ($val === null) {
					continue; // in bed / unknown stage: not counted as sleep, not an error
				}
			} else {
				$n = self::number($valRaw);
				// "9.420" steps in it/de locale = 9420 (a fraction of a step or floor does not exist)
				if (in_array($key, ['steps', 'flights'], true) && is_string($valRaw) && preg_match('/^\s*\d{1,3}(\.\d{3})+\s*\D*$/u', $valRaw)) {
					$n = (float)str_replace('.', '', (string)preg_replace('/[^\d.]/', '', $valRaw));
				}
				if ($n === null) {
					$rejected++;
					continue;
				}
				if ($unit === '' && is_string($valRaw)) {
					$unit = self::unitOf($valRaw);
				}
				$val = self::scale($key, $n, $unit);
				$unit = self::canonicalUnit($key, $unit);
			}
			$samples[] = ['type' => $key, 'hk' => self::TYPES[$key], 'value' => (string)$val, 'unit' => $unit, 'start' => $start, 'end' => max($start, $end),
				'source' => mb_substr(trim((string)($s['source'] ?? '')), 0, 64) ?: 'Shortcuts'];
		}
		return ['samples' => $samples, 'workouts' => self::workouts($p, $tz), 'rejected' => $rejected];
	}

	/** percentages as fractions, distances and energy as the units Aggregator::normalize understands */
	private static function scale(string $key, float $n, string $unit): float {
		$u = mb_strtolower($unit);
		if (in_array($key, ['spo2', 'body_fat'], true)) {
			return $n > 1 ? $n / 100 : $n;
		}
		return $n;
	}

	private static function canonicalUnit(string $key, string $unit): string {
		$u = mb_strtolower(trim($unit));
		return match ($key) {
			'distance', 'cycling' => match (true) { $u === 'm' || str_starts_with($u, 'met') => 'm', str_starts_with($u, 'mi') => 'mi', default => 'km' },
			'active_energy' => str_contains($u, 'kj') ? 'kJ' : 'kcal',
			'weight' => str_contains($u, 'lb') ? 'lb' : 'kg',
			'spo2', 'body_fat' => '%',
			default => $unit,
		};
	}

	private const ACTIVITY = ['cycling' => 'Cycling', 'ciclismo' => 'Cycling', 'radfahren' => 'Cycling', 'bici' => 'Cycling', 'running' => 'Running', 'corsa' => 'Running',
		'laufen' => 'Running', 'walking' => 'Walking', 'camminata' => 'Walking', 'gehen' => 'Walking', 'hiking' => 'Hiking', 'escursion' => 'Hiking', 'wandern' => 'Hiking',
		'swimming' => 'Swimming', 'nuoto' => 'Swimming', 'schwimmen' => 'Swimming', 'yoga' => 'Yoga', 'strength' => 'TraditionalStrengthTraining', 'forza' => 'TraditionalStrengthTraining',
		'kraft' => 'TraditionalStrengthTraining'];

	public static function activity(string $t): string {
		$t = (string)preg_replace('/^HKWorkoutActivityType/', '', trim($t));
		$l = mb_strtolower($t);
		foreach (self::ACTIVITY as $k => $v) {
			if (str_contains($l, $k)) {
				return $v;
			}
		}
		return $t === '' ? 'Other' : mb_substr((string)preg_replace('/[^\pL\pN]+/u', '', ucwords($t)), 0, 60);
	}

	private static function workouts(array $p, \DateTimeZone $tz): array {
		$list = is_array($p['workouts'] ?? null) ? $p['workouts'] : [];
		if (isset($p['workout_starts'])) {
			$starts = self::lines($p['workout_starts']);
			$ends = self::lines($p['workout_ends'] ?? '');
			$types = self::lines($p['workout_types'] ?? ($p['workout_values'] ?? ''));
			$durs = self::lines($p['workout_durations'] ?? '');
			$srcs = self::lines($p['workout_sources'] ?? '');
			foreach ($starts as $i => $s) {
				$list[] = ['type' => $types[$i] ?? '', 'start' => $s, 'end' => $ends[$i] ?? '', 'duration' => $durs[$i] ?? '', 'source' => $srcs[$i] ?? ''];
			}
		}
		$out = [];
		foreach ($list as $w) {
			if (!is_array($w)) {
				continue;
			}
			$s = self::date($w['start'] ?? null, $tz);
			$e = self::date($w['end'] ?? null, $tz);
			$dur = self::number($w['duration'] ?? '');
			if ($s === null) {
				continue;
			}
			if ($e === null && $dur !== null) {
				$e = $s + (int)round($dur * 60);
			}
			if ($e === null || $e <= $s || $e - $s > 24 * 3600) {
				continue;
			}
			$dist = self::number($w['distance'] ?? '');
			$kcal = self::number($w['energy'] ?? '');
			$out[] = ['activity' => self::activity((string)($w['type'] ?? '')), 'source' => mb_substr(trim((string)($w['source'] ?? '')), 0, 64) ?: 'Shortcuts',
				'start_ts' => $s, 'end_ts' => $e, 'duration_min' => round(($e - $s) / 60, 1),
				'distance_m' => $dist === null ? null : round($dist < 500 ? $dist * 1000 : $dist), 'kcal' => $kcal === null ? null : round($kcal), 'hr_avg' => null];
		}
		return $out;
	}

	/** Apple export style timestamp string for Aggregator::record */
	public static function appleDate(int $ts, \DateTimeZone $tz): string {
		return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s O');
	}

	/** stable hash of one sample (idempotent upsert) */
	public static function hash(array $s): string {
		return sha1($s['type'] . '|' . $s['start'] . '|' . $s['end'] . '|' . $s['value'] . '|' . $s['source']);
	}

	/** token: 48 random chars; only the sha256 is stored */
	public static function tokenHash(string $token): string {
		return hash('sha256', 'hc-ingest|' . $token);
	}
}
