<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * App pages as complete HTML documents with their own <head> (no Nextcloud layout). iOS "Add to Home Screen" reads
 * manifest, icon and title only from the delivered HTML, and Nextcloud's own head would win otherwise. Pure.
 */
final class StandalonePage {
	public static function csp(): string {
		return implode('; ', [
			"default-src 'none'",
			"script-src 'self'",
			"style-src 'self' 'unsafe-inline'",
			"img-src 'self' data: blob:",
			"connect-src 'self'",
			"manifest-src 'self'",
			"font-src 'self'",
			"worker-src 'self'",
			"object-src 'none'",
			"base-uri 'none'",
			"form-action 'self'",
			"frame-ancestors 'none'",
		]);
	}

	public static function e(string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * @param array{lang:string,title:string,base:string,manifest:string,icon:string,apple:string,css:list<string>,js:list<string>,page:string,attrs?:array<string,string>,requesttoken?:string,bodyClass?:string} $o
	 */
	public static function html(array $o): string {
		$e = [self::class, 'e'];
		$title = $e($o['title']);
		$h = [];
		$h[] = '<!DOCTYPE html>';
		$h[] = '<html lang="' . $e($o['lang']) . '">';
		$h[] = '<head>';
		$h[] = '<meta charset="utf-8">';
		$h[] = '<title>' . $title . '</title>';
		$h[] = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
		$h[] = '<meta name="color-scheme" content="light dark">';
		$h[] = '<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">';
		$h[] = '<meta name="theme-color" content="#0f1419" media="(prefers-color-scheme: dark)">';
		$h[] = '<meta name="apple-mobile-web-app-capable" content="yes">';
		$h[] = '<meta name="mobile-web-app-capable" content="yes">';
		$h[] = '<meta name="apple-mobile-web-app-title" content="' . $title . '">';
		$h[] = '<meta name="apple-mobile-web-app-status-bar-style" content="default">';
		$h[] = '<meta name="format-detection" content="telephone=no">';
		$h[] = '<meta name="referrer" content="same-origin">';
		$h[] = '<meta name="robots" content="noindex, nofollow">';
		$h[] = '<link rel="manifest" href="' . $e($o['manifest']) . '">';
		$h[] = '<link rel="apple-touch-icon" sizes="180x180" href="' . $e($o['apple']) . '">';
		$h[] = '<link rel="icon" type="image/png" sizes="192x192" href="' . $e($o['icon']) . '">';
		foreach ($o['css'] as $css) {
			$h[] = '<link rel="stylesheet" href="' . $e($css) . '">';
		}
		$h[] = '</head>';
		$h[] = '<body class="' . $e($o['bodyClass'] ?? 'hc-app') . '">';
		$attrs = [
			'id' => 'hc-root',
			'data-page' => $o['page'],
			'data-base' => $o['base'],
			'data-lang' => $o['lang'],
			'data-requesttoken' => $o['requesttoken'] ?? '',
		] + ($o['attrs'] ?? []);
		$a = [];
		foreach ($attrs as $k => $v) {
			$a[] = $k . '="' . $e((string)$v) . '"';
		}
		$h[] = '<div ' . implode(' ', $a) . '>';
		$h[] = '<noscript><p>' . $title . ' needs JavaScript.</p></noscript>';
		$h[] = '</div>';
		foreach ($o['js'] as $js) {
			$h[] = '<script src="' . $e($js) . '"></script>';
		}
		$h[] = '</body>';
		$h[] = '</html>';
		return implode("\n", $h) . "\n";
	}

	/** @return list<string> */
	public static function scripts(): array {
		return ['hc-i18n', 'hc-core', 'hc-charts', 'hc-app'];
	}

	/** @return list<string> */
	public static function styles(): array {
		return ['hc'];
	}
}
