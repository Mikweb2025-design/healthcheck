<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Collects single Apple Health records into one row per day. Pure (no Nextcloud), fed by HealthParser.
 *
 * - Every metric is first kept per day AND per source; finish() then takes, per day and metric, the most preferred
 *   source that has data (Sources::priority). That avoids double counting watch + phone steps.
 * - Sleep: a segment belongs to the night that ends on the wake-up day (segment start + 6 h, local time), overlapping
 *   segments of one stage are merged, only one source per night is used.
 * - Workouts: duplicates (same activity, start within 2 minutes) from several sources are collapsed.
 */
final class Aggregator {
	/** HealthKit type => [column, mode]  mode: sum|avg|last */
	public const QUANTITY = [
		'HKQuantityTypeIdentifierStepCount' => ['steps', 'sum'],
		'HKQuantityTypeIdentifierDistanceWalkingRunning' => ['distance_m', 'sum'],
		'HKQuantityTypeIdentifierDistanceCycling' => ['cycling_m', 'sum'],
		'HKQuantityTypeIdentifierFlightsClimbed' => ['flights', 'sum'],
		'HKQuantityTypeIdentifierActiveEnergyBurned' => ['active_kcal', 'sum'],
		'HKQuantityTypeIdentifierRestingHeartRate' => ['resting_hr', 'avg'],
		'HKQuantityTypeIdentifierOxygenSaturation' => ['spo2', 'avg'],
		'HKQuantityTypeIdentifierHeartRateVariabilitySDNN' => ['hrv', 'avg'],
		'HKQuantityTypeIdentifierVO2Max' => ['vo2max', 'last'],
		'HKQuantityTypeIdentifierBodyMass' => ['weight', 'last'],
		'HKQuantityTypeIdentifierBodyMassIndex' => ['bmi', 'last'],
		'HKQuantityTypeIdentifierBodyFatPercentage' => ['bodyfat', 'last'],
		'HKQuantityTypeIdentifierHeartRate' => ['hr', 'hr'],
	];
	public const SLEEP = 'HKCategoryTypeIdentifierSleepAnalysis';
	private const STAGE = [
		'HKCategoryValueSleepAnalysisAsleepCore' => 'core',
		'HKCategoryValueSleepAnalysisAsleepDeep' => 'deep',
		'HKCategoryValueSleepAnalysisAsleepREM' => 'rem',
		'HKCategoryValueSleepAnalysisAsleepUnspecified' => 'asleep',
		'HKCategoryValueSleepAnalysisAsleep' => 'asleep',
		'HKCategoryValueSleepAnalysisAwake' => 'awake',
	];
	/** columns of a finished day row (besides day) */
	public const COLUMNS = ['steps', 'distance_m', 'cycling_m', 'flights', 'active_kcal', 'hr_min', 'hr_avg', 'hr_max', 'resting_hr',
		'spo2_avg', 'spo2_min', 'hrv', 'vo2max', 'weight', 'bmi', 'bodyfat', 'sleep_total', 'sleep_deep', 'sleep_rem', 'sleep_core',
		'sleep_awake', 'sleep_start', 'sleep_end', 'src'];

	private \DateTimeZone $tz;
	/** @var array<string,string> "YYYY-MM-DD HH|+0100" => local day */
	private array $dayCache = [];
	/** @var array<string,array<string,array<string,array>>> day => col => source => acc */
	private array $q = [];
	/** @var array<string,array<string,array<string,list<int>>>> day => source => stage => flat [s,e,s,e,...] */
	private array $sleep = [];
	/** @var list<array> */
	private array $workouts = [];
	public int $records = 0;
	public int $used = 0;
	public ?string $exportDate = null;

	public function __construct(string $timezone = 'Europe/Berlin') {
		try {
			$this->tz = new \DateTimeZone($timezone);
		} catch (\Throwable) {
			$this->tz = new \DateTimeZone('Europe/Berlin');
		}
	}

	/** "2026-11-18 23:41:00 +0100" => unix seconds (null when unparsable) */
	public static function ts(string $s): ?int {
		$d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s O', $s);
		return $d === false ? null : $d->getTimestamp();
	}

	/** local calendar day of an Apple timestamp string; cached per hour+offset (millions of records) */
	public function day(string $s): ?string {
		if (strlen($s) < 25) {
			return null;
		}
		$key = substr($s, 0, 13) . substr($s, 19);
		if (isset($this->dayCache[$key])) {
			return $this->dayCache[$key];
		}
		$d = \DateTimeImmutable::createFromFormat('Y-m-d H O', substr($s, 0, 13) . substr($s, 19));
		if ($d === false) {
			return null;
		}
		return $this->dayCache[$key] = $d->setTimezone($this->tz)->format('Y-m-d');
	}

	public function dayOfTs(int $ts): string {
		return (new \DateTimeImmutable('@' . $ts))->setTimezone($this->tz)->format('Y-m-d');
	}

	public static function wanted(string $type): bool {
		return isset(self::QUANTITY[$type]) || $type === self::SLEEP;
	}

	/** one <Record>: attributes as strings */
	public function record(string $type, string $source, string $unit, string $start, string $end, string $value): void {
		$this->records++;
		if ($type === self::SLEEP) {
			$this->sleepRecord($source, $start, $end, $value);
			return;
		}
		if (!isset(self::QUANTITY[$type]) || !is_numeric($value)) {
			return;
		}
		$day = $this->day($start);
		if ($day === null) {
			return;
		}
		[$col, $mode] = self::QUANTITY[$type];
		$v = self::normalize($col, (float)$value, $unit);
		if ($v === null) {
			return;
		}
		$this->used++;
		$a = &$this->q[$day][$col][$source];
		if ($a === null) {
			$a = match ($mode) {
				'sum' => [0.0],
				'avg' => [0.0, 0, INF],
				'last' => ['', 0.0],
				default => [INF, -INF, 0.0, 0],
			};
		}
		switch ($mode) {
			case 'sum':
				$a[0] += $v;
				break;
			case 'avg':
				$a[0] += $v;
				$a[1]++;
				$a[2] = min($a[2], $v);
				break;
			case 'last':
				if ($start >= $a[0]) {
					$a = [$start, $v];
				}
				break;
			default: // heart rate
				$a[0] = min($a[0], $v);
				$a[1] = max($a[1], $v);
				$a[2] += $v;
				$a[3]++;
		}
		unset($a);
	}

	/** units to the stored unit: metres, kcal, kg, fraction 0..1 */
	public static function normalize(string $col, float $v, string $unit): ?float {
		$u = strtolower($unit);
		switch ($col) {
			case 'distance_m':
			case 'cycling_m':
				return match ($u) {
					'km' => $v * 1000, 'm' => $v, 'mi' => $v * 1609.344, 'cm' => $v / 100, 'ft' => $v * 0.3048, default => $v * 1000,
				};
			case 'active_kcal':
				return $u === 'kj' ? $v / 4.184 : ($u === 'cal' ? $v / 1000 : $v);
			case 'weight':
				return $u === 'lb' ? $v * 0.45359237 : ($u === 'g' ? $v / 1000 : $v);
			case 'spo2':
			case 'bodyfat':
				$f = $v > 1.0 ? $v / 100 : $v;
				return ($f <= 0 || $f > 1) ? null : $f;
			case 'hr':
			case 'resting_hr':
				return ($v < 20 || $v > 250) ? null : $v;
			default:
				return $v;
		}
	}

	private function sleepRecord(string $source, string $start, string $end, string $value): void {
		$stage = self::STAGE[$value] ?? null;
		if ($stage === null) {
			return; // InBed and unknown values are ignored
		}
		$s = self::ts($start);
		$e = self::ts($end);
		if ($s === null || $e === null || $e <= $s || $e - $s > 24 * 3600) {
			return;
		}
		$this->used++;
		$night = $this->dayOfTs($s + 6 * 3600);
		$this->sleep[$night][$source][$stage][] = $s;
		$this->sleep[$night][$source][$stage][] = $e;
	}

	/**
	 * @param array{activity:string,source:string,start:string,end:string,duration_min:?float,distance_m:?float,kcal:?float,hr_avg:?float} $w
	 */
	public function workout(array $w): void {
		$s = self::ts($w['start']);
		$e = self::ts($w['end']);
		if ($s === null || $e === null) {
			return;
		}
		$this->workouts[] = [
			'activity' => $w['activity'],
			'source' => $w['source'],
			'start_ts' => $s,
			'end_ts' => max($s, $e),
			'duration_min' => $w['duration_min'] ?? round(($e - $s) / 60, 1),
			'distance_m' => $w['distance_m'],
			'kcal' => $w['kcal'],
			'hr_avg' => $w['hr_avg'],
		];
	}

	/** merged length in seconds of flat [s,e,s,e...] intervals, plus earliest start / latest end */
	public static function union(array $flat): array {
		$iv = [];
		for ($i = 0, $n = count($flat); $i + 1 < $n; $i += 2) {
			$iv[] = [$flat[$i], $flat[$i + 1]];
		}
		if ($iv === []) {
			return [0, null, null];
		}
		usort($iv, fn ($a, $b) => $a[0] <=> $b[0]);
		$total = 0;
		[$cs, $ce] = $iv[0];
		$first = $cs;
		$last = $ce;
		foreach ($iv as [$s, $e]) {
			if ($s > $ce) {
				$total += $ce - $cs;
				[$cs, $ce] = [$s, $e];
			} else {
				$ce = max($ce, $e);
			}
			$last = max($last, $e);
		}
		$total += $ce - $cs;
		return [$total, $first, $last];
	}

	private static function best(array $bySource): ?string {
		$best = null;
		$bp = PHP_INT_MAX;
		foreach ($bySource as $src => $_) {
			$p = Sources::priority((string)$src);
			if ($p < $bp || ($p === $bp && strcmp((string)$src, (string)$best) < 0)) {
				$bp = $p;
				$best = (string)$src;
			}
		}
		return $best;
	}

	/** @return array<string,array<string,mixed>> day => row (all COLUMNS, null when missing), sorted by day */
	public function days(): array {
		$out = [];
		foreach ($this->q as $day => $cols) {
			$row = array_fill_keys(self::COLUMNS, null);
			$srcCount = [];
			foreach ($cols as $col => $bySource) {
				$src = self::best($bySource);
				$a = $bySource[$src];
				$srcCount[$src] = ($srcCount[$src] ?? 0) + 1;
				$mode = self::modeOf($col);
				if ($mode === 'sum') {
					$row[$col] = (int)round($a[0]);
				} elseif ($mode === 'avg') {
					if ($col === 'spo2') {
						$row['spo2_avg'] = round($a[0] / $a[1], 4);
						$row['spo2_min'] = round($a[2], 4);
					} else {
						$row[$col] = round($a[0] / $a[1], 1);
					}
				} elseif ($mode === 'last') {
					$row[$col] = round($a[1], $col === 'bodyfat' ? 4 : 2);
				} else {
					$row['hr_min'] = (int)round($a[0]);
					$row['hr_max'] = (int)round($a[1]);
					$row['hr_avg'] = (int)round($a[2] / $a[3]);
				}
			}
			arsort($srcCount);
			$row['src'] = (string)array_key_first($srcCount);
			$out[$day] = $row;
		}
		foreach ($this->sleep as $night => $bySource) {
			// prefer the best source that has real sleep (not only awake segments)
			$cands = array_filter($bySource, fn ($st) => count(array_diff(array_keys($st), ['awake'])) > 0);
			if ($cands === []) {
				continue;
			}
			$st = $bySource[self::best($cands)];
			$asleep = array_merge($st['core'] ?? [], $st['deep'] ?? [], $st['rem'] ?? [], $st['asleep'] ?? []);
			[$total, $first, $last] = self::union($asleep);
			if ($total < 30 * 60) {
				continue; // naps and fragments are not a night
			}
			$row = $out[$night] ?? array_fill_keys(self::COLUMNS, null);
			$row['sleep_total'] = intdiv($total, 60);
			$row['sleep_deep'] = isset($st['deep']) ? intdiv(self::union($st['deep'])[0], 60) : null;
			$row['sleep_rem'] = isset($st['rem']) ? intdiv(self::union($st['rem'])[0], 60) : null;
			$row['sleep_core'] = isset($st['core']) ? intdiv(self::union($st['core'])[0], 60) : null;
			$row['sleep_awake'] = isset($st['awake']) ? intdiv(self::union($st['awake'])[0], 60) : 0;
			$row['sleep_start'] = $first;
			$row['sleep_end'] = $last;
			if ($row['src'] === null) {
				$row['src'] = (string)self::best($cands);
			}
			$out[$night] = $row;
		}
		ksort($out);
		return $out;
	}

	private static function modeOf(string $col): string {
		foreach (self::QUANTITY as [$c, $m]) {
			if ($c === $col) {
				return $m;
			}
		}
		return 'sum';
	}

	/** @return list<array> workouts sorted by start, duplicates of several sources collapsed; key = stable id */
	public function workouts(): array {
		$ws = $this->workouts;
		usort($ws, fn ($a, $b) => [$a['start_ts'], Sources::priority($a['source'])] <=> [$b['start_ts'], Sources::priority($b['source'])]);
		$out = [];
		foreach ($ws as $w) {
			$dup = false;
			foreach (array_slice($out, -4, 4, true) as $i => $o) {
				if ($o['activity'] === $w['activity'] && abs($o['start_ts'] - $w['start_ts']) <= 120) {
					$dup = true;
					if (Sources::priority($w['source']) < Sources::priority($o['source'])) {
						$out[$i] = $w;
					}
				}
			}
			if (!$dup) {
				$out[] = $w;
			}
		}
		foreach ($out as &$w) {
			$w['wkey'] = sha1($w['source'] . '|' . $w['start_ts'] . '|' . $w['activity']);
			$w['day'] = $this->dayOfTs($w['start_ts']);
		}
		unset($w);
		return array_values($out);
	}
}
