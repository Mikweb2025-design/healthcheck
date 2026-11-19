<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Which device wins when several sources wrote the same metric for the same day.
 * Lower number = preferred. A watch is preferred over the phone (the phone counts the same steps again),
 * synthetic test sources come last before the phone.
 */
final class Sources {
	public static function priority(string $source): int {
		$s = strtolower(trim($source));
		if ($s === '') {
			return 50;
		}
		if (str_starts_with($s, 'huawei health:')) {
			return 0; // current Huawei watch account ("HUAWEI Health: Europa")
		}
		if ($s === 'huawei health') {
			return 1; // older Huawei account
		}
		if (str_contains($s, 'watch')) {
			return 2;
		}
		if (str_contains($s, 'health sync')) {
			return 20;
		}
		if (str_contains($s, 'iphone') || str_contains($s, 'ipad')) {
			return 30;
		}
		return 10;
	}
}
