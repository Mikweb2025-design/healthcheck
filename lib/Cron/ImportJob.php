<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Cron;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Service\ImportService;
use OCA\HealthCheck\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Every 15 minutes: import new exports of users of the app, and remind them when their data gets old. */
class ImportJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ImportService $import,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(900);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		foreach ($this->settings->activeUsers() as $uid) {
			try {
				$this->import->run($uid, 'cron');
				$this->import->checkStale($uid);
			} catch (\Throwable $e) {
				$this->logger->warning('Health Check job failed for a user: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			}
		}
	}
}
