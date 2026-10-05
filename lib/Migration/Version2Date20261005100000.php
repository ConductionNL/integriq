<?php

/**
 * Create integriq's opt-out table.
 *
 * The unsubscribe link writes an opt-out from a public request with no
 * session. OpenRegister refuses that write and may not be bypassed from a
 * request path (ADR-099 section 9), so opt-outs live in a table this app owns.
 * The repair step MigrateOptOutsToTable copies the existing OpenRegister
 * opt-outs into it.
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
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration;

use Closure;
use OCA\Integriq\Db\OptOutMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `integriq_opt_outs`.
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */
class Version2Date20261005100000 extends SimpleMigrationStep {

	/**
	 * Create the table when it is not there yet.
	 *
	 * @param IOutput                   $output        Migration output interface.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(OptOutMapper::TABLE) === true) {
			return null;
		}

		$table = $schema->createTable(OptOutMapper::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('address', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('case_ref', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']);
		$table->addColumn('source', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->addColumn('dedupe_key', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('legacy_uuid', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['dedupe_key'], 'integriq_optout_key');
		$table->addIndex(['address'], 'integriq_optout_addr');

		return $schema;

	}//end changeSchema()

}//end class
