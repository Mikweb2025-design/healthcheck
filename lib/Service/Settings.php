<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\AdminConfig;
use OCA\HealthCheck\Domain\Agent;
use OCA\HealthCheck\Domain\Messages;
use OCA\HealthCheck\Domain\Signature;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;

/** Per-user settings (user values of this app) and the app-wide administrator values (AI endpoint, limits, access). */
class Settings {
	public function __construct(
		private IConfig $config,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
		private IGroupManager $groups,
		private IUserManager $users,
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
		$all = array_values(array_map('strval', $this->config->getUsersForUserValue(Application::APP_ID, 'active', '1')));
		return array_values(array_filter($all, fn (string $u) => $this->userAllowed($u)));
	}

	public function lang(string $uid): string {
		$own = $this->get($uid, 'lang');
		if (in_array($own, Messages::LANGS, true)) {
			return $own;
		}
		$core = strtolower(substr((string)$this->config->getUserValue($uid, 'core', 'lang', (string)$this->config->getSystemValue('default_language', '')), 0, 2));
		return in_array($core, Messages::LANGS, true) ? $core : $this->adminString('default_lang');
	}

	/** language of the doctor report (own setting, else the app language) */
	public function reportLang(string $uid): string {
		$own = $this->get($uid, 'report_lang');
		if (in_array($own, Messages::LANGS, true)) {
			return $own;
		}
		$def = $this->adminString('default_report_lang');
		return in_array($def, Messages::LANGS, true) ? $def : $this->lang($uid);
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
			'reportLang' => $this->get($uid, 'report_lang'),
			'effectiveReportLang' => $this->reportLang($uid),
			'calendarSync' => $this->get($uid, 'calendar_sync', '1') === '1',
			'calendarDaily' => $this->get($uid, 'calendar_daily', '0') === '1',
			'webhookUrl' => $this->get($uid, 'webhook_url'),
			'webhookSecretSet' => $this->get($uid, 'webhook_secret') !== '',
			'notifyImport' => $this->get($uid, 'notify_import', '1') === '1',
			'nudges' => $this->get($uid, 'nudges', '1') === '1',
			'goals' => $this->goals($uid),
			'chatAvailable' => $this->aiConfigured() && $this->adminBool('ai_chat'),
			'aiTasksAvailable' => $this->aiConfigured() && $this->adminBool('ai_tasks'),
			'features' => ['ingest' => $this->adminBool('allow_ingest'), 'webhooks' => $this->adminBool('allow_webhooks'), 'reportMode' => $this->adminString('report_mode')],
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
		if (array_key_exists('reportLang', $in)) {
			$l = (string)$in['reportLang'];
			in_array($l, ['', 'de', 'en', 'it'], true) ? $this->set($uid, 'report_lang', $l) : $bad[] = 'reportLang';
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

	// ---- app-wide (administrator) values ------------------------------------------------------------------------------

	/** last push with an unknown token (app-wide timestamp, no token or user stored) */
	public function unauthPush(?int $ts = null): int {
		if ($ts !== null) {
			$this->appConfig->setValueString(Application::APP_ID, 'ingest_unauth_last', (string)$ts);
			return $ts;
		}
		return (int)$this->appConfig->getValueString(Application::APP_ID, 'ingest_unauth_last', '0');
	}

	public function adminBool(string $key): bool {
		return $this->appConfig->getValueString(Application::APP_ID, $key, AdminConfig::DEFAULTS[$key] ? '1' : '0') === '1';
	}

	public function adminInt(string $key): int {
		return (int)$this->appConfig->getValueString(Application::APP_ID, $key, (string)AdminConfig::DEFAULTS[$key]);
	}

	public function adminString(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, (string)AdminConfig::DEFAULTS[$key]);
	}

	/** @return list<string> */
	public function allowedGroups(): array {
		$j = json_decode($this->appConfig->getValueString(Application::APP_ID, 'allowed_groups', '[]'), true);
		return is_array($j) ? array_values(array_map('strval', $j)) : [];
	}

	/** the administrator may limit the app to groups; the Nextcloud app setting "enable for specific groups" works as well */
	public function userAllowed(string $uid): bool {
		$allowed = $this->allowedGroups();
		if ($allowed === []) {
			return true;
		}
		$u = $this->users->get($uid);
		return $u !== null && AdminConfig::groupAllowed($allowed, array_values($this->groups->getUserGroupIds($u)));
	}

	public function aiConfigured(): bool {
		$ai = $this->ai();
		return AdminConfig::aiReady($this->adminBool('ai_enabled'), $this->adminString('ai_provider'), $ai['url'], $ai['model'], $ai['key'] !== '');
	}

	/** @return array{url:string,model:string,key:string,max_tokens:int,timeout:int} */
	public function ai(): array {
		$p = AdminConfig::PROVIDERS[$this->adminString('ai_provider')] ?? AdminConfig::PROVIDERS['ionos'];
		return [
			'url' => $this->appConfig->getValueString(Application::APP_ID, 'ai_url', $p['url']),
			'model' => $this->appConfig->getValueString(Application::APP_ID, 'ai_model', $p['model']),
			'key' => $this->appConfig->getValueString(Application::APP_ID, 'ai_api_key', ''),
			'max_tokens' => $this->adminInt('ai_max_tokens'),
			'timeout' => $this->adminInt('ai_timeout'),
		];
	}
}
