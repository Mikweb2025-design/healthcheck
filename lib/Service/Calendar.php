<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\Ics;
use OCA\HealthCheck\Domain\Insights;
use OCA\HealthCheck\Domain\Messages;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Personal calendar "Sport & Health" of the user (not shared), on top of the DAV app's CalDavBackend.
 * One event per workout (stable object URI from source + start), optional all-day summary per day.
 * Unchanged events are not rewritten (same ICS = same etag), events of vanished workouts are removed.
 */
class Calendar {
	private const BACKEND = 'OCA\\DAV\\CalDAV\\CalDavBackend';
	public const URI = 'healthcheck-sport';
	public const DAILY_DAYS = 365;

	public function __construct(private ContainerInterface $container, private LoggerInterface $logger) {
	}

	private function backend(): object {
		return $this->container->get(self::BACKEND);
	}

	private static function principal(string $uid): string {
		return 'principals/users/' . $uid;
	}

	private function find(string $uid): ?array {
		foreach ($this->backend()->getCalendarsForUser(self::principal($uid)) as $c) {
			if (($c['uri'] ?? '') === self::URI && ($c['principaluri'] ?? '') === self::principal($uid)) {
				return $c;
			}
		}
		return null;
	}

	private function ensure(string $uid, string $lang): array {
		$c = $this->find($uid);
		if ($c !== null) {
			return $c;
		}
		$this->backend()->createCalendar(self::principal($uid), self::URI, [
			'{DAV:}displayname' => Messages::t('calendar', $lang),
			'{http://apple.com/ns/ical/}calendar-color' => '#E5484D',
		]);
		$c = $this->find($uid);
		if ($c === null) {
			throw new \RuntimeException('calendar_not_created');
		}
		return $c;
	}

	/** @return array{created:int,updated:int,unchanged:int,deleted:int,calendar:string} */
	public function sync(string $uid, array $workouts, array $days, bool $daily, string $lang): array {
		$stat = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0, 'calendar' => self::URI];
		$cal = $this->ensure($uid, $lang);
		$b = $this->backend();
		$id = (int)$cal['id'];
		$existing = [];
		foreach ($b->getCalendarObjects($id) as $o) {
			$existing[(string)$o['uri']] = trim((string)($o['etag'] ?? ''), '"');
		}
		$want = [];
		foreach ($workouts as $w) {
			$want[Ics::workoutUri($w)] = Ics::workout($w, $lang);
		}
		if ($daily && $days !== []) {
			$last = (string)$days[count($days) - 1]['day'];
			$from = Insights::addDays($last, -(self::DAILY_DAYS - 1));
			foreach ($days as $r) {
				if ($r['day'] >= $from && ($ics = Ics::day($r, $lang)) !== null) {
					$want[Ics::dayUri($r['day'])] = $ics;
				}
			}
		}
		foreach ($want as $uri => $ics) {
			if (!isset($existing[$uri])) {
				$b->createCalendarObject($id, $uri, $ics);
				$stat['created']++;
			} elseif ($existing[$uri] !== md5($ics)) {
				$b->updateCalendarObject($id, $uri, $ics);
				$stat['updated']++;
			} else {
				$stat['unchanged']++;
			}
		}
		foreach ($existing as $uri => $_) {
			if (!isset($want[$uri]) && (str_starts_with($uri, 'hc-w-') || str_starts_with($uri, 'hc-d-'))) {
				$b->deleteCalendarObject($id, $uri);
				$stat['deleted']++;
			}
		}
		return $stat;
	}

	/** removes the whole calendar of this app (to the trash bin of the calendar app) */
	public function remove(string $uid): bool {
		$c = $this->find($uid);
		if ($c === null) {
			return false;
		}
		$this->backend()->deleteCalendar((int)$c['id'], true);
		return true;
	}

	public function info(string $uid): ?array {
		try {
			$c = $this->find($uid);
			if ($c === null) {
				return null;
			}
			return ['uri' => self::URI, 'name' => (string)($c['{DAV:}displayname'] ?? ''), 'events' => count($this->backend()->getCalendarObjects((int)$c['id']))];
		} catch (\Throwable $e) {
			$this->logger->debug('Health Check calendar info: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return null;
		}
	}
}
