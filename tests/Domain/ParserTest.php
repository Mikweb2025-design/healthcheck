<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Tests\Domain;

use OCA\HealthCheck\Domain\Aggregator;
use OCA\HealthCheck\Domain\HealthParser;
use OCA\HealthCheck\Domain\Sources;
use OCA\HealthCheck\Tests\TestCase;

class ParserTest extends TestCase {
	private static ?array $result = null;

	private function fixture(): string {
		return dirname(__DIR__) . '/fixtures/export_small.xml';
	}

	/** zips the fixture like the iPhone does: apple_health_export/Export.xml */
	private function zip(): string {
		$zip = sys_get_temp_dir() . '/hc-test-' . getmypid() . '.zip';
		$z = new \ZipArchive();
		$z->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$z->addFile($this->fixture(), 'apple_health_export/Export.xml');
		$z->addFromString('apple_health_export/export_cda.xml', '<ClinicalDocument/>');
		$z->close();
		return $zip;
	}

	private function parsed(): array {
		if (self::$result === null) {
			$zip = $this->zip();
			$a = new Aggregator('Europe/Berlin');
			HealthParser::parse($zip, $a);
			@unlink($zip);
			self::$result = ['days' => $a->days(), 'workouts' => $a->workouts(), 'agg' => $a];
		}
		return self::$result;
	}

	public function testZipAndXmlGiveSameResult(): void {
		$a = new Aggregator('Europe/Berlin');
		HealthParser::parse($this->fixture(), $a);
		$this->assertSame($this->parsed()['days'], $a->days());
		$this->assertSame('2026-03-10 21:00:00 +0100', $this->parsed()['agg']->exportDate);
	}

	public function testStepsPreferWatchOverPhone(): void {
		$d = $this->parsed()['days'];
		$this->assertSame(7500, $d['2026-03-09']['steps'], 'watch only, phone ignored, nested correlation skipped');
		$this->assertSame(2100, $d['2026-03-08']['steps'], 'phone as fallback on a day without watch');
		$this->assertSame(2500, $d['2026-03-09']['distance_m']);
		$this->assertSame(100, $d['2026-03-09']['active_kcal'], 'kJ converted');
	}

	public function testHeartRateAndVitals(): void {
		$r = $this->parsed()['days']['2026-03-09'];
		$this->assertSame(60, $r['hr_min']);
		$this->assertSame(120, $r['hr_max'], 'implausible 999 rejected');
		$this->assertSame(90, $r['hr_avg']);
		$this->assertSame(55.0, $r['resting_hr'], 'current Huawei account wins');
		$this->assertSame(0.97, $r['spo2_avg']);
		$this->assertSame(0.96, $r['spo2_min']);
		$this->assertSame(81.9, $r['weight'], 'last value of the day');
		$this->assertSame(0.215, $r['bodyfat']);
		$this->assertSame('HUAWEI Health: Europa', $r['src']);
	}

	public function testSleepMergedAndAttributedToWakeUpDay(): void {
		$r = $this->parsed()['days']['2026-03-09'];
		$this->assertSame(430, $r['sleep_total'], 'overlap merged, other source and nap ignored');
		$this->assertSame(60, $r['sleep_deep']);
		$this->assertSame(90, $r['sleep_rem']);
		$this->assertSame(280, $r['sleep_core']);
		$this->assertSame(20, $r['sleep_awake']);
		$this->assertSame(Aggregator::ts('2026-03-08 23:00:00 +0100'), $r['sleep_start']);
		$this->assertSame(Aggregator::ts('2026-03-09 06:30:00 +0100'), $r['sleep_end']);
		$this->assertNull($this->parsed()['days']['2026-03-08']['sleep_total'], 'night belongs to the wake-up day');
		$this->assertFalse(isset($this->parsed()['days']['2026-03-10']), 'InBed only is not sleep');
	}

	public function testWorkoutsDeduplicatedWithStatistics(): void {
		$w = $this->parsed()['workouts'];
		$this->assertCount(2, $w);
		$this->assertSame('Running', $w[0]['activity']);
		$this->assertSame(30.0, $w[0]['duration_min'], 'seconds converted');
		$this->assertSame(5100.0, $w[0]['distance_m']);
		$this->assertSame('2017-10-15', $w[0]['day']);
		$c = $w[1];
		$this->assertSame('Cycling', $c['activity']);
		$this->assertSame('HUAWEI Health: Europa', $c['source']);
		$this->assertSame(32400.0, $c['distance_m']);
		$this->assertSame(512.0, $c['kcal']);
		$this->assertSame(128.4, $c['hr_avg']);
		$this->assertSame(sha1('HUAWEI Health: Europa|' . $c['start_ts'] . '|Cycling'), $c['wkey']);
	}

	public function testIdempotentKeys(): void {
		$a = new Aggregator('Europe/Berlin');
		HealthParser::parse($this->fixture(), $a);
		$this->assertSame(array_column($this->parsed()['workouts'], 'wkey'), array_column($a->workouts(), 'wkey'));
	}

	public function testRejectsForeignFiles(): void {
		$f = sys_get_temp_dir() . '/hc-test-foreign.xml';
		file_put_contents($f, '<?xml version="1.0"?><Other><Record type="x"/></Other>');
		$thrown = '';
		try {
			HealthParser::parse($f, new Aggregator());
		} catch (\RuntimeException $e) {
			$thrown = $e->getMessage();
		}
		@unlink($f);
		$this->assertSame('not_health_export', $thrown);
		$z = sys_get_temp_dir() . '/hc-test-empty.zip';
		$zz = new \ZipArchive();
		$zz->open($z, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$zz->addFromString('readme.txt', 'x');
		$zz->close();
		$thrown = '';
		try {
			HealthParser::parse($z, new Aggregator());
		} catch (\RuntimeException $e) {
			$thrown = $e->getMessage();
		}
		@unlink($z);
		$this->assertSame('no_export_xml', $thrown);
	}

	public function testSourcePriority(): void {
		$this->assertTrue(Sources::priority('HUAWEI Health: Europa') < Sources::priority('HUAWEI Health'));
		$this->assertTrue(Sources::priority('HUAWEI Health') < Sources::priority('Kinomap'));
		$this->assertTrue(Sources::priority('Kinomap') < Sources::priority('Health Sync'));
		$this->assertTrue(Sources::priority('Health Sync') < Sources::priority('iPhone von Max'));
	}

	public function testUnion(): void {
		$this->assertSame([0, null, null], Aggregator::union([]));
		$this->assertSame([300, 0, 300], Aggregator::union([0, 100, 50, 200, 200, 300]));
		$this->assertSame([200, 0, 400], Aggregator::union([300, 400, 0, 100]));
	}
}
