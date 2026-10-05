<?php

/**
 * Let integriq's opt-out table hold consent as well as opt-outs.
 *
 * The consent of pipelinq moves into this table (opt-out-before-send), so a row
 * now has a state, a channel, a list, a contact, a lawful basis, evidence and
 * a withdrawal. Columns are only added, each with a default an existing row
 * reads correctly under: an old row is an instance or case opt-out on every
 * channel, and its dedupe key does not change.
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
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
 * Adds the consent columns and the contact index to `integriq_opt_outs`.
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
 */
class Version2Date20261006100000 extends SimpleMigrationStep {

	/**
	 * The columns this step adds, with their options.
	 *
	 * @var array<string,array{0:string,1:array<string,mixed>}>
	 */
	public const COLUMNS = [
		'state' => [Types::STRING, ['notnull' => false, 'length' => 16, 'default' => 'opted-out']],
		'channel' => [Types::STRING, ['notnull' => false, 'length' => 16, 'default' => '']],
		'purpose' => [Types::STRING, ['notnull' => false, 'length' => 32, 'default' => '']],
		'list_ref' => [Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']],
		'contact_ref' => [Types::STRING, ['notnull' => false, 'length' => 255, 'default' => '']],
		'lawful_basis' => [Types::STRING, ['notnull' => false, 'length' => 32, 'default' => '']],
		'evidence' => [Types::TEXT, ['notnull' => false]],
		'withdrawn_at' => [Types::BIGINT, ['notnull' => false]],
		'source_app' => [Types::STRING, ['notnull' => false, 'length' => 64, 'default' => '']],
		'updated_at' => [Types::BIGINT, ['notnull' => false, 'default' => 0]],
	];

	/**
	 * Add what is missing.
	 *
	 * @param IOutput                   $output        Migration output interface.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-record-wishes-through-a-public-change-event-req-ooa-003
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(OptOutMapper::TABLE) === false) {
			return null;
		}

		$table = $schema->getTable(OptOutMapper::TABLE);
		$changed = false;
		foreach (self::COLUMNS as $name => [$type, $columnOptions]) {
			if ($table->hasColumn($name) === true) {
				continue;
			}

			$table->addColumn($name, $type, $columnOptions);
			$changed = true;
		}

		if ($table->hasIndex('integriq_optout_contact') === false) {
			$table->addIndex(['contact_ref'], 'integriq_optout_contact');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;

	}//end changeSchema()

}//end class
