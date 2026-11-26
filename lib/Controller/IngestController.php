<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Controller;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\Ingest;
use OCA\HealthCheck\Service\IngestService;
use OCA\HealthCheck\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Push endpoint for the phone. Authenticated only by the personal ingest token (header X-HC-Token or
 * "Authorization: Bearer"), never by a Nextcloud session; brute force protected and rate limited.
 */
class IngestController extends Controller {
	public function __construct(IRequest $request, private IngestService $ingest, private Settings $settings, private LoggerInterface $logger) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 3600)]
	#[BruteForceProtection(action: 'hc_ingest')]
	public function push(): Response {
		$token = (string)$this->request->getHeader('X-HC-Token');
		if ($token === '' && preg_match('/^Bearer\s+(\S+)$/i', (string)$this->request->getHeader('Authorization'), $m)) {
			$token = $m[1];
		}
		$uid = $token === '' ? null : $this->ingest->userOf($token);
		if ($uid === null) {
			$this->settings->unauthPush(time());
			$r = new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
			$r->throttle(['action' => 'hc_ingest']);
			return $r;
		}
		if (!$this->settings->adminBool('allow_ingest') || !$this->settings->userAllowed($uid)) {
			return new JSONResponse(['error' => 'disabled'], Http::STATUS_FORBIDDEN);
		}
		$body = (string)file_get_contents('php://input', false, null, 0, Ingest::MAX_BYTES + 1);
		if (strlen($body) > Ingest::MAX_BYTES) {
			return new JSONResponse(['error' => 'too_large', 'max_bytes' => Ingest::MAX_BYTES], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}
		$payload = json_decode($body, true);
		if (!is_array($payload)) {
			// a form post or an already parsed body
			$payload = $this->request->getParams();
			unset($payload['_route']);
		}
		if (!is_array($payload) || $payload === []) {
			return new JSONResponse(['error' => 'empty'], Http::STATUS_BAD_REQUEST);
		}
		try {
			return new JSONResponse($this->ingest->ingest($uid, $payload));
		} catch (\Throwable $e) {
			$this->logger->error('Health Check ingest failed: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
			return new JSONResponse(['error' => 'server_error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/** the signed shortcut file */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function shortcut(): Response {
		$f = dirname(__DIR__, 2) . '/shortcuts/Health-Check-Invio.shortcut';
		if (!is_file($f)) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}
		return new DataDownloadResponse((string)file_get_contents($f), 'Health Check – Invio.shortcut', 'application/octet-stream');
	}
}
