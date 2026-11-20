<?php

/**
 * Integriq MappingCheck.
 *
 * Judges an `openformulieren_form_mapping` against the schema it writes into,
 * through OpenRegister's form destination validator.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenFormulieren;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Form\FormDestination;
use OCA\OpenRegister\Service\Form\FormDestinationValidatorInterface;
use Throwable;

/**
 * Every problem with a form mapping, before it may be active.
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-mapping-must-be-valid-against-its-case-schema-before-it-is-active
 */
class MappingCheck {

	/**
	 * Constructor.
	 *
	 * @param FormDestinationValidatorInterface $validator    OpenRegister's form destination validator.
	 * @param SchemaMapper                      $schemaMapper Finds the destination schema by slug.
	 * @param FormFieldMapper                   $fieldMapper  Checks the contract-field expressions.
	 */
	public function __construct(
		private readonly FormDestinationValidatorInterface $validator,
		private readonly SchemaMapper $schemaMapper,
		private readonly FormFieldMapper $fieldMapper,
	) {
	}//end __construct()

	/**
	 * The findings of one mapping; empty when it may be active.
	 *
	 * @param array<string, mixed> $mapping The `openformulieren_form_mapping` object.
	 *
	 * @return list<array{field?: string, property: string, code: string, message: string}>
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-mapping-must-be-valid-against-its-case-schema-before-it-is-active
	 */
	public function findings(array $mapping): array {
		try {
			$this->fieldMapper->validateConfig(fieldMapping: (array)($mapping['fieldMapping'] ?? []));
		} catch (MappingResolutionException $exception) {
			return [['property' => 'fieldMapping', 'code' => 'mapping-invalid', 'message' => $exception->getMessage()]];
		}

		$write = FormDestinationWrite::writeFor(mapping: $mapping);
		try {
			$schema = $this->schemaMapper->find($write['schema']);
		} catch (Throwable) {
			return [[
				'property' => 'destinationSchema',
				'code' => 'destination-unknown',
				'message' => 'The destination schema "' . $write['schema'] . '" does not exist.',
			]];
		}

		return array_values($this->validator->validate($write['mapping'], new FormDestination(schema: $schema)));
	}//end findings()
}//end class
