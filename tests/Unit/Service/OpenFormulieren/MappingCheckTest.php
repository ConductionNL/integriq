<?php

/**
 * Tests for MappingCheck.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/tasks.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\OpenFormulieren;

use OCA\Integriq\Service\OpenFormulieren\FormDestinationWrite;
use OCA\Integriq\Service\OpenFormulieren\FormFieldMapper;
use OCA\Integriq\Service\OpenFormulieren\MappingCheck;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Form\FormDestination;
use OCA\OpenRegister\Service\Form\FormDestinationValidatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A form mapping judged against its destination schema.
 */
#[CoversClass(MappingCheck::class)]
#[UsesClass(FormDestinationWrite::class)]
#[UsesClass(FormFieldMapper::class)]
class MappingCheckTest extends TestCase {

	/**
	 * A valid mapping into a dossiq case.
	 *
	 * @return array<string, mixed>
	 */
	private function mapping(): array {
		return [
			'formSlug' => 'melding',
			'fieldMapping' => [
				'title' => ['type' => 'from', 'value' => 'onderwerp'],
				'summary' => ['type' => 'from', 'value' => 'toelichting'],
				'channel' => ['type' => 'const', 'value' => 'website'],
			],
		];
	}//end mapping()

	/**
	 * The validator gets the OpenRegister mapping and the case schema; its findings are the answer.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#scenario-a-mapping-without-the-case-type-is-not-activated
	 *
	 * @return void
	 */
	public function testTheValidatorJudgesTheMappingAgainstTheCaseSchema(): void {
		$schema = new Schema();
		$schema->setSlug('case');
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->expects($this->once())->method('find')->with('case')->willReturn($schema);
		$finding = ['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'caseType is required'];
		$validator = $this->createMock(FormDestinationValidatorInterface::class);
		$validator->expects($this->once())->method('validate')
			->with(
				FormDestinationWrite::destinationMapping($this->mapping()),
				$this->callback(static fn (FormDestination $destination): bool => $destination->schema === $schema && $destination->audience === null)
			)
			->willReturn([$finding]);

		$findings = (new MappingCheck($validator, $schemaMapper, new FormFieldMapper()))->findings($this->mapping());

		$this->assertSame([$finding], $findings);
	}//end testTheValidatorJudgesTheMappingAgainstTheCaseSchema()

	/**
	 * A broken contract-field expression is a finding before the schema is asked.
	 *
	 * @return void
	 */
	public function testABrokenExpressionIsAFindingWithoutAskingTheSchema(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->expects($this->never())->method('find');
		$validator = $this->createMock(FormDestinationValidatorInterface::class);
		$validator->expects($this->never())->method('validate');

		$findings = (new MappingCheck($validator, $schemaMapper, new FormFieldMapper()))->findings(['fieldMapping' => ['title' => 'not-an-object']]);

		$this->assertCount(1, $findings);
		$this->assertSame(['fieldMapping', 'mapping-invalid'], [$findings[0]['property'], $findings[0]['code']]);
	}//end testABrokenExpressionIsAFindingWithoutAskingTheSchema()

	/**
	 * A destination schema that does not exist is a finding.
	 *
	 * @return void
	 */
	public function testAnUnknownDestinationIsAFinding(): void {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willThrowException(new RuntimeException('not found'));
		$validator = $this->createMock(FormDestinationValidatorInterface::class);
		$validator->expects($this->never())->method('validate');

		$findings = (new MappingCheck($validator, $schemaMapper, new FormFieldMapper()))->findings($this->mapping() + ['destinationSchema' => 'nope']);

		$this->assertSame(['destinationSchema', 'destination-unknown'], [$findings[0]['property'], $findings[0]['code']]);
	}//end testAnUnknownDestinationIsAFinding()
}//end class
