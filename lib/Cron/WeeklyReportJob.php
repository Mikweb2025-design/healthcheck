<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Cron;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\AdminConfig;
use OCA\HealthCheck\Service\DataService;
use OCA\HealthCheck\Service\Notify;
use OCA\HealthCheck\Service\Settings;
use OCA\HealthCheck\Service\Store;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly. Only when the administrator chose "built-in" as weekly report: on Monday morning (user time) each active user
 * gets one AI report from the configured AI, with no automation needed. With the default "n8n" nothing happens here.
 */
class WeeklyReportJob extends TimedJob {
	public function __construct(
		private ITimeFactory $clock,
		private Settings $settings,
		private Store $store,
		private DataService $data,
		private Notify $notify,
		private LoggerInterface $logger,
	) {
		parent::__construct($clock);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if ($this->settings->adminString('report_mode') !== 'builtin' || !$this->settings->aiConfigured() || !$this->settings->adminBool('ai_tasks')) {
			return;
		}
		foreach ($this->settings->activeUsers() as $uid) {
			try {
				$now = $this->clock->getTime();
				$local = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone($this->settings->tz($uid)));
				$last = $this->store->aiReports($uid, 1)[0]['createdAt'] ?? 0;
				$days = $this->store->days($uid, $local->modify('-12 days')->format('Y-m-d'));
				$newest = $days === [] ? null : (string)end($days)['day'];
				if (!AdminConfig::weeklyDue($local, (int)$last, $newest)) {
					continue;
				}
				$res = $this->data->aiTask($uid, 'checkup', ['days' => 30]);
				$rep = AdminConfig::reportFromCheckup($res['result']);
				if ($rep['summary'] === '') {
					continue;
				}
				$this->store->saveAi($uid, $rep + [
					'period_from' => $local->modify('-30 days')->format('Y-m-d'), 'period_to' => $local->format('Y-m-d'),
					'lang' => $this->settings->lang($uid), 'model' => $res['model'], 'doctor_lang' => $this->settings->reportLang($uid), 'plan' => null, 'evaluation' => null,
				], $now);
				$this->notify->send($uid, 'report', ['day' => $local->format('Y-m-d')], 'report', '#ai');
			} catch (\Throwable $e) {
				$this->logger->warning('Health Check weekly report failed for a user: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
	}
}
