<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/** The web app manifest (installable PWA). Pure: URLs are passed in. */
final class Manifest {
	public const NAME = ['de' => 'Gesundheits-Check', 'en' => 'Health Check', 'it' => 'Health Check'];
	public const SHORT = ['de' => 'Gesundheit', 'en' => 'Health', 'it' => 'Salute'];
	public const DESCRIPTION = [
		'de' => 'Schlaf, Erholung und Bewegung aus dem Apple-Health-Export',
		'en' => 'Sleep, recovery and movement from the Apple Health export',
		'it' => 'Sonno, recupero e movimento dall’esportazione di Apple Salute',
	];

	public static function name(string $lang): string {
		return self::NAME[Messages::lang($lang)];
	}

	/**
	 * @param string $base path of the app incl. trailing slash, e.g. /apps/healthcheck/
	 * @param array{192:string,512:string,maskable:string,svg:string} $icons
	 */
	public static function build(string $base, array $icons, string $lang): array {
		$lang = Messages::lang($lang);
		return [
			'id' => $base,
			'name' => self::NAME[$lang],
			'short_name' => self::SHORT[$lang],
			'description' => self::DESCRIPTION[$lang],
			'lang' => $lang,
			'start_url' => $base,
			'scope' => $base,
			'display' => 'standalone',
			'orientation' => 'portrait',
			'background_color' => '#0f1419',
			'theme_color' => '#e5484d',
			'categories' => ['health', 'fitness', 'lifestyle'],
			'prefer_related_applications' => false,
			'icons' => [
				['src' => $icons['192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
				['src' => $icons['512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
				['src' => $icons['maskable'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
				['src' => $icons['svg'], 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
			],
		];
	}
}
