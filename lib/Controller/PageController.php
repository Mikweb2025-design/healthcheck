<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Controller;

use OCA\HealthCheck\AppInfo\Application;
use OCA\HealthCheck\Domain\Manifest;
use OCA\HealthCheck\Domain\Messages;
use OCA\HealthCheck\Domain\StandalonePage;
use OCA\HealthCheck\Http\StandalonePageResponse;
use OCA\HealthCheck\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * All app pages are standalone documents with their own head (manifest, icons, title for iOS home screen apps).
 * They need a Nextcloud login: without one Nextcloud redirects to its login page and back.
 */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IURLGenerator $urls,
		private IUserSession $session,
		private Settings $settings,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): StandalonePageResponse {
		return $this->page('main');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function report(): StandalonePageResponse {
		return $this->page('report', 'hc-app hc-print-page');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function infographic(): StandalonePageResponse {
		return $this->page('infographic', 'hc-app hc-info-page');
	}

	private function page(string $page, string $bodyClass = 'hc-app'): StandalonePageResponse {
		$user = $this->session->getUser();
		$uid = $user?->getUID() ?? '';
		if ($uid !== '') {
			$this->settings->activate($uid);
		}
		$lang = $uid === '' ? 'en' : $this->settings->lang($uid);
		$app = Application::APP_ID;
		$v = self::assetVersion();
		$file = fn (string $dir, string $n, string $ext) => $this->urls->linkTo($app, $dir . '/' . $n . '.' . $ext) . '?v=' . $v;
		$base = rtrim($this->base(), '/');
		return new StandalonePageResponse(StandalonePage::html([
			'lang' => $lang,
			'title' => Manifest::name($lang),
			'base' => $base,
			'manifest' => $this->urls->linkToRoute('healthcheck.page.manifest'),
			'icon' => $this->urls->imagePath($app, 'icon-192.png'),
			'apple' => $this->urls->imagePath($app, 'apple-touch-icon.png'),
			'css' => array_map(fn (string $n) => $file('css', $n, 'css'), StandalonePage::styles()),
			'js' => array_map(fn (string $n) => $file('js', $n, 'js'), StandalonePage::scripts()),
			'page' => $page,
			'bodyClass' => $bodyClass,
			'requesttoken' => $this->requestToken(),
			'attrs' => [
				'data-uid' => $uid,
				'data-home' => $this->link('dashboard.dashboard.index', '/apps/dashboard/'),
				'data-files' => $this->link('files.view.index', '/apps/files/') . '?dir=/Health/Import',
				'data-dav' => $this->urls->getWebroot() . '/remote.php/dav/files/' . rawurlencode($uid),
				'data-sw' => $this->urls->linkToRoute('healthcheck.page.serviceWorker'),
				'data-icon' => $this->urls->imagePath($app, 'app.svg'),
			],
		]));
	}

	/** changes whenever a script or stylesheet changes (cache busting for browsers and the service worker) */
	private static function assetVersion(): string {
		$root = dirname(__DIR__, 2);
		$m = [];
		foreach (array_merge(glob($root . '/js/*.js') ?: [], glob($root . '/css/*.css') ?: []) as $f) {
			$m[] = basename($f) . filemtime($f) . filesize($f);
		}
		return substr(md5(implode('|', $m)), 0, 10);
	}

	private function link(string $route, string $fallback): string {
		try {
			$l = $this->urls->linkToRoute($route);
			return $l !== '' ? $l : $this->urls->getWebroot() . $fallback;
		} catch (\Throwable) {
			return $this->urls->getWebroot() . $fallback;
		}
	}

	private function requestToken(): string {
		try {
			return \OCP\Server::get(\OC\Security\CSRF\CsrfTokenManager::class)->getToken()->getEncryptedValue();
		} catch (\Throwable) {
			return '';
		}
	}

	private function base(): string {
		$p = $this->urls->linkToRoute('healthcheck.page.index');
		return str_ends_with($p, '/') ? $p : $p . '/';
	}

	private function lang(): string {
		$u = $this->session->getUser();
		if ($u !== null) {
			return $this->settings->lang($u->getUID());
		}
		$h = (string)$this->request->getHeader('Accept-Language');
		return Messages::lang($h === '' ? null : substr($h, 0, 2));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function manifest(): DataDisplayResponse {
		$icon = fn (string $f) => $this->urls->imagePath(Application::APP_ID, $f);
		$m = Manifest::build($this->base(), [
			'192' => $icon('icon-192.png'), '512' => $icon('icon-512.png'), 'maskable' => $icon('icon-maskable-512.png'), 'svg' => $icon('app.svg'),
		], $this->lang());
		$r = new DataDisplayResponse(json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
		$r->addHeader('Content-Type', 'application/manifest+json');
		$r->addHeader('Cache-Control', 'no-cache');
		return $r;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function serviceWorker(): DataDisplayResponse {
		$file = dirname(__DIR__, 2) . '/js/hc-sw.js';
		$r = new DataDisplayResponse(is_file($file) ? (string)file_get_contents($file) : '');
		$r->addHeader('Content-Type', 'application/javascript');
		$r->addHeader('Service-Worker-Allowed', $this->base());
		$csp = new EmptyContentSecurityPolicy();
		$csp->addAllowedConnectDomain("'self'");
		$r->setContentSecurityPolicy($csp);
		$r->addHeader('Cache-Control', 'no-cache');
		return $r;
	}

	/** small ring image for the dashboard widget, e.g. /ring/sleep/82.svg */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function ring(string $kind, string $value): DataDisplayResponse {
		$colors = ['sleep' => '#7c6cf2', 'recovery' => '#2bb673', 'movement' => '#f2994a', 'none' => '#9aa3ad'];
		$c = $colors[$kind] ?? $colors['none'];
		$v = is_numeric($value) ? max(0, min(100, (int)$value)) : null;
		$r = 22;
		$circ = 2 * M_PI * $r;
		$dash = $v === null ? 0 : $circ * $v / 100;
		$label = $v === null ? '–' : (string)$v;
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 56 56" width="56" height="56">'
			. '<circle cx="28" cy="28" r="' . $r . '" fill="none" stroke="' . $c . '" stroke-opacity=".2" stroke-width="7"/>'
			. '<circle cx="28" cy="28" r="' . $r . '" fill="none" stroke="' . $c . '" stroke-width="7" stroke-linecap="round" stroke-dasharray="'
			. round($dash, 2) . ' ' . round($circ, 2) . '" transform="rotate(-90 28 28)"/>'
			. '<text x="28" y="33" text-anchor="middle" font-family="sans-serif" font-size="15" font-weight="700" fill="' . $c . '">' . $label . '</text></svg>';
		$resp = new DataDisplayResponse($svg);
		$resp->addHeader('Content-Type', 'image/svg+xml');
		$resp->addHeader('Cache-Control', 'private, max-age=86400');
		return $resp;
	}
}
