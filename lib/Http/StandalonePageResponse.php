<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Http;

use OCA\HealthCheck\Domain\StandalonePage;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\EmptyFeaturePolicy;
use OCP\AppFramework\Http\Response;

/**
 * A complete HTML document that bypasses the Nextcloud layout (no core JavaScript, no theming head, no foreign manifest).
 *
 * Nextcloud merges its default policies into every response unless the policy object is exactly the Empty* class;
 * we use those and set the finished header values ourselves (own headers win over the generated ones).
 */
class StandalonePageResponse extends Response {
	public function __construct(private string $html) {
		parent::__construct(Http::STATUS_OK);
		$this->setContentSecurityPolicy(new EmptyContentSecurityPolicy());
		$this->setFeaturePolicy(new EmptyFeaturePolicy());
		$this->addHeader('Content-Type', 'text/html; charset=utf-8');
		$this->addHeader('Content-Security-Policy', StandalonePage::csp());
		$this->addHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
		$this->addHeader('Referrer-Policy', 'same-origin');
		$this->addHeader('X-Content-Type-Options', 'nosniff');
		$this->addHeader('Cache-Control', 'no-store');
	}

	public function render(): string {
		return $this->html;
	}
}
