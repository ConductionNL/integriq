<?php

/**
 * Give every opt-out that carries a purpose the key it has under the purpose rules.
 *
 * Rows written before purposes were read kept the purpose out of their dedupe
 * key. pipelinq wrote such rows with purpose `marketing`. Without a new key,
 * the next write for the same wish would miss the row and add a second one.
 * Rows without a purpose keep their key: they stop everything, as before.
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
 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration;

use Closure;
use OCA\Integriq\Db\OptOutMapper;
use OCA\Integriq\Outbound\Identity\OptOutRowBuilder;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Throwable;

/**
 * Re-keys the opt-out rows that carry a purpose.
 *
 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
 */
class Version2Date20261007100000 extends SimpleMigrationStep {

	/**
	 * Constructor.
	 *
	 * @param OptOutMapper $mapper Reads and saves the rows.
	 * @param OptOutRowBuilder $rows Computes the purpose and the key.
	 */
	public function __construct(
		private readonly OptOutMapper $mapper,
		private readonly OptOutRowBuilder $rows,
	) {

	}//end __construct()

	/**
	 * Re-key the rows. A row that cannot be saved is reported and left as it
	 * is: it still stops its purpose, it only risks a twin on the next write.
	 *
	 * @param IOutput $output The output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema.
	 * @param array<string,mixed> $options The options.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the signature is SimpleMigrationStep's.
	 *
	 * @spec openspec/changes/opt-out-per-purpose/specs/outbound-opt-out-authority/spec.md#requirement-an-opt-out-stops-only-its-own-purpose-req-ooa-011
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$changed = 0;
		foreach ($this->mapper->findWithPurpose() as $row) {
			if ($this->rows->rekey(row: $row) === false) {
				continue;
			}

			try {
				$this->mapper->update($row);
				$changed++;
			} catch (Throwable $exception) {
				$output->warning('Opt-out ' . $row->getId() . ' kept its old key: ' . $exception->getMessage());
			}
		}

		$output->info('Opt-outs re-keyed by purpose: ' . $changed);

	}//end postSchemaChange()

}//end class
