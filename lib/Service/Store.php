<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Service;

use OCA\HealthCheck\Domain\Aggregator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** All database access. Every query is filtered by the user id: there is no cross-user read anywhere. */
class Store {
	private const INT = ['steps', 'distance_m', 'cycling_m', 'flights', 'active_kcal', 'hr_min', 'hr_avg', 'hr_max', 'sleep_total', 'sleep_deep',
		'sleep_rem', 'sleep_core', 'sleep_awake', 'sleep_start', 'sleep_end'];
	private const FLOAT = ['resting_hr', 'spo2_avg', 'spo2_min', 'hrv', 'vo2max', 'weight', 'bmi', 'bodyfat'];

	public function __construct(private IDBConnection $db) {
	}

	private static function dayRow(array $r): array {
		$o = ['day' => (string)$r['day']];
		foreach (self::INT as $c) {
			$o[$c] = $r[$c] === null ? null : (int)$r[$c];
		}
		foreach (self::FLOAT as $c) {
			$o[$c] = $r[$c] === null ? null : round((float)$r[$c], 4);
		}
		$o['src'] = $r['src'];
		return $o;
	}

	/** replaces the days first..last of the export (idempotent: same export twice gives the same table) */
	public function replaceDays(string $uid, array $days): int {
		if ($days === []) {
			return 0;
		}
		$keys = array_keys($days);
		$first = (string)$keys[0];
		$last = (string)end($keys);
		$this->db->beginTransaction();
		try {
			$q = $this->db->getQueryBuilder();
			$q->delete('hc_day')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
				->andWhere($q->expr()->gte('day', $q->createNamedParameter($first)))
				->andWhere($q->expr()->lte('day', $q->createNamedParameter($last)))
				->executeStatement();
			$ins = $this->db->getQueryBuilder();
			$ins->insert('hc_day')->setValue('uid', $ins->createParameter('uid'))->setValue('day', $ins->createParameter('day'));
			foreach (Aggregator::COLUMNS as $c) {
				$ins->setValue($c, $ins->createParameter($c));
			}
			foreach ($days as $day => $row) {
				$ins->setParameter('uid', $uid)->setParameter('day', (string)$day);
				foreach (Aggregator::COLUMNS as $c) {
					$v = $row[$c] ?? null;
					$type = $v === null ? IQueryBuilder::PARAM_NULL : (in_array($c, self::INT, true) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
					$ins->setParameter($c, $v === null ? null : (in_array($c, self::INT, true) ? (int)$v : (string)$v), $type);
				}
				$ins->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return count($days);
	}

	public function replaceWorkouts(string $uid, array $workouts): int {
		$this->db->beginTransaction();
		try {
			$q = $this->db->getQueryBuilder();
			$q->delete('hc_workout')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
			if ($workouts !== []) {
				$q->andWhere($q->expr()->gte('start_ts', $q->createNamedParameter((int)$workouts[0]['start_ts'], IQueryBuilder::PARAM_INT)));
			}
			$q->executeStatement();
			foreach ($workouts as $w) {
				$i = $this->db->getQueryBuilder();
				$i->insert('hc_workout')->values([
					'uid' => $i->createNamedParameter($uid),
					'wkey' => $i->createNamedParameter($w['wkey']),
					'day' => $i->createNamedParameter($w['day']),
					'activity' => $i->createNamedParameter(substr((string)$w['activity'], 0, 64)),
					'source' => $i->createNamedParameter(substr((string)$w['source'], 0, 64)),
					'start_ts' => $i->createNamedParameter((int)$w['start_ts'], IQueryBuilder::PARAM_INT),
					'end_ts' => $i->createNamedParameter((int)$w['end_ts'], IQueryBuilder::PARAM_INT),
					'duration_min' => $i->createNamedParameter((string)(float)$w['duration_min']),
					'distance_m' => $i->createNamedParameter($w['distance_m'] === null ? null : (int)$w['distance_m'], $w['distance_m'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
					'kcal' => $i->createNamedParameter($w['kcal'] === null ? null : (int)$w['kcal'], $w['kcal'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
					'hr_avg' => $i->createNamedParameter($w['hr_avg'] === null ? null : (string)$w['hr_avg'], $w['hr_avg'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
				])->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return count($workouts);
	}

	/** @return list<array> ascending */
	public function days(string $uid, ?string $from = null, ?string $to = null): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('hc_day')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
		if ($from !== null) {
			$q->andWhere($q->expr()->gte('day', $q->createNamedParameter($from)));
		}
		if ($to !== null) {
			$q->andWhere($q->expr()->lte('day', $q->createNamedParameter($to)));
		}
		$q->orderBy('day', 'ASC');
		$res = $q->executeQuery();
		$out = [];
		while ($r = $res->fetch()) {
			$out[] = self::dayRow($r);
		}
		$res->closeCursor();
		return $this->withManualWeights($uid, $out, $from, $to);
	}

	/** manual weigh-ins win over imported values of the same day (and add days without other data) */
	private function withManualWeights(string $uid, array $rows, ?string $from, ?string $to): array {
		$manual = $this->weights($uid, $from, $to);
		if ($manual === []) {
			return $rows;
		}
		$idx = [];
		foreach ($rows as $i => $r) {
			$idx[$r['day']] = $i;
		}
		foreach ($manual as $m) {
			if (!isset($idx[$m['day']])) {
				$rows[] = ['day' => $m['day']] + array_fill_keys(Aggregator::COLUMNS, null);
				$idx[$m['day']] = count($rows) - 1;
			}
			$i = $idx[$m['day']];
			$rows[$i]['weight'] = $m['weight'];
			if ($m['bodyfat'] !== null) {
				$rows[$i]['bodyfat'] = round($m['bodyfat'] / 100, 4);
			}
			$rows[$i]['weight_src'] = 'manual';
		}
		usort($rows, fn ($a, $b) => strcmp($a['day'], $b['day']));
		return $rows;
	}

	/** @return list<array{id:int,day:string,weight:float,bodyfat:?float,createdAt:int}> bodyfat in percent */
	public function weights(string $uid, ?string $from = null, ?string $to = null): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('hc_weight')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
		if ($from !== null) {
			$q->andWhere($q->expr()->gte('day', $q->createNamedParameter($from)));
		}
		if ($to !== null) {
			$q->andWhere($q->expr()->lte('day', $q->createNamedParameter($to)));
		}
		$q->orderBy('day', 'ASC');
		return array_map(fn ($r) => ['id' => (int)$r['id'], 'day' => $r['day'], 'weight' => round((float)$r['weight'], 2),
			'bodyfat' => $r['bodyfat'] === null ? null : round((float)$r['bodyfat'], 1), 'createdAt' => (int)$r['created_at']], $q->executeQuery()->fetchAll());
	}

	/** one manual weigh-in per day: insert or replace */
	public function saveWeight(string $uid, string $day, float $kg, ?float $fatPct, int $now): void {
		$this->db->beginTransaction();
		try {
			$d = $this->db->getQueryBuilder();
			$d->delete('hc_weight')->where($d->expr()->eq('uid', $d->createNamedParameter($uid)))->andWhere($d->expr()->eq('day', $d->createNamedParameter($day)))->executeStatement();
			$q = $this->db->getQueryBuilder();
			$q->insert('hc_weight')->values([
				'uid' => $q->createNamedParameter($uid), 'day' => $q->createNamedParameter($day), 'weight' => $q->createNamedParameter((string)$kg),
				'bodyfat' => $q->createNamedParameter($fatPct === null ? null : (string)$fatPct, $fatPct === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
				'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function deleteWeight(string $uid, int $id): bool {
		$q = $this->db->getQueryBuilder();
		return $q->delete('hc_weight')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement() > 0;
	}

	/** @return list<array> ascending by start */
	public function workouts(string $uid, ?string $from = null, ?string $to = null): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('hc_workout')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
		if ($from !== null) {
			$q->andWhere($q->expr()->gte('day', $q->createNamedParameter($from)));
		}
		if ($to !== null) {
			$q->andWhere($q->expr()->lte('day', $q->createNamedParameter($to)));
		}
		$q->orderBy('start_ts', 'ASC');
		$res = $q->executeQuery();
		$out = [];
		while ($r = $res->fetch()) {
			$out[] = ['wkey' => $r['wkey'], 'day' => $r['day'], 'activity' => $r['activity'], 'source' => $r['source'], 'start_ts' => (int)$r['start_ts'],
				'end_ts' => (int)$r['end_ts'], 'duration_min' => round((float)$r['duration_min'], 1), 'distance_m' => $r['distance_m'] === null ? null : (int)$r['distance_m'],
				'kcal' => $r['kcal'] === null ? null : (int)$r['kcal'], 'hr_avg' => $r['hr_avg'] === null ? null : round((float)$r['hr_avg'], 1)];
		}
		$res->closeCursor();
		return $out;
	}

	public function counts(string $uid): array {
		$c = [];
		foreach (['hc_day' => 'days', 'hc_workout' => 'workouts', 'hc_import' => 'imports', 'hc_ai' => 'reports', 'hc_chat' => 'chats'] as $t => $k) {
			$q = $this->db->getQueryBuilder();
			$q->select($q->func()->count('*', 'n'))->from($t)->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
			$c[$k] = (int)$q->executeQuery()->fetchOne();
		}
		return $c;
	}

	// ---- import log ---------------------------------------------------------------------------------------------
	public function importStart(string $uid, string $name, int $fileId, int $size, string $trigger, int $now): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('hc_import')->values([
			'uid' => $q->createNamedParameter($uid), 'file_name' => $q->createNamedParameter(mb_substr($name, 0, 255)),
			'file_id' => $q->createNamedParameter($fileId, IQueryBuilder::PARAM_INT), 'file_size' => $q->createNamedParameter($size, IQueryBuilder::PARAM_INT),
			'trigger_kind' => $q->createNamedParameter($trigger), 'status' => $q->createNamedParameter('running'),
			'started_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		return $q->getLastInsertId();
	}

	public function importFinish(int $id, array $f): void {
		$q = $this->db->getQueryBuilder();
		$q->update('hc_import')->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		foreach ($f as $k => $v) {
			$q->set($k, $q->createNamedParameter($v, is_int($v) ? IQueryBuilder::PARAM_INT : ($v === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR)));
		}
		$q->executeStatement();
	}

	public function imports(string $uid, int $limit = 10): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('hc_import')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))->orderBy('started_at', 'DESC')->setMaxResults($limit);
		$rows = $q->executeQuery()->fetchAll();
		return array_map(fn ($r) => [
			'id' => (int)$r['id'], 'file' => $r['file_name'], 'size' => (int)$r['file_size'], 'trigger' => $r['trigger_kind'], 'status' => $r['status'],
			'startedAt' => (int)$r['started_at'], 'finishedAt' => $r['finished_at'] === null ? null : (int)$r['finished_at'],
			'durationMs' => $r['duration_ms'] === null ? null : (int)$r['duration_ms'], 'peakMb' => $r['peak_mb'] === null ? null : (int)$r['peak_mb'],
			'records' => $r['records'] === null ? null : (int)$r['records'], 'days' => $r['days'] === null ? null : (int)$r['days'],
			'workouts' => $r['workouts'] === null ? null : (int)$r['workouts'], 'firstDay' => $r['first_day'], 'lastDay' => $r['last_day'],
			'exportDate' => $r['export_date'], 'message' => $r['message'],
		], $rows);
	}

	public function lastOkImport(string $uid): ?array {
		foreach ($this->imports($uid, 20) as $i) {
			if ($i['status'] === 'ok') {
				return $i;
			}
		}
		return null;
	}

	// ---- AI reports (also the agent's memory) -------------------------------------------------------------------
	public function saveAi(string $uid, array $r, int $now): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('hc_ai')->values([
			'uid' => $q->createNamedParameter($uid), 'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'period_from' => $q->createNamedParameter($r['period_from'] ?? null), 'period_to' => $q->createNamedParameter($r['period_to'] ?? null),
			'lang' => $q->createNamedParameter($r['lang'] ?? null), 'model' => $q->createNamedParameter(mb_substr((string)($r['model'] ?? ''), 0, 100)),
			'summary' => $q->createNamedParameter($r['summary'] ?? ''), 'tips' => $q->createNamedParameter(json_encode($r['tips'] ?? [], JSON_UNESCAPED_UNICODE)),
			'doctor' => $q->createNamedParameter($r['doctor'] ?? ''), 'doctor_lang' => $q->createNamedParameter($r['doctor_lang'] ?? null), 'plan' => $q->createNamedParameter($r['plan'] === null ? null : json_encode($r['plan'], JSON_UNESCAPED_UNICODE)),
			'evaluation' => $q->createNamedParameter(($r['evaluation'] ?? null) === null ? null : json_encode($r['evaluation'], JSON_UNESCAPED_UNICODE)),
			'report_path' => $q->createNamedParameter($r['report_path'] ?? null),
		])->executeStatement();
		return $q->getLastInsertId();
	}

	public function aiReports(string $uid, int $limit = 5): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('hc_ai')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults($limit);
		$rows = $q->executeQuery()->fetchAll();
		return array_map(fn ($r) => [
			'id' => (int)$r['id'], 'createdAt' => (int)$r['created_at'], 'from' => $r['period_from'], 'to' => $r['period_to'], 'lang' => $r['lang'],
			'model' => $r['model'], 'summary' => (string)$r['summary'], 'tips' => json_decode((string)$r['tips'], true) ?: [], 'doctor' => (string)$r['doctor'], 'doctorLang' => $r['doctor_lang'] ?? null,
			'plan' => $r['plan'] === null ? null : json_decode((string)$r['plan'], true), 'evaluation' => $r['evaluation'] === null ? null : json_decode((string)$r['evaluation'], true),
			'reportPath' => $r['report_path'],
		], $rows);
	}

	// ---- chat ---------------------------------------------------------------------------------------------------
	public function chatTimes(string $uid, int $since): array {
		$q = $this->db->getQueryBuilder();
		$q->select('created_at')->from('hc_chat')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->gt('created_at', $q->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		return array_map('intval', $q->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
	}

	/** number of AI calls (chat and tasks) of all users since a time; counts only */
	public function chatCountSince(int $since): int {
		$q = $this->db->getQueryBuilder();
		$q->select($q->func()->count('*', 'n'))->from('hc_chat')->where($q->expr()->gt('created_at', $q->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
		return (int)$q->executeQuery()->fetchOne();
	}

	public function saveChat(string $uid, string $question, string $answer, string $model, string $status, int $now, string $kind = 'chat'): int {
		$q = $this->db->getQueryBuilder();
		$q->insert('hc_chat')->values([
			'uid' => $q->createNamedParameter($uid), 'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'question' => $q->createNamedParameter($question), 'answer' => $q->createNamedParameter($answer),
			'model' => $q->createNamedParameter($model), 'status' => $q->createNamedParameter($status), 'kind' => $q->createNamedParameter($kind),
		])->executeStatement();
		return $q->getLastInsertId();
	}

	public function chats(string $uid, int $limit = 20, string $kind = 'chat'): array {
		$q = $this->db->getQueryBuilder();
		$q->select('*')->from('hc_chat')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))->andWhere($q->expr()->eq('kind', $q->createNamedParameter($kind)))->orderBy('created_at', 'DESC')->addOrderBy('id', 'DESC')->setMaxResults($limit);
		return array_map(fn ($r) => ['id' => (int)$r['id'], 'at' => (int)$r['created_at'], 'question' => $r['question'], 'answer' => $r['answer'], 'model' => $r['model'], 'status' => $r['status']],
			$q->executeQuery()->fetchAll());
	}

	// ---- raw samples from the phone ------------------------------------------------------------------------------
	/** inserts samples that are not stored yet; returns the number of new ones */
	public function insertRaw(string $uid, array $samples, int $now): int {
		if ($samples === []) {
			return 0;
		}
		$hashes = array_map(fn ($s) => $s['hash'], $samples);
		$have = [];
		foreach (array_chunk($hashes, 500) as $chunk) {
			$q = $this->db->getQueryBuilder();
			$q->select('hash')->from('hc_raw')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
				->andWhere($q->expr()->in('hash', $q->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
			foreach ($q->executeQuery()->fetchAll(\PDO::FETCH_COLUMN) as $h) {
				$have[$h] = true;
			}
		}
		$n = 0;
		$this->db->beginTransaction();
		try {
			foreach ($samples as $s) {
				if (isset($have[$s['hash']])) {
					continue;
				}
				$have[$s['hash']] = true;
				$q = $this->db->getQueryBuilder();
				$q->insert('hc_raw')->values([
					'uid' => $q->createNamedParameter($uid), 'hash' => $q->createNamedParameter($s['hash']), 'type' => $q->createNamedParameter($s['hk']),
					'start_ts' => $q->createNamedParameter($s['start'], IQueryBuilder::PARAM_INT), 'end_ts' => $q->createNamedParameter($s['end'], IQueryBuilder::PARAM_INT),
					'value' => $q->createNamedParameter(mb_substr($s['value'], 0, 64)), 'unit' => $q->createNamedParameter(mb_substr($s['unit'], 0, 16)),
					'source' => $q->createNamedParameter($s['source']), 'created_at' => $q->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])->executeStatement();
				$n++;
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return $n;
	}

	/** @return list<array{type:string,start_ts:int,end_ts:int,value:string,unit:?string,source:?string}> */
	public function raw(string $uid, int $from, int $to): array {
		$q = $this->db->getQueryBuilder();
		$q->select('type', 'start_ts', 'end_ts', 'value', 'unit', 'source')->from('hc_raw')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
			->andWhere($q->expr()->gte('start_ts', $q->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($q->expr()->lte('start_ts', $q->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
		return $q->executeQuery()->fetchAll();
	}

	/**
	 * Merges daily rows computed from phone samples. A row that came from the phone before is replaced (the raw data
	 * only grows); a row from a zip import is only completed: empty columns are filled, sums take the larger value,
	 * heart rate min/max are widened. The next zip import overwrites everything again.
	 */
	public function mergeDays(string $uid, array $days): array {
		$stat = ['inserted' => 0, 'replaced' => 0, 'completed' => 0];
		$sums = ['steps', 'distance_m', 'cycling_m', 'flights', 'active_kcal'];
		foreach ($days as $day => $new) {
			$q = $this->db->getQueryBuilder();
			$q->select('*')->from('hc_day')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))->andWhere($q->expr()->eq('day', $q->createNamedParameter((string)$day)));
			$old = $q->executeQuery()->fetch();
			if ($old === false) {
				$this->replaceDays($uid, [$day => ['src' => 'shortcut'] + $new]);
				$stat['inserted']++;
				continue;
			}
			$old = self::dayRow($old);
			if ($old['src'] === 'shortcut') {
				$row = ['src' => 'shortcut'] + $new;
				$stat['replaced']++;
			} else {
				$row = $old;
				foreach (Aggregator::COLUMNS as $c) {
					$nv = $new[$c] ?? null;
					if ($nv === null || $c === 'src') {
						continue;
					}
					if ($row[$c] === null) {
						$row[$c] = $nv;
					} elseif (in_array($c, $sums, true)) {
						$row[$c] = max((int)$row[$c], (int)$nv);
					} elseif ($c === 'hr_min') {
						$row[$c] = min((int)$row[$c], (int)$nv);
					} elseif ($c === 'hr_max') {
						$row[$c] = max((int)$row[$c], (int)$nv);
					}
				}
				unset($row['day']);
				$stat['completed']++;
			}
			$this->replaceDays($uid, [$day => $row]);
		}
		return $stat;
	}

	/** adds workouts unless one starting within 2 minutes is already stored (any source); returns the added ones */
	public function addWorkouts(string $uid, array $workouts): array {
		$added = [];
		foreach ($workouts as $w) {
			$q = $this->db->getQueryBuilder();
			$q->select($q->func()->count('*', 'n'))->from('hc_workout')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))
				->andWhere($q->expr()->gte('start_ts', $q->createNamedParameter($w['start_ts'] - 120, IQueryBuilder::PARAM_INT)))
				->andWhere($q->expr()->lte('start_ts', $q->createNamedParameter($w['start_ts'] + 120, IQueryBuilder::PARAM_INT)));
			if ((int)$q->executeQuery()->fetchOne() > 0) {
				continue;
			}
			$i = $this->db->getQueryBuilder();
			$i->insert('hc_workout')->values([
				'uid' => $i->createNamedParameter($uid), 'wkey' => $i->createNamedParameter($w['wkey']), 'day' => $i->createNamedParameter($w['day']),
				'activity' => $i->createNamedParameter($w['activity']), 'source' => $i->createNamedParameter($w['source']),
				'start_ts' => $i->createNamedParameter($w['start_ts'], IQueryBuilder::PARAM_INT), 'end_ts' => $i->createNamedParameter($w['end_ts'], IQueryBuilder::PARAM_INT),
				'duration_min' => $i->createNamedParameter((string)$w['duration_min']),
				'distance_m' => $i->createNamedParameter($w['distance_m'] === null ? null : (int)$w['distance_m'], $w['distance_m'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'kcal' => $i->createNamedParameter($w['kcal'] === null ? null : (int)$w['kcal'], $w['kcal'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
				'hr_avg' => $i->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
			])->executeStatement();
			$added[] = $w;
		}
		return $added;
	}

	/** last day that has any imported or pushed data */
	public function lastDataDay(string $uid): ?string {
		$q = $this->db->getQueryBuilder();
		$q->select($q->func()->max('day'))->from('hc_day')->where($q->expr()->eq('uid', $q->createNamedParameter($uid)));
		$v = $q->executeQuery()->fetchOne();
		return $v === false || $v === null ? null : (string)$v;
	}

	public function deleteAll(string $uid): array {
		$out = [];
		foreach (['hc_day', 'hc_workout', 'hc_import', 'hc_ai', 'hc_chat', 'hc_weight', 'hc_raw'] as $t) {
			$q = $this->db->getQueryBuilder();
			$out[$t] = $q->delete($t)->where($q->expr()->eq('uid', $q->createNamedParameter($uid)))->executeStatement();
		}
		return $out;
	}
}
