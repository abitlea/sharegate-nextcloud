<?php

declare(strict_types=1);

namespace OCA\ShareGate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000003Date20250613160000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sharegate_payments')) {
			$table = $schema->getTable('sharegate_payments');
			if ($table->hasColumn('provider')) {
				$column = $table->getColumn('provider');
				$column->setDefault('mock');
				$column->setNotnull(true);
			}
		}

		return $schema;
	}
}
