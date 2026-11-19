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

/** Manual weigh-ins (never touched by imports) and the kind of an AI request (chat, checkup, explain, doctorq). */
class Version0003Date20261120120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('hc_weight')) {
			$t = $schema->createTable('hc_weight');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$t->setPrimaryKey(['id']);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('day', Types::STRING, ['notnull' => true, 'length' => 10]);
			$t->addColumn('weight', Types::FLOAT, ['notnull' => true]);
			$t->addColumn('bodyfat', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$t->addUniqueIndex(['uid', 'day'], 'hc_weight_uid_day');
		}
		$c = $schema->getTable('hc_chat');
		if (!$c->hasColumn('kind')) {
			$c->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'chat']);
		}
		return $schema;
	}
}
