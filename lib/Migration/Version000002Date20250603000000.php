<?php

declare(strict_types=1);

namespace OCA\ShareGate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000002Date20250603000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sharegate_share_stats')) {
			$table = $schema->createTable('sharegate_share_stats');
			$table->addColumn('share_id', 'string', ['length' => 16, 'notnull' => true]);
			$table->addColumn('preview_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('save_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('download_count', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('updated_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['share_id']);
		}

		return $schema;
	}
}
