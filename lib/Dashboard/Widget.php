<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Dashboard;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\Insights;
use OCA\HealthCheck\Domain\Messages;
use OCA\HealthCheck\Service\DataService;
use OCA\HealthCheck\Service\Settings;
use OCA\HealthCheck\Service\Store;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IURLGenerator;

/** Dashboard widget: the three rings as items with ring images, plus data status. No own JavaScript. */
class Widget implements IAPIWidgetV2, IButtonWidget, IIconWidget {
	public function __construct(
		private IURLGenerator $urls,
		private Store $store,
		private Settings $settings,
		private DataService $data,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getTitle(): string {
		return 'Health Check';
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-healthcheck';
	}

	public function getIconUrl(): string {
		return $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->urls->linkToRouteAbsolute('healthcheck.page.index');
	}

	public function load(): void {
	}

	public function getWidgetButtons(string $userId): array {
		return [new WidgetButton(WidgetButton::TYPE_MORE, $this->urls->linkToRouteAbsolute('healthcheck.page.index'), Messages::t('w_open', $this->settings->lang($userId)))];
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$lang = $this->settings->lang($userId);
		$link = $this->urls->linkToRouteAbsolute('healthcheck.page.index');
		try {
			$rows = $this->store->days($userId, Insights::addDays(gmdate('Y-m-d'), -400));
			if ($rows === []) {
				return new WidgetItems([], Messages::t('w_empty', $lang));
			}
			$rings = Insights::rings($rows);
			$items = [];
			foreach (['sleep' => 'w_sleep', 'recovery' => 'w_recovery', 'movement' => 'w_movement'] as $k => $label) {
				$s = $rings[$k]['score'];
				$items[] = new WidgetItem(
					Messages::t($label, $lang) . ': ' . ($s === null ? '–' : $s . '/100'),
					$rings[$k]['day'] === null ? '' : Messages::day($rings[$k]['day'], $lang),
					$link,
					$this->urls->linkToRouteAbsolute('healthcheck.page.ring', ['kind' => $k, 'value' => $s === null ? 'na' : (string)$s]),
					$k,
				);
			}
			$st = $this->data->status($userId);
			$last = $st['lastImport']['lastDay'] ?? null;
			$items[] = new WidgetItem(
				$st['stale'] ? Messages::t('w_stale', $lang, ['days' => $st['ageDays']]) : Messages::t('w_import', $lang, ['day' => $last === null ? '–' : Messages::day($last, $lang)]),
				'', $link, $this->urls->linkToRouteAbsolute('healthcheck.page.ring', ['kind' => 'none', 'value' => $st['ageDays'] === null ? 'na' : (string)min(100, (int)$st['ageDays'])]), 'status',
			);
			$w = Insights::warnings($rows);
			if ($w !== []) {
				$items[] = new WidgetItem(Messages::t('w_hint', $lang, ['n' => count($w)]), '', $link . '#hints', $this->getIconUrl(), 'hints');
			}
			return new WidgetItems(array_slice($items, 0, max(1, $limit)), Messages::t('w_empty', $lang));
		} catch (\Throwable) {
			return new WidgetItems([], Messages::t('w_empty', $lang));
		}
	}
}
