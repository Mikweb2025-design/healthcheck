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

/** Raw samples pushed by the phone (Shortcuts), kept so later pushes of the same days can be re-aggregated. */
class Version0004Date20261120180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('hc_raw')) {
			return null;
		}
		$t = $schema->createTable('hc_raw');
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$t->setPrimaryKey(['id']);
		$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('hash', Types::STRING, ['notnull' => true, 'length' => 40]);
		$t->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('start_ts', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$t->addColumn('end_ts', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$t->addColumn('value', Types::STRING, ['notnull' => true, 'length' => 64]);
		$t->addColumn('unit', Types::STRING, ['notnull' => false, 'length' => 16]);
		$t->addColumn('source', Types::STRING, ['notnull' => false, 'length' => 64]);
		$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$t->addUniqueIndex(['uid', 'hash'], 'hc_raw_uid_hash');
		$t->addIndex(['uid', 'start_ts'], 'hc_raw_uid_start');
		return $schema;
	}
}
