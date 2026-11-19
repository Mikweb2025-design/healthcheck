<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * iCalendar objects for the "Sport & Health" calendar. Deterministic output (DTSTAMP = start of the workout), so an
 * unchanged workout produces byte-identical data and a re-import does not touch the calendar at all.
 */
final class Ics {
	public const PRODID = '-//Health Check//Nextcloud app//EN';

	public static function esc(string $s): string {
		return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\\;', '\\,', '\\n', '\\n'], $s);
	}

	/** folds lines at 75 octets without cutting UTF-8 characters */
	public static function fold(string $line): string {
		if (strlen($line) <= 75) {
			return $line;
		}
		$out = [];
		$cur = '';
		foreach (mb_str_split($line) as $ch) {
			$limit = $out === [] ? 75 : 74;
			if (strlen($cur) + strlen($ch) > $limit) {
				$out[] = $cur;
				$cur = '';
			}
			$cur .= $ch;
		}
		$out[] = $cur;
		return implode("\r\n ", $out);
	}

	private static function utc(int $ts): string {
		return gmdate('Ymd\THis\Z', $ts);
	}

	private static function wrap(array $lines): string {
		$all = array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:' . self::PRODID, 'CALSCALE:GREGORIAN'], $lines, ['END:VCALENDAR']);
		return implode("\r\n", array_map([self::class, 'fold'], $all)) . "\r\n";
	}

	public static function workoutUri(array $w): string {
		return 'hc-w-' . $w['wkey'] . '.ics';
	}

	public static function workoutTitle(array $w, string $lang): string {
		[$emoji, $name] = Messages::activity((string)$w['activity'], $lang);
		$parts = [$emoji . ' ' . $name];
		if (($w['distance_m'] ?? null) !== null && (float)$w['distance_m'] >= 100) {
			$parts[] = Messages::num((float)$w['distance_m'] / 1000, 1, $lang) . ' km';
		}
		$parts[] = Messages::dur((float)$w['duration_min']);
		return implode(' · ', $parts);
	}

	public static function workout(array $w, string $lang): string {
		$desc = [Messages::t('c_duration', $lang) . ': ' . Messages::dur((float)$w['duration_min'])];
		if (($w['distance_m'] ?? null) !== null) {
			$desc[] = Messages::t('c_distance', $lang) . ': ' . Messages::num((float)$w['distance_m'] / 1000, 2, $lang) . ' km';
		}
		if (($w['kcal'] ?? null) !== null) {
			$desc[] = Messages::t('c_energy', $lang) . ': ' . (int)$w['kcal'] . ' kcal';
		}
		if (($w['hr_avg'] ?? null) !== null) {
			$desc[] = Messages::t('c_hr', $lang) . ': ' . (int)round((float)$w['hr_avg']) . ' bpm';
		}
		$desc[] = Messages::t('c_source', $lang) . ': ' . $w['source'];
		$desc[] = '';
		$desc[] = Messages::t('c_auto', $lang);
		$start = (int)$w['start_ts'];
		$end = max($start + 60, (int)$w['end_ts']);
		return self::wrap([
			'BEGIN:VEVENT',
			'UID:hc-w-' . $w['wkey'] . '@healthcheck',
			'DTSTAMP:' . self::utc($start),
			'DTSTART:' . self::utc($start),
			'DTEND:' . self::utc($end),
			'SUMMARY:' . self::esc(self::workoutTitle($w, $lang)),
			'DESCRIPTION:' . self::esc(implode("\n", $desc)),
			'CATEGORIES:' . self::esc('Sport'),
			'TRANSP:TRANSPARENT',
			'CLASS:PRIVATE',
			'END:VEVENT',
		]);
	}

	public static function dayUri(string $day): string {
		return 'hc-d-' . $day . '.ics';
	}

	/** "💤 7 h 12 · ❤️ 54 · 👣 9.420" or null when the day has nothing to show */
	public static function dayTitle(array $row, string $lang): ?string {
		$p = [];
		if (($row['sleep_total'] ?? null) !== null) {
			$m = (int)$row['sleep_total'];
			$p[] = '💤 ' . intdiv($m, 60) . ' h ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT);
		}
		if (($row['resting_hr'] ?? null) !== null) {
			$p[] = '❤️ ' . (int)round((float)$row['resting_hr']);
		}
		if (($row['steps'] ?? null) !== null) {
			$p[] = '👣 ' . Messages::num((float)$row['steps'], 0, $lang);
		}
		return $p === [] ? null : implode(' · ', $p);
	}

	public static function day(array $row, string $lang): ?string {
		$title = self::dayTitle($row, $lang);
		if ($title === null) {
			return null;
		}
		$d = str_replace('-', '', (string)$row['day']);
		$next = str_replace('-', '', Insights::addDays((string)$row['day'], 1));
		return self::wrap([
			'BEGIN:VEVENT',
			'UID:hc-d-' . $row['day'] . '@healthcheck',
			'DTSTAMP:' . $d . 'T000000Z',
			'DTSTART;VALUE=DATE:' . $d,
			'DTEND;VALUE=DATE:' . $next,
			'SUMMARY:' . self::esc($title),
			'DESCRIPTION:' . self::esc(Messages::t('c_auto', $lang)),
			'TRANSP:TRANSPARENT',
			'CLASS:PRIVATE',
			'END:VEVENT',
		]);
	}
}
