<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\Aggregator;
use OCA\HealthCheck\Domain\Ingest;
use OCA\HealthCheck\Tests\TestCase;

class IngestTest extends TestCase {
	private function tz(): \DateTimeZone {
		return new \DateTimeZone('Europe/Berlin');
	}

	public function testNumbers(): void {
		$this->assertSame(62.5, Ingest::number('62,5'));
		$this->assertSame(1234.5, Ingest::number('1.234,5'));
		$this->assertSame(1234.5, Ingest::number('1,234.5'));
		$this->assertSame(97.0, Ingest::number('97 %'));
		$this->assertSame(62.0, Ingest::number("62\u{00A0}bpm"));
		$this->assertSame(0.97, Ingest::number(0.97));
		$this->assertNull(Ingest::number('abc'));
		$this->assertSame('bpm', Ingest::unitOf('62 bpm'));
	}

	public function testDates(): void {
		$exp = (new \DateTimeImmutable('2026-11-18 23:41:00', $this->tz()))->getTimestamp();
		foreach (['2026-11-18T23:41:00+01:00', '2026-11-18 23:41:00 +0100', '18.11.2026, 23:41', '18/11/2026, 23:41', '18 nov 2026, 23:41', '18 nov 2026 alle ore 23:41',
			'18. Nov. 2026 um 23:41', 'Nov 18, 2026 at 11:41 PM', '2026-11-18T22:41:00Z', (string)$exp] as $d) {
			$this->assertSame($exp, Ingest::date($d, $this->tz()), $d);
		}
		$this->assertSame((new \DateTimeImmutable('2026-03-05 00:00:00', $this->tz()))->getTimestamp(), Ingest::date('5.3.2026', $this->tz()));
		$this->assertNull(Ingest::date('31.02.2026, 10:00', $this->tz()));
		$this->assertNull(Ingest::date('gestern', $this->tz()));
	}

	public function testTypesAndSleep(): void {
		$this->assertSame('heart_rate', Ingest::typeKey('Frequenza cardiaca'));
		$this->assertSame('heart_rate', Ingest::typeKey('HKQuantityTypeIdentifierHeartRate'));
		$this->assertSame('sleep', Ingest::typeKey('Analisi del sonno'));
		$this->assertSame('steps', Ingest::typeKey('Schritte'));
		$this->assertNull(Ingest::typeKey('Caffeine'));
		$this->assertSame('HKCategoryValueSleepAnalysisAsleepDeep', Ingest::sleepValue('Sonno profondo'));
		$this->assertSame('HKCategoryValueSleepAnalysisAsleepCore', Ingest::sleepValue('Sonno leggero'));
		$this->assertSame('HKCategoryValueSleepAnalysisAsleepCore', Ingest::sleepValue('Kern'));
		$this->assertSame('HKCategoryValueSleepAnalysisAsleepREM', Ingest::sleepValue('REM'));
		$this->assertSame('HKCategoryValueSleepAnalysisAwake', Ingest::sleepValue('Sveglio'));
		$this->assertSame('HKCategoryValueSleepAnalysisAwake', Ingest::sleepValue('Wach'));
		$this->assertNull(Ingest::sleepValue('A letto'));
		$this->assertNull(Ingest::sleepValue('Im Bett'));
		$this->assertSame('HKCategoryValueSleepAnalysisAsleepUnspecified', Ingest::sleepValue('Addormentato'));
		$this->assertSame('HKCategoryValueSleepAnalysisAsleepDeep', Ingest::sleepValue('4'));
		$this->assertSame('Cycling', Ingest::activity('Ciclismo all’aperto'));
		$this->assertSame('Running', Ingest::activity('HKWorkoutActivityTypeRunning'));
	}

	/** what the provided shortcut sends on an Italian iPhone: flat columns, newline separated */
	private function payload(): array {
		return [
			'v' => '1',
			'heart_rate_values' => "62\n70\n58",
			'heart_rate_starts' => "18 nov 2026, 08:00\n18 nov 2026, 12:00\n18 nov 2026, 23:10",
			'heart_rate_ends' => "18 nov 2026, 08:00\n18 nov 2026, 12:00\n18 nov 2026, 23:10",
			'heart_rate_units' => 'bpm',
			'heart_rate_sources' => 'HUAWEI Health: Europa',
			'steps_values' => "4.200\n3.900",
			'steps_starts' => "18.11.2026, 09:00\n18.11.2026, 18:00",
			'steps_ends' => "18.11.2026, 10:00\n18.11.2026, 19:00",
			'steps_sources' => "HUAWEI Health: Europa\nHUAWEI Health: Europa",
			'spo2_values' => "97 %\n95 %",
			'spo2_starts' => "18 nov 2026, 03:00\n18 nov 2026, 04:00",
			'weight_values' => '93,4',
			'weight_units' => 'kg',
			'weight_starts' => '18.11.2026, 07:10',
			'sleep_values' => "Sonno leggero\nSonno profondo\nREM\nSveglio\nA letto",
			'sleep_starts' => "17 nov 2026, 23:30\n18 nov 2026, 01:00\n18 nov 2026, 02:00\n18 nov 2026, 03:00\n17 nov 2026, 23:00",
			'sleep_ends' => "18 nov 2026, 01:00\n18 nov 2026, 02:00\n18 nov 2026, 03:00\n18 nov 2026, 03:10\n18 nov 2026, 07:00",
			'resting_hr_values' => '57',
			'resting_hr_starts' => '18.11.2026, 06:00',
			'workout_types' => 'Ciclismo',
			'workout_starts' => '18.11.2026, 17:00',
			'workout_ends' => '18.11.2026, 17:45',
			'unknown_values' => 'x',
		];
	}

	public function testColumnarPayloadAggregates(): void {
		$r = Ingest::parse($this->payload(), $this->tz());
		$this->assertSame(13, count($r['samples']), '3 HR + 2 steps + 2 SpO2 + 1 weight + 4 sleep (in bed dropped) + 1 resting');
		$this->assertSame(1, $r['rejected'], 'unknown type');
		$this->assertCount(1, $r['workouts']);
		$this->assertSame('Cycling', $r['workouts'][0]['activity']);
		$this->assertSame(45.0, $r['workouts'][0]['duration_min']);
		$agg = new Aggregator('Europe/Berlin');
		foreach ($r['samples'] as $s) {
			$agg->record($s['hk'], $s['source'], $s['unit'], Ingest::appleDate($s['start'], $this->tz()), Ingest::appleDate($s['end'], $this->tz()), $s['value']);
		}
		$d = $agg->days()['2026-11-18'];
		$this->assertSame(8100, $d['steps'], 'Italian thousands dot');
		$this->assertSame(58, $d['hr_min']);
		$this->assertSame(70, $d['hr_max']);
		$this->assertSame(57.0, $d['resting_hr']);
		$this->assertSame(0.96, $d['spo2_avg']);
		$this->assertSame(93.4, $d['weight']);
		$this->assertSame(210, $d['sleep_total'], '23:30-03:00 asleep');
		$this->assertSame(60, $d['sleep_deep']);
		$this->assertSame(10, $d['sleep_awake']);
	}

	public function testOtherShapesAndHash(): void {
		$r = Ingest::parse(['samples' => [['type' => 'Herzfrequenz', 'value' => '61', 'start' => '18.11.2026, 10:00', 'end' => '18.11.2026, 10:00', 'source' => 'Uhr']],
			'lines' => "Passi;1200;;18/11/2026, 11:00;18/11/2026, 12:00;Telefono\nbad line"], $this->tz());
		$this->assertCount(2, $r['samples']);
		$this->assertSame('steps', $r['samples'][1]['type']);
		$this->assertSame(Ingest::hash($r['samples'][0]), Ingest::hash(Ingest::parse(['samples' => [['type' => 'heart_rate', 'value' => '61,0', 'start' => '2026-11-18T10:00:00+01:00', 'source' => 'Uhr']]], $this->tz())['samples'][0]), 'same sample, same hash');
		$this->assertSame(64, strlen(Ingest::tokenHash('abc')));
	}
}
