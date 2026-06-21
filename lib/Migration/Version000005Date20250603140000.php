<?php

declare(strict_types=1);

namespace OCA\ShareGate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000005Date20250603140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sharegate_payments')) {
			$table = $schema->getTable('sharegate_payments');
			if (!$table->hasColumn('status_message')) {
				$table->addColumn('status_message', 'text', [
					'notnull' => false,
				]);
			}
			if (!$table->hasColumn('refunded_at')) {
				$table->addColumn('refunded_at', 'bigint', [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
