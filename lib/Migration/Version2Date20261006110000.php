<?php

/**
 * Create the opt-out log and the short link table.
 *
 * `integriq_opt_out_log` holds every suppression, every exempt override,
 * every recorded change and one allowed count per batch, kept seven years.
 * `integriq_unsubscribe_short` maps the ten-character id an SMS carries to its
 * unsubscribe token.
 *
 * @category Migration
 * @package  OCA\Integriq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration;

use Closure;
use OCA\Integriq\Db\OptOutLogMapper;
use OCA\Integriq\Db\UnsubscribeShortLinkMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `integriq_opt_out_log` and `integriq_unsubscribe_short`.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
 */
class Version2Date20261006110000 extends SimpleMigrationStep {

	/**
	 * Create the tables that are not there yet.
	 *
	 * @param IOutput                   $output        Migration output interface.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-suppressions-and-overrides-are-logged-req-ooa-007
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable(OptOutLogMapper::TABLE) === false) {
			$table = $schema->createTable(OptOutLogMapper::TABLE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('address', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
			$table->addColumn('category', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => '']);
			$table->addColumn('channel', Types::STRING, ['notnull' => false, 'length' => 16, 'default' => '']);
			$table->addColumn('source_app', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']);
			$table->addColumn('correlation_id', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
			$table->addColumn('detail', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['at'], 'integriq_optlog_at');
			$table->addIndex(['correlation_id'], 'integriq_optlog_corr');
			$changed = true;
		}

		if ($schema->hasTable(UnsubscribeShortLinkMapper::TABLE) === false) {
			$table = $schema->createTable(UnsubscribeShortLinkMapper::TABLE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('short_id', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('token', Types::TEXT, ['notnull' => true]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['short_id'], 'integriq_unsub_short');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;

	}//end changeSchema()

}//end class
