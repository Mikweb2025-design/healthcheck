<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/** Nextcloud notifications; raw parameters, rendered by the Notifier in the reader's language. Never throws. */
class Notify {
	public function __construct(
		private IManager $manager,
		private ITimeFactory $time,
		private IURLGenerator $urls,
		private LoggerInterface $logger,
	) {
	}

	public function send(string $uid, string $subject, array $params, string $object, string $hash = ''): void {
		try {
			$this->dismiss($uid, $object);
			$n = $this->manager->createNotification();
			$n->setApp(Application::APP_ID)->setUser($uid)
				->setDateTime((new \DateTime())->setTimestamp($this->time->getTime()))
				->setObject('health', $object)
				->setSubject($subject, array_map('strval', $params))
				->setLink($this->urls->linkToRouteAbsolute('healthcheck.page.index') . $hash);
			$this->manager->notify($n);
		} catch (\Throwable $e) {
			$this->logger->warning('Health Check notification failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	public function dismiss(string $uid, string $object): void {
		try {
			$n = $this->manager->createNotification();
			$n->setApp(Application::APP_ID)->setUser($uid)->setObject('health', $object);
			$this->manager->markProcessed($n);
		} catch (\Throwable) {
		}
	}
}
