<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\Chat;
use OCA\HealthCheck\Domain\Signature;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/** Outgoing HTTP: the signed webhook to the automation and the chat completion call. */
class Outbound {
	public function __construct(
		private IClientService $clients,
		private Settings $settings,
		private ITimeFactory $time,
		private ISecureRandom $random,
		private LoggerInterface $logger,
	) {
	}

	/** @return array{ok:bool,status:int,error?:string} */
	public function webhook(string $uid, string $event, array $data = []): array {
		if (!$this->settings->adminBool('allow_webhooks')) {
			return ['ok' => false, 'status' => 0, 'error' => 'not_configured'];
		}
		$url = $this->settings->get($uid, 'webhook_url');
		$secret = $this->settings->get($uid, 'webhook_secret');
		if ($url === '' || $secret === '') {
			return ['ok' => false, 'status' => 0, 'error' => 'not_configured'];
		}
		$ts = $this->time->getTime();
		$nonce = $this->random->generate(16, ISecureRandom::CHAR_ALPHANUMERIC);
		$body = ['event' => $event, 'user' => $uid, 'ts' => $ts, 'nonce' => $nonce, 'payload' => '', 'data' => $data];
		try {
			$r = $this->clients->newClient()->post($url, [
				'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				'headers' => ['Content-Type' => 'application/json', 'X-HC-Signature' => Signature::sign($secret, $ts, $event, $uid, $nonce, '')],
				'timeout' => 15,
			]);
			return ['ok' => $r->getStatusCode() < 300, 'status' => $r->getStatusCode()];
		} catch (\Throwable $e) {
			$this->logger->warning('Health Check webhook failed: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return ['ok' => false, 'status' => 0, 'error' => 'request_failed'];
		}
	}

	/** @return array{answer:string,model:string} */
	public function chat(array $messages): array {
		return $this->chatWith($this->settings->ai(), $messages);
	}

	/**
	 * @param array{url:string,model:string,key:string,max_tokens:int,timeout:int} $ai
	 * @return array{answer:string,model:string}
	 */
	public function chatWith(array $ai, array $messages): array {
		if ($ai['url'] === '' || $ai['model'] === '') {
			throw new \RuntimeException('ai_not_configured');
		}
		$anthropic = Chat::isAnthropic($ai['url']);
		$headers = ['Content-Type' => 'application/json'];
		$key = preg_replace('/^Bearer\s+/i', '', $ai['key']);
		if ($anthropic) {
			$headers['x-api-key'] = (string)$key;
			$headers['anthropic-version'] = '2023-06-01';
			$body = Chat::anthropicBody($messages, $ai['model'], $ai['max_tokens']);
		} else {
			if ($ai['key'] !== '') {
				$headers['Authorization'] = 'Bearer ' . $key;
			}
			$body = ['model' => $ai['model'], 'messages' => $messages, 'max_tokens' => $ai['max_tokens'], 'reasoning_effort' => 'low', 'temperature' => 0.3];
		}
		$r = $this->clients->newClient()->post($ai['url'], [
			'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			'headers' => $headers,
			'timeout' => $ai['timeout'],
		]);
		$j = json_decode((string)$r->getBody(), true);
		$answer = $anthropic ? Chat::anthropicAnswer(is_array($j) ? $j : []) : Chat::answerOf(is_array($j) ? $j : []);
		if ($answer === '') {
			throw new \RuntimeException('ai_empty');
		}
		return ['answer' => $answer, 'model' => (string)($j['model'] ?? $ai['model'])];
	}
}
