<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\Checkins;
use OCA\HealthCheck\Tests\TestCase;

class CheckinsTest extends TestCase {
	public function testCleanDefaultsAndLimits(): void {
		$c = Checkins::clean(['energy' => '4', 'note' => "  slept\nwell  "], '2026-11-20');
		$this->assertSame(['day' => '2026-11-20', 'energy' => 4, 'note' => 'slept well'], $c);
		$this->assertSame(null, Checkins::clean(['energy' => 3, 'note' => '  '], '2026-11-20')['note']);
		$this->assertSame(200, mb_strlen((string)Checkins::clean(['energy' => 3, 'note' => str_repeat('ä', 500)], '2026-11-20')['note']));
	}

	public function testCleanRejects(): void {
		foreach ([['energy' => 0], ['energy' => 6], ['energy' => 'x'], ['energy' => 2.5], ['energy' => 3, 'day' => '2026-11-21'], ['energy' => 3, 'day' => 'nope'], []] as $in) {
			$bad = false;
			try {
				Checkins::clean($in, '2026-11-20');
			} catch (\InvalidArgumentException) {
				$bad = true;
			}
			$this->assertTrue($bad, json_encode($in));
		}
	}

	public function testSleepLink(): void {
		$days = [];
		$ci = [];
		for ($i = 1; $i <= 12; $i++) {
			$d = sprintf('2026-11-%02d', $i);
			$long = $i % 2 === 0;
			$days[] = ['day' => $d, 'sleep_total' => $long ? 450.0 : 360.0];
			$ci[] = ['day' => $d, 'energy' => $long ? 4 : 2];
		}
		$l = Checkins::sleepLink($days, $ci);
		$this->assertSame(4.0, $l['enough']);
		$this->assertSame(2.0, $l['short']);
		$this->assertSame(2.0, $l['diff']);
		$this->assertSame(12, $l['n']);
		$this->assertSame(null, Checkins::sleepLink($days, array_slice($ci, 0, 6)), 'too few');
		$this->assertSame(null, Checkins::sleepLink([], $ci), 'no sleep data');
	}

	public function testAverage(): void {
		$this->assertSame(null, Checkins::average([]));
		$this->assertSame(3.0, Checkins::average([['day' => 'a', 'energy' => 2], ['day' => 'b', 'energy' => 4]]));
		$this->assertSame(5.0, Checkins::average([['day' => 'a', 'energy' => 1], ['day' => 'b', 'energy' => 5]], 1));
	}
}
