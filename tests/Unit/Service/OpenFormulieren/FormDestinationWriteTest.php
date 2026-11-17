<?php

/**
 * Tests for FormDestinationWrite.
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The destination write and payload of a form mapping.
 */
#[CoversClass(FormDestinationWrite::class)]
class FormDestinationWriteTest extends TestCase {

	/**
	 * Without destination or property map: the dossiq case, its own property names, priority left out.
	 *
	 * @return void
	 */
	public function testDefaultsWriteADossiqCaseWithItsOwnPropertyNames(): void {
		$write = FormDestinationWrite::writeFor(
			[
				'fieldMapping' => ['title' => [], 'summary' => [], 'channel' => [], 'priority' => []],
				'fixedValues' => ['caseType' => 'ct-1'],
			]
		);

		$this->assertSame('dossiq', $write['register']);
		$this->assertSame('case', $write['schema']);
		$this->assertSame(
			[
				['field' => 'title', 'property' => 'title'],
				['field' => 'summary', 'property' => 'description'],
				['field' => 'channel', 'property' => 'intakeChannel'],
				['field' => 'externalReference', 'property' => 'externalReference'],
			],
			$write['mapping']['fields']
		);
		$this->assertSame(['caseType' => 'ct-1'], $write['mapping']['fixed']);
	}//end testDefaultsWriteADossiqCaseWithItsOwnPropertyNames()

	/**
	 * A named destination and property map win; a field the mapping lacks, a blank
	 * property and a non-object fixedValues are left out.
	 *
	 * @return void
	 */
	public function testANamedDestinationAndPropertyMapWin(): void {
		$write = FormDestinationWrite::writeFor(
			[
				'destinationRegister' => ' pipelinq ',
				'destinationSchema' => 'ticket',
				'propertyMap' => ['title' => 'subject', 'summary' => '', 'priority' => 'urgency'],
				'fieldMapping' => ['title' => [], 'summary' => []],
				'fixedValues' => 'nope',
			]
		);

		$this->assertSame(['pipelinq', 'ticket'], [$write['register'], $write['schema']]);
		$this->assertSame(
			[['field' => 'title', 'property' => 'subject'], ['field' => 'externalReference', 'property' => 'externalReference']],
			$write['mapping']['fields']
		);
		$this->assertSame([], $write['mapping']['fixed']);
	}//end testANamedDestinationAndPropertyMapWin()

	/**
	 * The payload names each resolved field by its contract name and carries the submission id.
	 *
	 * @return void
	 */
	public function testThePayloadCarriesTheFieldsAndTheSubmissionId(): void {
		$payload = FormDestinationWrite::payloadFor(['mappedTitle' => 'T', 'mappedChannel' => 'web', 'other' => 'x'], 'of-1');

		$this->assertSame(['title' => 'T', 'channel' => 'web', 'externalReference' => 'of-1'], $payload);
	}//end testThePayloadCarriesTheFieldsAndTheSubmissionId()
}//end class
