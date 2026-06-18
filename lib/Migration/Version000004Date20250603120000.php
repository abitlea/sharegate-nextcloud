<?php

declare(strict_types=1);

namespace OCA\ShareGate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000004Date20250603120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sharegate_shares')) {
			$table = $schema->getTable('sharegate_shares');
			if (!$table->hasColumn('file_id')) {
				$table->addColumn('file_id', 'bigint', [
					'notnull' => true,
					'default' => 0,
				]);
				$table->addIndex(['created_by', 'file_id'], 'sg_shares_user_file_idx');
			}
		}

		return $schema;
	}
}
