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

/** Daily check-in: how the person feels (energy 1-5, optional short note), one row per user and day. */
class Version0005Date20261120210000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('hc_checkin')) {
			return null;
		}
		$t = $schema->createTable('hc_checkin');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$t->setPrimaryKey(['id']);
		$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('day', Types::STRING, ['notnull' => true, 'length' => 10]);
		$t->addColumn('energy', Types::SMALLINT, ['notnull' => true]);
		$t->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 200]);
		$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$t->addUniqueIndex(['uid', 'day'], 'hc_checkin_uid_day');
		return $schema;
	}
}
