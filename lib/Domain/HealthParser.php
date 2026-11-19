<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Domain;

/**
 * Streams an Apple Health export (Export.zip or Export.xml) with XMLReader into an Aggregator.
 * The XML can be close to 1 GB: it is never loaded as a whole, only one element at a time. Records of types we do not
 * use are skipped after reading a single attribute.
 */
final class HealthParser {
	public const XML_IN_ZIP = 'apple_health_export/Export.xml';

	/** finds the Export.xml inside a zip (also when the folder name is localised) */
	public static function xmlEntry(string $zipPath): ?string {
		$z = new \ZipArchive();
		if ($z->open($zipPath, \ZipArchive::RDONLY) !== true) {
			return null;
		}
		$found = null;
		if ($z->locateName(self::XML_IN_ZIP) !== false) {
			$found = self::XML_IN_ZIP;
		} else {
			for ($i = 0; $i < $z->numFiles; $i++) {
				$n = (string)$z->getNameIndex($i);
				$base = strtolower(basename($n));
				if (($base === 'export.xml' || $base === 'exportieren.xml') && !str_contains($n, '__MACOSX')) {
					$found = $n;
					break;
				}
			}
		}
		$z->close();
		return $found;
	}

	/**
	 * @param string $path local path of Export.zip or Export.xml
	 * @param ?callable(int):void $progress called every 500k elements with the element count
	 */
	public static function parse(string $path, Aggregator $agg, ?callable $progress = null): void {
		$uri = $path;
		if (str_ends_with(strtolower($path), '.zip')) {
			$entry = self::xmlEntry($path);
			if ($entry === null) {
				throw new \RuntimeException('no_export_xml');
			}
			$uri = 'zip://' . $path . '#' . $entry;
		}
		$r = new \XMLReader();
		if (!@$r->open($uri, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
			throw new \RuntimeException('cannot_open');
		}
		$n = 0;
		$seenRoot = false;
		try {
			$ok = @$r->read();
			while ($ok) {
				if ($r->nodeType === \XMLReader::ELEMENT) {
					$name = $r->localName;
					if ($name === 'Record') {
						if ((++$n % 500000) === 0 && $progress !== null) {
							$progress($n);
						}
						$type = (string)$r->getAttribute('type');
						if (Aggregator::wanted($type)) {
							$agg->record($type, (string)$r->getAttribute('sourceName'), (string)$r->getAttribute('unit'),
								(string)$r->getAttribute('startDate'), (string)$r->getAttribute('endDate'), (string)$r->getAttribute('value'));
						}
						$ok = $r->next();
						continue;
					}
					if ($name === 'Workout') {
						$xml = $r->readOuterXml();
						if ($xml !== '') {
							$w = self::workout($xml);
							if ($w !== null) {
								$agg->workout($w);
							}
						}
						$ok = $r->next();
						continue;
					}
					if ($name === 'HealthData') {
						$seenRoot = true;
					} elseif ($name === 'ExportDate') {
						$agg->exportDate = $r->getAttribute('value');
					} elseif ($name !== 'Me') {
						// Correlation, ActivitySummary, ClinicalRecord, Audiogram...: not used, skip with their children
						if ($r->depth >= 1) {
							$ok = $r->next();
							continue;
						}
					}
				}
				$ok = @$r->read();
			}
		} finally {
			$r->close();
		}
		if (!$seenRoot) {
			throw new \RuntimeException('not_health_export');
		}
	}

	/** one <Workout> element (attributes of old exports or WorkoutStatistics children of new ones) */
	public static function workout(string $xml): ?array {
		$prev = libxml_use_internal_errors(true);
		$x = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
		libxml_use_internal_errors($prev);
		if ($x === false) {
			return null;
		}
		$a = $x->attributes();
		$num = fn ($v) => ($v !== null && is_numeric((string)$v)) ? (float)(string)$v : null;
		$dur = $num($a['duration'] ?? null);
		if ($dur !== null) {
			$u = strtolower((string)($a['durationUnit'] ?? 'min'));
			$dur = $u === 's' ? $dur / 60 : ($u === 'hr' || $u === 'h' ? $dur * 60 : $dur);
		}
		$dist = $num($a['totalDistance'] ?? null);
		if ($dist !== null) {
			$dist = Aggregator::normalize('distance_m', $dist, (string)($a['totalDistanceUnit'] ?? 'km'));
		}
		$kcal = $num($a['totalEnergyBurned'] ?? null);
		if ($kcal !== null) {
			$kcal = Aggregator::normalize('active_kcal', $kcal, (string)($a['totalEnergyBurnedUnit'] ?? 'kcal'));
		}
		$hr = null;
		foreach ($x->WorkoutStatistics as $st) {
			$sa = $st->attributes();
			$t = (string)($sa['type'] ?? '');
			$unit = (string)($sa['unit'] ?? '');
			if ($t === 'HKQuantityTypeIdentifierActiveEnergyBurned' && $kcal === null && $num($sa['sum'] ?? null) !== null) {
				$kcal = Aggregator::normalize('active_kcal', (float)(string)$sa['sum'], $unit);
			} elseif (str_starts_with($t, 'HKQuantityTypeIdentifierDistance') && $dist === null && $num($sa['sum'] ?? null) !== null) {
				$dist = Aggregator::normalize('distance_m', (float)(string)$sa['sum'], $unit);
			} elseif ($t === 'HKQuantityTypeIdentifierHeartRate' && $num($sa['average'] ?? null) !== null) {
				$hr = round((float)(string)$sa['average'], 1);
			}
		}
		$activity = preg_replace('/^HKWorkoutActivityType/', '', (string)($a['workoutActivityType'] ?? 'Other')) ?: 'Other';
		return [
			'activity' => substr($activity, 0, 60),
			'source' => substr((string)($a['sourceName'] ?? ''), 0, 64),
			'start' => (string)($a['startDate'] ?? ''),
			'end' => (string)($a['endDate'] ?? ''),
			'duration_min' => $dur === null ? null : round($dur, 2),
			'distance_m' => ($dist === null || $dist <= 0) ? null : round($dist),
			'kcal' => ($kcal === null || $kcal <= 0) ? null : round($kcal),
			'hr_avg' => $hr,
		];
	}
}
