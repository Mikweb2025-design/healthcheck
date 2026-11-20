<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Administrator settings (app-wide): pure validation, defaults and presets. Storage is in Settings/AdminController.
 * The API key is never part of what is read back; it is write-only.
 */
final class AdminConfig {
	/** OpenAI-compatible chat completion endpoints. `key` = false means the provider works without an API key. */
	public const PROVIDERS = [
		'ionos' => ['url' => 'https://openai.inference.de-txl.ionos.com/v1/chat/completions', 'model' => 'openai/gpt-oss-120b', 'key' => true],
		'openai' => ['url' => 'https://api.openai.com/v1/chat/completions', 'model' => 'gpt-4o-mini', 'key' => true],
		'mistral' => ['url' => 'https://api.mistral.ai/v1/chat/completions', 'model' => 'mistral-small-latest', 'key' => true],
		'anthropic' => ['url' => 'https://api.anthropic.com/v1/messages', 'model' => 'claude-sonnet-5-5', 'key' => true],
		'opencode' => ['url' => 'https://opencode.ai/zen/v1/chat/completions', 'model' => 'big-pickle', 'key' => true],
		'openrouter' => ['url' => 'https://openrouter.ai/api/v1/chat/completions', 'model' => 'openai/gpt-oss-120b:free', 'key' => true],
		'ollama' => ['url' => 'http://localhost:11434/v1/chat/completions', 'model' => 'llama3.1', 'key' => false],
		'custom' => ['url' => '', 'model' => '', 'key' => true],
	];
	public const REPORT_MODES = ['n8n', 'builtin'];

	public const DEFAULTS = [
		'ai_enabled' => true, 'ai_chat' => true, 'ai_tasks' => true, 'ai_provider' => 'ionos',
		'ai_per_hour' => Chat::PER_HOUR, 'ai_per_day' => Chat::PER_DAY, 'ai_max_tokens' => 3000, 'ai_timeout' => 120,
		'report_mode' => 'n8n', 'allow_ingest' => true, 'allow_webhooks' => true,
		'default_lang' => 'en', 'default_report_lang' => '', 'allowed_groups' => [],
	];

	/**
	 * @return array{clean:array<string,mixed>,bad:list<string>} only the keys that were sent; `bad` lists invalid fields
	 */
	public static function validate(array $in): array {
		$c = [];
		$bad = [];
		foreach (['ai_enabled', 'ai_chat', 'ai_tasks', 'allow_ingest', 'allow_webhooks'] as $k) {
			if (array_key_exists($k, $in)) {
				$c[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
			}
		}
		if (array_key_exists('ai_provider', $in)) {
			isset(self::PROVIDERS[(string)$in['ai_provider']]) ? $c['ai_provider'] = (string)$in['ai_provider'] : $bad[] = 'ai_provider';
		}
		if (array_key_exists('ai_url', $in)) {
			self::aiUrl((string)$in['ai_url']) ? $c['ai_url'] = trim((string)$in['ai_url']) : $bad[] = 'ai_url';
		}
		if (array_key_exists('ai_model', $in)) {
			$m = trim((string)$in['ai_model']);
			($m !== '' && strlen($m) <= 100 && !preg_match('/[\x00-\x1f\s]/', $m)) ? $c['ai_model'] = $m : $bad[] = 'ai_model';
		}
		foreach (['ai_per_hour' => [1, 200], 'ai_per_day' => [1, 2000], 'ai_max_tokens' => [200, 16000], 'ai_timeout' => [10, 600]] as $k => [$lo, $hi]) {
			if (array_key_exists($k, $in)) {
				$v = $in[$k];
				(is_numeric($v) && (int)$v >= $lo && (int)$v <= $hi) ? $c[$k] = (int)$v : $bad[] = $k;
			}
		}
		if (array_key_exists('report_mode', $in)) {
			in_array((string)$in['report_mode'], self::REPORT_MODES, true) ? $c['report_mode'] = (string)$in['report_mode'] : $bad[] = 'report_mode';
		}
		if (array_key_exists('default_lang', $in)) {
			in_array((string)$in['default_lang'], Messages::LANGS, true) ? $c['default_lang'] = (string)$in['default_lang'] : $bad[] = 'default_lang';
		}
		if (array_key_exists('default_report_lang', $in)) {
			in_array((string)$in['default_report_lang'], array_merge([''], Messages::LANGS), true) ? $c['default_report_lang'] = (string)$in['default_report_lang'] : $bad[] = 'default_report_lang';
		}
		if (array_key_exists('allowed_groups', $in)) {
			$g = $in['allowed_groups'];
			if (is_string($g)) {
				$g = preg_split('/[\n,]+/', $g) ?: [];
			}
			if (is_array($g)) {
				$g = array_values(array_unique(array_filter(array_map(fn ($x) => trim((string)$x), $g), fn ($x) => $x !== '' && strlen($x) <= 64)));
				$c['allowed_groups'] = array_slice($g, 0, 50);
			} else {
				$bad[] = 'allowed_groups';
			}
		}
		return ['clean' => $c, 'bad' => $bad];
	}

	/** an admin may use any http(s) endpoint (also a local model); no credentials in the URL */
	public static function aiUrl(string $url): bool {
		$url = trim($url);
		$p = parse_url($url);
		return is_array($p) && !empty($p['host']) && !isset($p['user']) && !isset($p['pass']) && strlen($url) <= 500 && in_array($p['scheme'] ?? '', ['http', 'https'], true);
	}

	/** the AI is usable when it is switched on, an endpoint and model are set and a key exists (unless the provider needs none) */
	public static function aiReady(bool $enabled, string $provider, string $url, string $model, bool $keySet): bool {
		$needsKey = (self::PROVIDERS[$provider]['key'] ?? true) === true;
		return $enabled && $url !== '' && $model !== '' && ($keySet || !$needsKey);
	}

	/** in-app access: an empty list means everybody */
	public static function groupAllowed(array $allowed, array $userGroups): bool {
		return $allowed === [] || array_intersect($allowed, $userGroups) !== [];
	}

	/**
	 * Built-in weekly report due? Monday (local) from 06:00, nothing created in the last 5 days, and the user has data from the last 10 days.
	 *
	 * @param int $lastReportAt unix time of the newest AI report (0 = none)
	 */
	public static function weeklyDue(\DateTimeImmutable $localNow, int $lastReportAt, ?string $newestDay): bool {
		if ($localNow->format('N') !== '1' || (int)$localNow->format('G') < 6) {
			return false;
		}
		if ($lastReportAt > $localNow->getTimestamp() - 5 * 86400) {
			return false;
		}
		return $newestDay !== null && $newestDay >= $localNow->modify('-10 days')->format('Y-m-d');
	}

	/** check-up result -> stored weekly report fields */
	public static function reportFromCheckup(array $r): array {
		$summary = trim(implode(' ', array_filter(array_merge($r['good'] ?? [], $r['watch'] ?? []), 'is_string')));
		return ['summary' => mb_substr($summary, 0, 4000), 'tips' => array_values(array_filter($r['actions'] ?? [], 'is_string')), 'doctor' => (string)($r['doctor'] ?? '')];
	}
}
