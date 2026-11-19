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

/** Language of the doctor paragraph (may differ from the language of the weekly report). */
class Version0002Date20261120000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$t = $schema->getTable('hc_ai');
		if (!$t->hasColumn('doctor_lang')) {
			$t->addColumn('doctor_lang', Types::STRING, ['notnull' => false, 'length' => 8]);
			return $schema;
		}
		return null;
	}
}
