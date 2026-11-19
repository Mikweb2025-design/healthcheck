<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\AiTasks;
use OCA\HealthCheck\Domain\Correlations;
use OCA\HealthCheck\Domain\Insights;
use OCA\HealthCheck\Domain\Weight;
use OCA\HealthCheck\Tests\TestCase;

class WeightTest extends TestCase {
	/** 61 days, weight falls 0.1 kg per day from 90 kg, weigh-in every second day */
	private function rows(): array {
		$rows = [];
		for ($i = 0; $i <= 60; $i++) {
			$day = Insights::addDays('2026-01-01', $i);
			$rows[] = ['day' => $day, 'weight' => $i % 2 === 0 ? round(90 - 0.1 * $i, 2) : null, 'bmi' => $i === 60 ? 27.1 : null, 'bodyfat' => $i === 58 ? 0.235 : null,
				'steps' => 5000 + ($i % 7) * 1000, 'sleep_total' => 400 + ($i % 7) * 10, 'resting_hr' => 65 - ($i % 7) * 1.0, 'sleep_deep' => 60, 'active_kcal' => 300];
		}
		return $rows;
	}

	public function testStats(): void {
		$s = Weight::stats($this->rows(), 80.0, '2026-03-12');
		$this->assertSame(['day' => '2026-03-02', 'kg' => 84.0, 'manual' => false], $s['last']);
		$this->assertSame(10, $s['age_days']);
		$this->assertTrue($s['reminder']);
		$this->assertSame(-0.8, $s['change'][7]['kg']);
		$this->assertSame(-3.0, $s['change'][30]['kg']);
		$this->assertNull($s['change'][90], 'no weigh-in 90 days before');
		$this->assertSame(['value' => 27.1, 'day' => '2026-03-02', 'category' => 'over'], $s['bmi']);
		$this->assertSame(23.5, $s['bodyfat']['pct']);
		$this->assertSame(-0.7, $s['rate_kg_week']);
		$this->assertSame(['reached' => false, 'days' => 40, 'date' => '2026-04-11'], $s['eta']);
		$away = Weight::stats($this->rows(), 95.0, '2026-03-03');
		$this->assertSame('away', $away['eta']['direction']);
		$this->assertFalse(Weight::stats([['day' => '2026-01-01', 'weight' => null]], null, '2026-01-02')['has']);
		$this->assertSame('normal', Weight::bmiCategory(22.0));
		$this->assertSame('obese', Weight::bmiCategory(30.0));
	}

	public function testMovingAverage(): void {
		$ma = Weight::movingAverage([['day' => '2026-01-01', 'weight' => 80.0], ['day' => '2026-01-03', 'weight' => 82.0], ['day' => '2026-01-10', 'weight' => 84.0]]);
		$this->assertSame([80.0, 81.0, 84.0], array_column($ma, 'avg'));
	}

	public function testCorrelations(): void {
		$this->assertSame(1.0, round(Correlations::pearson([1, 2, 3, 4], [2, 4, 6, 8]), 6));
		$this->assertSame(-1.0, round(Correlations::pearson([1, 2, 3], [3, 2, 1]), 6));
		$this->assertNull(Correlations::pearson([1, 1, 1], [1, 2, 3]));
		$c = Correlations::compute($this->rows(), [], 365);
		$byKey = array_column($c['pairs'], null, 'key');
		$this->assertSame(-1.0, $byKey['sleep_rhr']['r'], 'more sleep, lower resting HR in this synthetic data');
		$this->assertSame(61, $byKey['sleep_rhr']['n']);
		$this->assertSame('strong', $byKey['sleep_rhr']['strength']);
		$this->assertCount(7, $c['weekday']);
		$this->assertTrue($c['weekday'][0]['steps_n'] >= 8);
		$few = Correlations::compute(array_slice($this->rows(), 0, 10), [], 365);
		$this->assertSame([], $few['pairs'], 'below the minimum sample size nothing is reported');
	}

	public function testAiTasks(): void {
		$this->assertSame('Sleep was 5 h 42 min, nap 45 min', AiTasks::fixMinutes('Sleep was 342 minutes, nap 45 min'));
		$this->assertSame('Schlaf 6 h 00 min', AiTasks::fixMinutes('Schlaf 360 Minuten'));
		$this->assertSame(['a' => 1], AiTasks::json("```json\n{\"a\":1}\n```"));
		$this->assertNull(AiTasks::json('no json'));
		$c = AiTasks::clean('checkup', ['good' => ['**Steps** up 20 %'], 'watch' => ['sleep 300 min'], 'actions' => ['a', 'b', 'c', 'd'], 'doctor' => '<b>x</b>']);
		$this->assertSame(['Steps up 20 %'], $c['good']);
		$this->assertSame('RHR 62.1 bpm', AiTasks::clean('explain', ['what' => 'RHR 62.1.n bpm', 'possible' => ['x']])['what']);
		$this->assertSame(['sleep 5 h 00 min'], $c['watch']);
		$this->assertCount(3, $c['actions']);
		$this->assertSame('x', $c['doctor']);
		$this->assertNull(AiTasks::clean('checkup', ['good' => []]));
		$this->assertNull(AiTasks::clean('nope', ['a' => 1]));
		$ctx = AiTasks::periodContext($this->rows(), [], ['weight' => 80.0], 30, '2026-03-02');
		$this->assertSame('7 h 40 min', $ctx['metrics']['sleep_total']['period']['max']);
		$this->assertTrue($ctx['weight']['has']);
		$this->assertStringContainsString('must be written in Italian', AiTasks::checkupMessages($ctx, 'it')[0]['content']);
		$w = ['key' => 'rhr_up', 'day' => '2026-03-02', 'params' => []];
		$e = AiTasks::explainContext($w, $this->rows(), [['day' => '2026-03-01', 'duration_min' => 30]]);
		$this->assertCount(14, $e['last_14_days']);
		$this->assertSame(30, $e['last_14_days'][12]['workout_min']);
	}
}
