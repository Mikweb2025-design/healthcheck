<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\Aggregator;
use OCA\HealthCheck\Domain\Ingest;
use OCA\HealthCheck\Domain\Insights;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Daily push from the phone (Apple Shortcuts) with a personal ingest token. The token can only add health samples for
 * its user; only its sha256 is stored. Samples are kept raw (deduplicated by hash) and the touched days are
 * re-aggregated with the same Aggregator as the zip import.
 */
class IngestService {
	public function __construct(
		private IConfig $config,
		private Settings $settings,
		private Store $store,
		private Calendar $calendar,
		private ImportService $import,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function newToken(string $uid): string {
		$token = 'hc_' . $this->random->generate(45, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->settings->set($uid, 'ingest_token_hash', Ingest::tokenHash($token));
		$this->settings->set($uid, 'ingest_token_created', (string)$this->time->getTime());
		return $token;
	}

	public function revoke(string $uid): void {
		$this->settings->del($uid, 'ingest_token_hash');
		$this->settings->del($uid, 'ingest_token_created');
	}

	/** user of a token, or null */
	public function userOf(string $token): ?string {
		$token = trim($token);
		if (!preg_match('/^hc_[A-Za-z0-9]{45}$/', $token)) {
			return null;
		}
		$users = $this->config->getUsersForUserValue(Application::APP_ID, 'ingest_token_hash', Ingest::tokenHash($token));
		return count($users) === 1 ? (string)$users[0] : null;
	}

	public function info(string $uid): array {
		return [
			'tokenSet' => $this->settings->get($uid, 'ingest_token_hash') !== '',
			'tokenCreated' => (int)$this->settings->get($uid, 'ingest_token_created', '0') ?: null,
			'lastAt' => (int)$this->settings->get($uid, 'ingest_last', '0') ?: null,
			'lastSamples' => (int)$this->settings->get($uid, 'ingest_last_samples', '0'),
			'lastNew' => (int)$this->settings->get($uid, 'ingest_last_new', '0'),
			'lastDay' => $this->settings->get($uid, 'ingest_last_day') ?: null,
		];
	}

	public function ingest(string $uid, array $payload): array {
		$tzName = $this->settings->tz($uid);
		$tz = new \DateTimeZone($tzName);
		$p = Ingest::parse($payload, $tz);
		$now = $this->time->getTime();
		// only plausible times: not in the future, at most 30 days back (older data comes with the zip export)
		$samples = [];
		foreach ($p['samples'] as $s) {
			if ($s['start'] > $now + 3600 || $s['start'] < $now - 30 * 86400) {
				$p['rejected']++;
				continue;
			}
			$s['hash'] = Ingest::hash($s);
			$samples[] = $s;
		}
		$new = $this->store->insertRaw($uid, $samples, $now);
		$days = [];
		$agg = new Aggregator($tzName);
		foreach ($samples as $s) {
			$days[$agg->dayOfTs($s['start'])] = true;
			if ($s['type'] === 'sleep') {
				$days[$agg->dayOfTs($s['start'] + 6 * 3600)] = true;
			}
		}
		$merge = ['inserted' => 0, 'replaced' => 0, 'completed' => 0];
		if ($days !== []) {
			ksort($days);
			$first = array_key_first($days);
			$last = array_key_last($days);
			$from = (new \DateTimeImmutable(Insights::addDays($first, -1) . ' 00:00:00', $tz))->getTimestamp();
			$to = (new \DateTimeImmutable(Insights::addDays($last, 1) . ' 23:59:59', $tz))->getTimestamp();
			foreach ($this->store->raw($uid, $from, $to) as $r) {
				$agg->record((string)$r['type'], (string)$r['source'], (string)$r['unit'], Ingest::appleDate((int)$r['start_ts'], $tz), Ingest::appleDate((int)$r['end_ts'], $tz), (string)$r['value']);
			}
			$rows = array_intersect_key($agg->days(), $days);
			$merge = $this->store->mergeDays($uid, $rows);
		}
		$workouts = [];
		foreach ($p['workouts'] as $w) {
			if ($w['start_ts'] > $now + 3600 || $w['start_ts'] < $now - 30 * 86400) {
				continue;
			}
			$w['wkey'] = sha1($w['source'] . '|' . $w['start_ts'] . '|' . $w['activity']);
			$w['day'] = $agg->dayOfTs($w['start_ts']);
			$workouts[] = $w;
		}
		$added = $this->store->addWorkouts($uid, $workouts);
		$lastDay = $days === [] ? null : array_key_last($days);
		$this->settings->set($uid, 'ingest_last', (string)$now);
		$this->settings->set($uid, 'ingest_last_samples', (string)count($samples));
		$this->settings->set($uid, 'ingest_last_new', (string)$new);
		if ($lastDay !== null) {
			$this->settings->set($uid, 'ingest_last_day', $lastDay);
		}
		$this->settings->activate($uid);
		if ($added !== [] && $this->settings->get($uid, 'calendar_sync', '1') === '1') {
			try {
				$this->calendar->sync($uid, $this->store->workouts($uid), $this->store->days($uid), $this->settings->get($uid, 'calendar_daily', '0') === '1', $this->settings->lang($uid));
			} catch (\Throwable $e) {
				$this->logger->warning('Health Check calendar after ingest: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		if ($days !== [] && $this->settings->get($uid, 'nudges', '1') === '1') {
			$this->import->nudge($uid, $this->store->days($uid, Insights::addDays((string)$lastDay, -120)));
		}
		return ['ok' => true, 'received' => count($p['samples']), 'stored_new' => $new, 'rejected' => $p['rejected'], 'days' => array_keys($days),
			'merge' => $merge, 'workouts_added' => count($added)];
	}
}
