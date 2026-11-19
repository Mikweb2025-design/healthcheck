<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HealthCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Initial schema: one row per user and day, workouts, import log, AI reports. Timestamps are unix seconds. */
class Version0001Date20261119000000 extends SimpleMigrationStep {
	/**
	 * @param Closure():ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$id = static function ($t): void {
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->setPrimaryKey(['id']);
		};
		$int = ['notnull' => false];
		$float = ['notnull' => false];

		if (!$schema->hasTable('hc_day')) {
			$t = $schema->createTable('hc_day');
			$id($t);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('day', Types::STRING, ['notnull' => true, 'length' => 10]);
			foreach (['steps', 'distance_m', 'cycling_m', 'flights', 'active_kcal', 'hr_min', 'hr_avg', 'hr_max', 'sleep_total', 'sleep_deep', 'sleep_rem', 'sleep_core', 'sleep_awake'] as $c) {
				$t->addColumn($c, Types::INTEGER, $int);
			}
			foreach (['resting_hr', 'spo2_avg', 'spo2_min', 'hrv', 'vo2max', 'weight', 'bmi', 'bodyfat'] as $c) {
				$t->addColumn($c, Types::FLOAT, $float);
			}
			$t->addColumn('sleep_start', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('sleep_end', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('src', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addUniqueIndex(['uid', 'day'], 'hc_day_uid_day');
		}

		if (!$schema->hasTable('hc_workout')) {
			$t = $schema->createTable('hc_workout');
			$id($t);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('wkey', Types::STRING, ['notnull' => true, 'length' => 40]);
			$t->addColumn('day', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('activity', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('source', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('start_ts', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('end_ts', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('duration_min', Types::FLOAT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('distance_m', Types::INTEGER, $int);
			$t->addColumn('kcal', Types::INTEGER, $int);
			$t->addColumn('hr_avg', Types::FLOAT, $float);
			$t->addUniqueIndex(['uid', 'wkey'], 'hc_wo_uid_key');
			$t->addIndex(['uid', 'start_ts'], 'hc_wo_uid_start');
		}

		if (!$schema->hasTable('hc_import')) {
			$t = $schema->createTable('hc_import');
			$id($t);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('file_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('file_size', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('trigger_kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'cron']);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'running']);
			$t->addColumn('started_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('finished_at', Types::BIGINT, ['notnull' => false, 'length' => 20]);
			$t->addColumn('duration_ms', Types::INTEGER, $int);
			$t->addColumn('peak_mb', Types::INTEGER, $int);
			$t->addColumn('records', Types::INTEGER, $int);
			$t->addColumn('days', Types::INTEGER, $int);
			$t->addColumn('workouts', Types::INTEGER, $int);
			$t->addColumn('first_day', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('last_day', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('export_date', Types::STRING, ['notnull' => false, 'length' => 32]);
			$t->addColumn('message', Types::STRING, ['notnull' => false, 'length' => 500]);
			$t->addIndex(['uid', 'started_at'], 'hc_imp_uid_start');
		}

		if (!$schema->hasTable('hc_ai')) {
			$t = $schema->createTable('hc_ai');
			$id($t);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('period_from', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('period_to', Types::STRING, ['notnull' => false, 'length' => 10]);
			$t->addColumn('lang', Types::STRING, ['notnull' => false, 'length' => 8]);
			$t->addColumn('model', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('summary', Types::TEXT, ['notnull' => false]);
			$t->addColumn('tips', Types::TEXT, ['notnull' => false]);
			$t->addColumn('doctor', Types::TEXT, ['notnull' => false]);
			$t->addColumn('plan', Types::TEXT, ['notnull' => false]);
			$t->addColumn('evaluation', Types::TEXT, ['notnull' => false]);
			$t->addColumn('report_path', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addIndex(['uid', 'created_at'], 'hc_ai_uid_created');
		}

		if (!$schema->hasTable('hc_chat')) {
			$t = $schema->createTable('hc_chat');
			$id($t);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addColumn('question', Types::TEXT, ['notnull' => true]);
			$t->addColumn('answer', Types::TEXT, ['notnull' => false]);
			$t->addColumn('model', Types::STRING, ['notnull' => false, 'length' => 100]);
			$t->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'ok']);
			$t->addIndex(['uid', 'created_at'], 'hc_chat_uid_created');
		}
		return $schema;
	}
}
