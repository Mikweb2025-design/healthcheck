<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\Agent;
use OCA\HealthCheck\Domain\Messages;
use OCA\HealthCheck\Domain\Signature;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Security\ISecureRandom;

/** Per-user settings (user values of this app) and the few app-wide values (AI endpoint for the chat). */
class Settings {
	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
	) {
	}

	public function get(string $uid, string $key, string $default = ''): string {
		return (string)$this->config->getUserValue($uid, Application::APP_ID, $key, $default);
	}

	public function set(string $uid, string $key, string $value): void {
		$this->config->setUserValue($uid, Application::APP_ID, $key, $value);
	}

	public function del(string $uid, string $key): void {
		$this->config->deleteUserValue($uid, Application::APP_ID, $key);
	}

	/** marks a user as using the app (the background job only looks at these users) */
	public function activate(string $uid): void {
		if ($this->get($uid, 'active') !== '1') {
			$this->set($uid, 'active', '1');
		}
	}

	/** @return list<string> */
	public function activeUsers(): array {
		return array_values(array_map('strval', $this->config->getUsersForUserValue(Application::APP_ID, 'active', '1')));
	}

	public function lang(string $uid): string {
		$own = $this->get($uid, 'lang');
		if (in_array($own, Messages::LANGS, true)) {
			return $own;
		}
		return Messages::lang((string)$this->config->getUserValue($uid, 'core', 'lang', (string)$this->config->getSystemValue('default_language', 'en')));
	}

	public function tz(string $uid): string {
		$tz = (string)$this->config->getUserValue($uid, 'core', 'timezone', '');
		try {
			new \DateTimeZone($tz);
			return $tz === '' ? 'Europe/Berlin' : $tz;
		} catch (\Throwable) {
			return 'Europe/Berlin';
		}
	}

	public function goals(string $uid): array {
		$j = json_decode($this->get($uid, 'goals', '{}'), true);
		return Agent::goals(is_array($j) ? $j : []);
	}

	/** settings as shown to the user (the secret only as "set" flag) */
	public function forUser(string $uid): array {
		return [
			'lang' => $this->get($uid, 'lang'),
			'effectiveLang' => $this->lang($uid),
			'calendarSync' => $this->get($uid, 'calendar_sync', '1') === '1',
			'calendarDaily' => $this->get($uid, 'calendar_daily', '0') === '1',
			'webhookUrl' => $this->get($uid, 'webhook_url'),
			'webhookSecretSet' => $this->get($uid, 'webhook_secret') !== '',
			'notifyImport' => $this->get($uid, 'notify_import', '1') === '1',
			'nudges' => $this->get($uid, 'nudges', '1') === '1',
			'goals' => $this->goals($uid),
			'chatAvailable' => $this->aiConfigured(),
			'timezone' => $this->tz($uid),
		];
	}

	/** @return list<string> invalid fields */
	public function update(string $uid, array $in): array {
		$bad = [];
		if (array_key_exists('lang', $in)) {
			$l = (string)$in['lang'];
			in_array($l, ['', 'de', 'en', 'it'], true) ? $this->set($uid, 'lang', $l) : $bad[] = 'lang';
		}
		foreach (['calendarSync' => 'calendar_sync', 'calendarDaily' => 'calendar_daily', 'notifyImport' => 'notify_import', 'nudges' => 'nudges'] as $k => $key) {
			if (array_key_exists($k, $in)) {
				$this->set($uid, $key, filter_var($in[$k], FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
			}
		}
		if (array_key_exists('webhookUrl', $in)) {
			$u = trim((string)$in['webhookUrl']);
			Signature::validUrl($u) ? $this->set($uid, 'webhook_url', $u) : $bad[] = 'webhookUrl';
		}
		if (array_key_exists('webhookSecret', $in) && (string)$in['webhookSecret'] !== '') {
			$s = (string)$in['webhookSecret'];
			strlen($s) >= 16 && strlen($s) <= 200 ? $this->set($uid, 'webhook_secret', $s) : $bad[] = 'webhookSecret';
		}
		if (array_key_exists('goals', $in) && is_array($in['goals'])) {
			$this->set($uid, 'goals', (string)json_encode(Agent::goals($in['goals'])));
		}
		return $bad;
	}

	/** creates a new random secret and returns it once */
	public function newSecret(string $uid): string {
		$s = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->set($uid, 'webhook_secret', $s);
		return $s;
	}

	public function aiConfigured(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, 'ai_api_key', '') !== '';
	}

	/** @return array{url:string,model:string,key:string} */
	public function ai(): array {
		return [
			'url' => $this->appConfig->getValueString(Application::APP_ID, 'ai_url', 'https://openai.inference.de-txl.ionos.com/v1/chat/completions'),
			'model' => $this->appConfig->getValueString(Application::APP_ID, 'ai_model', 'openai/gpt-oss-120b'),
			'key' => $this->appConfig->getValueString(Application::APP_ID, 'ai_api_key', ''),
		];
	}
}
