<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\Coach;
use OCA\HealthCheck\Tests\TestCase;

class CoachTest extends TestCase {
	private const TZ = 'Europe/Berlin';

	/** 28 days ending Sunday 2026-11-22; work nights 23:00-07:00, weekend nights 01:30-10:00 */
	private function rows(int $kcalLast7 = 500, int $steps = 9000, float $rhrLast3 = 55.0): array {
		$tz = new \DateTimeZone(self::TZ);
		$rows = [];
		for ($i = 27; $i >= 0; $i--) {
			$d = (new \DateTimeImmutable('2026-11-22', $tz))->modify("-$i days");
			$weekend = (int)$d->format('N') >= 6;
			$start = $d->setTime($weekend ? 1 : 23, $weekend ? 30 : 0)->modify($weekend ? '+0 day' : '-1 day');
			$end = $d->setTime($weekend ? 10 : 7, 0);
			$rows[] = ['day' => $d->format('Y-m-d'), 'sleep_start' => $start->getTimestamp(), 'sleep_end' => $end->getTimestamp(), 'sleep_total' => (float)(($end->getTimestamp() - $start->getTimestamp()) / 60 - 20),
				'steps' => $steps, 'active_kcal' => $i < 7 ? $kcalLast7 : 400, 'resting_hr' => $i < 3 ? $rhrLast3 : 52.0];
		}
		return $rows;
	}

	public function testRegularityAndJetlag(): void {
		$rows = $this->rows();
		$reg = Coach::regularity($rows, '2026-11-22', self::TZ);
		$this->assertTrue($reg !== null && $reg['n'] === 14 && $reg['bed_sd'] > 60, json_encode($reg));
		$jet = Coach::jetlag($rows, '2026-11-22', self::TZ);
		$this->assertTrue($jet !== null && $jet['diff_min'] > 120 && $jet['diff_min'] < 200, json_encode($jet));
		$this->assertSame(null, Coach::regularity(array_slice($rows, -5), '2026-11-22', self::TZ), 'needs 7 nights');
		$this->assertSame(null, Coach::jetlag(array_slice($rows, -10), '2026-11-22', self::TZ), 'needs enough work and free nights');
	}

	public function testLoadStatus(): void {
		$this->assertSame('balanced', Coach::load($this->rows(420, 9000, 52.0), '2026-11-22')['status']);
		$this->assertSame('high', Coach::load($this->rows(560, 9000, 52.0), '2026-11-22')['status']);
		$this->assertSame('rest', Coach::load($this->rows(480, 9000, 57.0), '2026-11-22')['status']);
		$this->assertSame('low', Coach::load($this->rows(200, 9000, 52.0), '2026-11-22')['status']);
		$this->assertSame(null, Coach::load(array_slice($this->rows(), -5), '2026-11-22'));
	}

	public function testTipsAreFewAndPrioritised(): void {
		$c = Coach::compute($this->rows(480, 4000, 57.0), ['sleep_min' => 480, 'steps' => 8000], '2026-11-22', self::TZ);
		$keys = array_column($c['tips'], 'key');
		$this->assertTrue(count($keys) <= 3 && $keys[0] === 'tip_rest', implode(',', $keys));
		$this->assertTrue(in_array('tip_sleep_short', $keys, true) || in_array('tip_bed_regular', $keys, true), implode(',', $keys));
		$good = Coach::compute(array_map(fn ($r) => ['sleep_start' => null, 'sleep_end' => null, 'sleep_total' => 480.0, 'steps' => 9000, 'active_kcal' => 400, 'resting_hr' => 52.0, 'day' => $r['day']], $this->rows()), ['sleep_min' => 450, 'steps' => 8000], '2026-11-22', self::TZ);
		$this->assertSame(['tip_good'], array_column($good['tips'], 'key'));
		$this->assertSame(['regularity' => null, 'jetlag' => null, 'load' => null, 'tips' => []], Coach::compute([], [], null, self::TZ));
	}
}
