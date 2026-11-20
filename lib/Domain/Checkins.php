<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/** Daily check-in (energy 1-5 and a short note) and its neutral link to sleep. Pure functions. */
final class Checkins {
	public const NOTE_MAX = 200;

	/** @return array{day:string,energy:int,note:?string} */
	public static function clean(array $in, string $today): array {
		$day = (string)($in['day'] ?? '') ?: $today;
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day > $today || $day < '2000-01-01') {
			throw new \InvalidArgumentException('day');
		}
		$e = $in['energy'] ?? null;
		if (!is_numeric($e) || (int)$e < 1 || (int)$e > 5 || (float)$e != (int)$e) {
			throw new \InvalidArgumentException('energy');
		}
		$note = trim((string)preg_replace('/[\x00-\x1f]+/u', ' ', (string)($in['note'] ?? '')));
		$note = mb_substr($note, 0, self::NOTE_MAX);
		return ['day' => $day, 'energy' => (int)$e, 'note' => $note === '' ? null : $note];
	}

	/**
	 * Average energy on days after enough sleep versus after short sleep (sleep of the night is stored on the wake-up day).
	 * Needs at least 10 check-ins with sleep data and 4 in each group, else null. Not a diagnosis, only a pattern in the person's own data.
	 *
	 * @param list<array{day:string,sleep_total:?float}> $days
	 * @param list<array{day:string,energy:int}> $checkins
	 * @return array{n:int,enough:float,short:float,diff:float,threshold_min:int}|null
	 */
	public static function sleepLink(array $days, array $checkins, int $thresholdMin = 420): ?array {
		$sleep = [];
		foreach ($days as $d) {
			if (isset($d['sleep_total']) && $d['sleep_total'] !== null && (float)$d['sleep_total'] > 0) {
				$sleep[(string)$d['day']] = (float)$d['sleep_total'];
			}
		}
		$hi = [];
		$lo = [];
		foreach ($checkins as $c) {
			if (!isset($sleep[$c['day']])) {
				continue;
			}
			$sleep[$c['day']] >= $thresholdMin ? $hi[] = (int)$c['energy'] : $lo[] = (int)$c['energy'];
		}
		if (count($hi) + count($lo) < 10 || count($hi) < 4 || count($lo) < 4) {
			return null;
		}
		$a = array_sum($hi) / count($hi);
		$b = array_sum($lo) / count($lo);
		return ['n' => count($hi) + count($lo), 'enough' => round($a, 1), 'short' => round($b, 1), 'diff' => round($a - $b, 1), 'threshold_min' => $thresholdMin];
	}

	/** @param list<array{day:string,energy:int}> $checkins */
	public static function average(array $checkins, int $last = 7): ?float {
		$e = array_map(fn ($c) => (int)$c['energy'], array_slice($checkins, -$last));
		return $e === [] ? null : round(array_sum($e) / count($e), 1);
	}
}
