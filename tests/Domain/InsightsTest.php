<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\Insights;
use OCA\HealthCheck\Tests\TestCase;

class InsightsTest extends TestCase {
	/** 40 calm days ending 2026-03-31, $patch(day index 0..39, row) may change rows */
	private function rows(?callable $patch = null): array {
		$rows = [];
		for ($i = 0; $i < 40; $i++) {
			$day = Insights::addDays('2026-02-20', $i);
			$r = ['day' => $day, 'resting_hr' => 55.0 + ($i % 3) - 1, 'spo2_avg' => 0.97, 'sleep_total' => 450, 'sleep_deep' => 80, 'sleep_rem' => 90,
				'sleep_core' => 280, 'sleep_awake' => 15, 'steps' => 9000, 'active_kcal' => 400, 'weight' => 80.0, 'hrv' => null];
			$rows[] = $patch ? $patch($i, $r) : $r;
		}
		return $rows;
	}

	public function testAddDays(): void {
		$this->assertSame('2026-03-01', Insights::addDays('2026-02-28', 1));
		$this->assertSame('2026-03-29', Insights::addDays('2026-03-30', -1), 'DST change');
		$this->assertSame('2026-03-31', $this->rows()[39]['day']);
	}

	public function testScores(): void {
		$this->assertNull(Insights::sleepScore(null)['score']);
		$s = Insights::sleepScore(['sleep_total' => 450, 'sleep_deep' => 68, 'sleep_rem' => 90, 'sleep_awake' => 10]);
		$this->assertSame(100, $s['score']);
		$short = Insights::sleepScore(['sleep_total' => 225, 'sleep_deep' => null, 'sleep_rem' => null, 'sleep_awake' => 90]);
		$this->assertSame(40, $short['score'], '50 % duration minus 10 for long awake time');
		$three = Insights::sleepScore(['sleep_total' => 193, 'sleep_deep' => 62, 'sleep_rem' => 64, 'sleep_awake' => 1]);
		$this->assertSame(43, $three['score'], 'high stage shares of a 3 h night are scaled down');
		$m = Insights::movementScore(['steps' => 4000, 'active_kcal' => 400]);
		$this->assertSame(70, $m['score']);
		$this->assertNull(Insights::movementScore([])['score']);
		$base = [['resting_hr' => 55], ['resting_hr' => 55], ['resting_hr' => 56]];
		$this->assertSame(100, Insights::recoveryScore(['resting_hr' => 54, 'spo2_avg' => 0.97], $base)['score']);
		$r = Insights::recoveryScore(['resting_hr' => 60, 'spo2_avg' => 0.97], $base);
		$this->assertSame(65, $r['score'], '+5 bpm: 50 points * 70 % + 100 * 30 %');
		$this->assertNull(Insights::recoveryScore(['spo2_avg' => 0.97], $base)['score'], 'no resting HR, no recovery score');
	}

	public function testCalmDataHasNoWarnings(): void {
		$this->assertSame([], Insights::warnings($this->rows()));
		$this->assertSame([], Insights::warnings([]));
	}

	public function testRestingHeartRateUp(): void {
		$rows = $this->rows(fn ($i, $r) => $i >= 38 ? ['resting_hr' => 61.0] + $r : $r);
		$w = Insights::warnings($rows);
		$this->assertSame(['rhr_up'], array_column($w, 'key'));
		$this->assertSame(55.0, $w[0]['params']['base']);
		$one = $this->rows(fn ($i, $r) => $i === 39 ? ['resting_hr' => 70.0] + $r : $r);
		$this->assertSame([], Insights::warnings($one), 'a single day is not a pattern');
	}

	public function testSpo2DeepSleepStepsWeight(): void {
		$rows = $this->rows(fn ($i, $r) => $i >= 37 ? ['spo2_avg' => 0.95] + $r : $r);
		$this->assertSame(['spo2_drop'], array_column(Insights::warnings($rows), 'key'));
		$rows = $this->rows(fn ($i, $r) => $i >= 33 ? ['sleep_deep' => 30] + $r : $r);
		$this->assertSame(['deep_drop'], array_column(Insights::warnings($rows), 'key'));
		$rows = $this->rows(fn ($i, $r) => $i >= 32 ? ['steps' => 3000] + $r : $r);
		$this->assertSame(['steps_drop'], array_column(Insights::warnings($rows), 'key'));
		$rows = $this->rows(fn ($i, $r) => $i >= 33 ? ['sleep_total' => 320] + $r : $r);
		$this->assertContains('sleep_short', array_column(Insights::warnings($rows), 'key'));
		$rows = $this->rows(fn ($i, $r) => $i >= 39 ? ['weight' => 77.0] + $r : $r);
		$w = Insights::warnings($rows);
		$this->assertSame(['weight_change'], array_column($w, 'key'));
		$this->assertSame(-3.8, $w[0]['params']['pct']);
	}

	public function testSummaryOnlyAggregates(): void {
		$rows = $this->rows(fn ($i, $r) => $i >= 33 ? ['steps' => 12000] + $r : $r);
		$wo = [['day' => '2026-03-30', 'start_ts' => strtotime('2026-03-30 10:00 UTC'), 'activity' => 'Cycling', 'duration_min' => 60, 'distance_m' => 20000, 'kcal' => 400]];
		$s = Insights::summary($rows, $wo);
		$this->assertSame('2026-03-25', $s['period']['from']);
		$this->assertSame('2026-03-31', $s['period']['to']);
		$this->assertSame(12000.0, (float)$s['metrics']['steps']['period']['avg']);
		$this->assertSame(9000.0, (float)$s['metrics']['steps']['baseline']['avg']);
		$this->assertSame(33.3, $s['metrics']['steps']['delta_pct']);
		$this->assertSame(1, $s['workouts']['count']);
		$this->assertSame(20.0, $s['workouts']['distance_km']);
		$this->assertCount(12, $s['weeks']);
		$this->assertFalse(isset($s['days']), 'no daily rows');
		$this->assertSame(100, $s['rings']['movement']['score']);
	}

	public function testRingsUseLastCompleteMovementDay(): void {
		$rows = $this->rows();
		$rows[] = ['day' => '2026-04-01', 'steps' => 500, 'active_kcal' => 20];
		$r = Insights::rings($rows);
		$this->assertSame('2026-03-31', $r['movement']['day'], 'export day is cut off');
		$this->assertSame('2026-03-31', $r['sleep']['day']);
	}
}
