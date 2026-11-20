<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Controller;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\AdminConfig;
use OCA\HealthCheck\Service\Outbound;
use OCA\HealthCheck\Service\Settings;
use OCA\HealthCheck\Service\Store;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Administrator API (no #[NoAdminRequired]: Nextcloud only lets admins in). The API key is write-only. */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private Settings $settings,
		private IAppConfig $appConfig,
		private Outbound $outbound,
		private Store $store,
		private IGroupManager $groups,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function view(): array {
		$ai = $this->settings->ai();
		$all = ['ai_enabled', 'ai_chat', 'ai_tasks', 'allow_ingest', 'allow_webhooks'];
		$out = [];
		foreach ($all as $k) {
			$out[$k] = $this->settings->adminBool($k);
		}
		foreach (['ai_per_hour', 'ai_per_day', 'ai_max_tokens', 'ai_timeout'] as $k) {
			$out[$k] = $this->settings->adminInt($k);
		}
		return $out + [
			'ai_provider' => $this->settings->adminString('ai_provider'),
			'ai_url' => $ai['url'], 'ai_model' => $ai['model'], 'ai_key_set' => $ai['key'] !== '',
			'report_mode' => $this->settings->adminString('report_mode'),
			'default_lang' => $this->settings->adminString('default_lang'),
			'default_report_lang' => $this->settings->adminString('default_report_lang'),
			'allowed_groups' => $this->settings->allowedGroups(),
			'ai_ready' => $this->settings->aiConfigured(),
			'providers' => AdminConfig::PROVIDERS,
			'groups' => array_values(array_map(fn ($g) => $g->getGID(), $this->groups->search('', 200))),
			'stats' => ['users' => count($this->settings->activeUsers()), 'ai_calls_24h' => $this->store->chatCountSince($this->time->getTime() - 86400)],
		];
	}

	public function get(): JSONResponse {
		return new JSONResponse($this->view());
	}

	public function save(): JSONResponse {
		$in = $this->request->getParams();
		$r = AdminConfig::validate($in);
		if ($r['bad'] !== []) {
			return new JSONResponse(['error' => 'validation', 'fields' => $r['bad']], Http::STATUS_BAD_REQUEST);
		}
		foreach ($r['clean'] as $k => $v) {
			$this->appConfig->setValueString(Application::APP_ID, $k, is_bool($v) ? ($v ? '1' : '0') : (is_array($v) ? (string)json_encode($v) : (string)$v));
		}
		if (!empty($in['ai_api_key_clear'])) {
			$this->appConfig->deleteKey(Application::APP_ID, 'ai_api_key');
		} elseif (isset($in['ai_api_key']) && trim((string)$in['ai_api_key']) !== '') {
			$this->appConfig->setValueString(Application::APP_ID, 'ai_api_key', trim((string)$in['ai_api_key']), false, true);
		} else {
			// an old key stored as plain text becomes a sensitive value (hidden in occ and logs)
			$k = $this->appConfig->getValueString(Application::APP_ID, 'ai_api_key', '');
			if ($k !== '') {
				$this->appConfig->setValueString(Application::APP_ID, 'ai_api_key', $k, false, true);
			}
		}
		return new JSONResponse($this->view());
	}

	/** tries the posted (or saved) endpoint with a one-word question; nothing is stored */
	public function test(): JSONResponse {
		$in = $this->request->getParams();
		$saved = $this->settings->ai();
		$url = trim((string)($in['ai_url'] ?? '')) ?: $saved['url'];
		$model = trim((string)($in['ai_model'] ?? '')) ?: $saved['model'];
		if (!AdminConfig::aiUrl($url)) {
			return new JSONResponse(['ok' => false, 'error' => 'ai_url'], Http::STATUS_BAD_REQUEST);
		}
		$key = trim((string)($in['ai_api_key'] ?? '')) ?: $saved['key'];
		$t0 = microtime(true);
		try {
			$r = $this->outbound->chatWith(['url' => $url, 'model' => $model, 'key' => $key, 'max_tokens' => 200, 'timeout' => 30],
				[['role' => 'user', 'content' => 'Reply with the single word: ok']]);
			return new JSONResponse(['ok' => true, 'model' => $r['model'], 'ms' => (int)round((microtime(true) - $t0) * 1000)]);
		} catch (\Throwable $e) {
			$this->logger->info('Health Check AI test failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			$code = $e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse() !== null ? 'http_' . $e->getResponse()->getStatusCode() : ($e instanceof \RuntimeException && preg_match('/^[a-z_]+$/', $e->getMessage()) ? $e->getMessage() : 'request_failed');
			return new JSONResponse(['ok' => false, 'error' => $code]);
		}
	}
}
