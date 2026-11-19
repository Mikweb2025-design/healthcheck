<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\Agent;
use OCA\HealthCheck\Domain\Aggregator;
use OCA\HealthCheck\Domain\HealthParser;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Import of an Apple Health export from /Health/Import: stream-parse, store daily rows, archive the zip, then the
 * follow-ups (calendar, notifications, rule based nudges, webhook to the automation). One import per user at a time.
 */
class ImportService {
	public const STALE_DAYS = 8;

	public function __construct(
		private Files $files,
		private Store $store,
		private Settings $settings,
		private Calendar $calendar,
		private Notify $notify,
		private Outbound $outbound,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function running(string $uid): bool {
		$lock = (int)$this->settings->get($uid, 'import_lock', '0');
		return $lock > $this->time->getTime() - 1800;
	}

	/** @return array{status:string,...} */
	public function run(string $uid, string $trigger = 'cron'): array {
		$this->files->ensure($uid);
		$pending = $this->files->pending($uid);
		if ($pending === []) {
			return ['status' => 'nothing'];
		}
		$file = $pending[0];
		$fingerprint = $file->getId() . ':' . $file->getMTime() . ':' . $file->getSize();
		if ($trigger === 'cron' && $this->settings->get($uid, 'failed') === $fingerprint) {
			return ['status' => 'skipped_failed'];
		}
		if ($this->running($uid)) {
			return ['status' => 'running'];
		}
		$now = $this->time->getTime();
		$this->settings->set($uid, 'import_lock', (string)$now);
		@set_time_limit(0);
		$t0 = microtime(true);
		if (function_exists('memory_reset_peak_usage')) {
			memory_reset_peak_usage();
		}
		$logId = $this->store->importStart($uid, $file->getName(), (int)$file->getId(), (int)$file->getSize(), $trigger, $now);
		try {
			$agg = new Aggregator($this->settings->tz($uid));
			HealthParser::parse($this->files->localPath($file), $agg);
			$days = $agg->days();
			$workouts = $agg->workouts();
			if ($days === []) {
				throw new \RuntimeException('empty_export');
			}
			$this->store->replaceDays($uid, $days);
			$this->store->replaceWorkouts($uid, $workouts);
			$keys = array_keys($days);
			$res = [
				'status' => 'ok', 'days' => count($days), 'workouts' => count($workouts), 'records' => $agg->used,
				'first_day' => (string)$keys[0], 'last_day' => (string)end($keys), 'export_date' => $agg->exportDate,
				'duration_ms' => (int)round((microtime(true) - $t0) * 1000), 'peak_mb' => (int)round(memory_get_peak_usage(true) / 1048576),
			];
			$this->store->importFinish($logId, $res + ['finished_at' => $this->time->getTime()]);
			$this->settings->del($uid, 'failed');
			$this->files->archive($uid, $file);
			$res['calendar'] = $this->afterImport($uid, $res);
			return $res;
		} catch (\Throwable $e) {
			$msg = $e instanceof \RuntimeException && preg_match('/^[a-z_]+$/', $e->getMessage()) ? $e->getMessage() : 'parse_error';
			$this->logger->warning('Health Check import failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			$this->store->importFinish($logId, ['status' => 'error', 'message' => $msg, 'finished_at' => $this->time->getTime(),
				'duration_ms' => (int)round((microtime(true) - $t0) * 1000)]);
			$this->settings->set($uid, 'failed', $fingerprint);
			$this->notify->send($uid, 'error', ['file' => $file->getName(), 'error' => $msg], 'import-error');
			return ['status' => 'error', 'message' => $msg];
		} finally {
			$this->settings->del($uid, 'import_lock');
		}
	}

	/** calendar, notifications, nudges and webhook; each step is optional and may fail on its own */
	private function afterImport(string $uid, array $res): ?array {
		$lang = $this->settings->lang($uid);
		$cal = null;
		$rows = $this->store->days($uid);
		if ($this->settings->get($uid, 'calendar_sync', '1') === '1') {
			try {
				$cal = $this->calendar->sync($uid, $this->store->workouts($uid), $rows, $this->settings->get($uid, 'calendar_daily', '0') === '1', $lang);
			} catch (\Throwable $e) {
				$this->logger->warning('Health Check calendar sync failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
				$cal = ['error' => 'calendar_failed'];
			}
		}
		$this->notify->dismiss($uid, 'stale');
		$this->notify->dismiss($uid, 'import-error');
		if ($this->settings->get($uid, 'notify_import', '1') === '1') {
			$this->notify->send($uid, 'import', ['days' => $res['days'], 'workouts' => $res['workouts'], 'day' => $res['last_day']], 'import');
		}
		if ($this->settings->get($uid, 'nudges', '1') === '1') {
			$this->nudge($uid, $rows);
		}
		$this->outbound->webhook($uid, 'import', ['last_day' => $res['last_day'], 'days' => $res['days']]);
		return $cal;
	}

	/** rule based notifications, each key at most once per data day */
	public function nudge(string $uid, array $rows): array {
		$sent = [];
		$lastDay = $rows === [] ? '' : $rows[count($rows) - 1]['day'];
		foreach (Agent::nudges($rows, $this->settings->goals($uid)) as $n) {
			$mark = 'nudge_' . $n['key'];
			if ($this->settings->get($uid, $mark) === $lastDay) {
				continue;
			}
			$this->settings->set($uid, $mark, $lastDay);
			$this->notify->send($uid, $n['key'], $n['params'] + ['day' => $lastDay], 'nudge-' . $n['key']);
			$sent[] = $n['key'];
		}
		return $sent;
	}

	/** reminder when the newest data is older than STALE_DAYS (once per data day) */
	public function checkStale(string $uid): ?int {
		$last = $this->store->lastOkImport($uid);
		if ($last === null || $last['lastDay'] === null) {
			return null;
		}
		$today = (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new \DateTimeZone($this->settings->tz($uid)))->format('Y-m-d');
		$age = (int)round((strtotime($today . ' 12:00 UTC') - strtotime($last['lastDay'] . ' 12:00 UTC')) / 86400);
		if ($age > self::STALE_DAYS && $this->settings->get($uid, 'stale_notified') !== $last['lastDay']) {
			$this->settings->set($uid, 'stale_notified', $last['lastDay']);
			$this->notify->send($uid, 'stale', ['day' => $last['lastDay'], 'days' => $age], 'stale');
		}
		return $age;
	}

	public static function ageDays(?string $lastDay, string $today): ?int {
		return $lastDay === null ? null : (int)round((strtotime($today . ' 12:00 UTC') - strtotime($lastDay . ' 12:00 UTC')) / 86400);
	}

	public function today(string $uid): string {
		return (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone(new \DateTimeZone($this->settings->tz($uid)))->format('Y-m-d');
	}
}
