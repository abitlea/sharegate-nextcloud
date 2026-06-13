<?php

declare(strict_types=1);

namespace OCA\ShareGate\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 表结构与 ShareGate monorepo 对齐（价格：分；share_id：16 字符）
 */
class Version000001Date20250101000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sharegate_shares')) {
			$table = $schema->createTable('sharegate_shares');
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('share_id', 'string', ['length' => 16, 'notnull' => true]);
			$table->addColumn('file_path', 'string', ['length' => 1024, 'notnull' => true]);
			$table->addColumn('file_name', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('file_size', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('storage_type', 'string', ['length' => 32, 'notnull' => true, 'default' => 'nextcloud']);
			$table->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', 'string', ['length' => 512, 'notnull' => false, 'default' => '']);
			$table->addColumn('price', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('access_days', 'integer', ['notnull' => true, 'default' => 30]);
			$table->addColumn('status', 'string', ['length' => 20, 'notnull' => true, 'default' => 'active']);
			$table->addColumn('created_by', 'string', ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', 'bigint', ['notnull' => true]);
			$table->addColumn('expire_at', 'bigint', ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['share_id'], 'sg_shares_share_id_uniq');
			$table->addIndex(['status'], 'sg_shares_status_idx');
			$table->addIndex(['created_by'], 'sg_shares_user_idx');
		}

		if (!$schema->hasTable('sharegate_payments')) {
			$table = $schema->createTable('sharegate_payments');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('order_id', 'string', ['length' => 64, 'notnull' => true]);
			$table->addColumn('share_id', 'string', ['length' => 16, 'notnull' => true]);
			$table->addColumn('amount', 'integer', ['notnull' => true]);
			$table->addColumn('provider', 'string', ['length' => 32, 'notnull' => true]);
			$table->addColumn('provider_order_id', 'string', ['length' => 128, 'notnull' => false]);
			$table->addColumn('client_user_id', 'string', ['length' => 128, 'notnull' => false]);
			$table->addColumn('status', 'string', ['length' => 20, 'notnull' => true, 'default' => 'pending']);
			$table->addColumn('qr_code', 'text', ['notnull' => false]);
			$table->addColumn('created_at', 'bigint', ['notnull' => true]);
			$table->addColumn('paid_at', 'bigint', ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['order_id'], 'sg_payments_order_uniq');
			$table->addIndex(['share_id'], 'sg_payments_share_idx');
			$table->addIndex(['status'], 'sg_payments_status_idx');
		}

		if (!$schema->hasTable('sharegate_access_grants')) {
			$table = $schema->createTable('sharegate_access_grants');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('share_id', 'string', ['length' => 16, 'notnull' => true]);
			$table->addColumn('payment_id', 'bigint', ['notnull' => true]);
			$table->addColumn('provider_user_id', 'string', ['length' => 128, 'notnull' => true]);
			$table->addColumn('access_token', 'string', ['length' => 512, 'notnull' => false]);
			$table->addColumn('expires_at', 'bigint', ['notnull' => false]);
			$table->addColumn('created_at', 'bigint', ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['share_id'], 'sg_grants_share_idx');
			$table->addIndex(['provider_user_id'], 'sg_grants_user_idx');
		}

		return $schema;
	}
}
