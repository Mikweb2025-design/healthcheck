<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Signed webhook from the app to the automation (n8n). The signature covers a canonical string built from fields that
 * are also inside the JSON body, so the receiver can recompute it without access to the raw request bytes:
 *   X-HC-Signature = "sha256=" + hex(HMAC-SHA256(secret, "<ts>.<event>.<user>.<nonce>.<payload>"))
 * payload is a string field of the body ('' when absent), so its content is covered too.
 */
final class Signature {
	public static function canonical(int $ts, string $event, string $user, string $nonce, string $payload = ''): string {
		return $ts . '.' . $event . '.' . $user . '.' . $nonce . '.' . $payload;
	}

	public static function sign(string $secret, int $ts, string $event, string $user, string $nonce, string $payload = ''): string {
		return 'sha256=' . hash_hmac('sha256', self::canonical($ts, $event, $user, $nonce, $payload), $secret);
	}

	public static function verify(string $secret, array $body, string $signature, int $now, int $tolerance = 300): bool {
		$ts = (int)($body['ts'] ?? 0);
		if ($secret === '' || abs($now - $ts) > $tolerance) {
			return false;
		}
		return hash_equals(self::sign($secret, $ts, (string)($body['event'] ?? ''), (string)($body['user'] ?? ''), (string)($body['nonce'] ?? ''), (string)($body['payload'] ?? '')), $signature);
	}

	/** only https (or http to localhost) URLs without credentials */
	public static function validUrl(string $url): bool {
		if ($url === '') {
			return true;
		}
		$p = parse_url($url);
		if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || strlen($url) > 500) {
			return false;
		}
		$scheme = $p['scheme'] ?? '';
		return $scheme === 'https' || ($scheme === 'http' && in_array($p['host'], ['localhost', '127.0.0.1'], true));
	}
}
