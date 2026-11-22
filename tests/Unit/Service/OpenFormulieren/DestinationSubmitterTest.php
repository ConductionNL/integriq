<?php

/**
 * Tests for DestinationSubmitter.
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

use OCA\Integriq\Service\OpenFormulieren\DestinationSubmitter;
use OCA\Integriq\Service\OpenFormulieren\FormDestinationWrite;
use OCA\Integriq\Service\OpenFormulieren\FormFieldMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Service\Form\FormSubmission;
use OCA\OpenRegister\Service\Form\FormSubmitServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The inbound submit path: the mapped submission into its case, one audit line.
 */
#[CoversClass(DestinationSubmitter::class)]
#[UsesClass(FormDestinationWrite::class)]
#[UsesClass(FormFieldMapper::class)]
class DestinationSubmitterTest extends TestCase {

	/**
	 * The audit lines dispatched.
	 *
	 * @var list<CriticalActionPerformedEvent>
	 */
	private array $audited = [];

	/**
	 * The submitter over these mapping rows and this submit path.
	 *
	 * @param list<mixed>                $rows       What findAll answers.
	 * @param FormSubmitServiceInterface $formSubmit The submit path.
	 *
	 * @return DestinationSubmitter
	 */
	private function submitter(array $rows, FormSubmitServiceInterface $formSubmit): DestinationSubmitter {
		$objects = $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor()->getMock();
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($rows): array {
				$this->assertSame(
					['register' => 'integriq', 'schema' => 'openformulieren_form_mapping', 'formSlug' => 'melding', 'isEnabled' => true],
					$config['filters']
				);
				return ['results' => $rows];
			}
		);
		$account = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($account);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (CriticalActionPerformedEvent $event): void {
				$this->audited[] = $event;
			}
		);

		return new DestinationSubmitter($objects, new FormFieldMapper(), $formSubmit, $session, $dispatcher);
	}//end submitter()

	/**
	 * The enabled mapping row of form `melding`.
	 *
	 * @return ObjectEntity
	 */
	private function mappingRow(): ObjectEntity {
		$row = new ObjectEntity();
		$row->setUuid('mapping-1');
		$row->setObject(
			[
				'formSlug' => 'melding',
				'isEnabled' => true,
				'fieldMapping' => [
					'title' => ['type' => 'template', 'value' => 'Melding: {{onderwerp}}'],
					'summary' => ['type' => 'from', 'value' => 'toelichting'],
					'channel' => ['type' => 'const', 'value' => 'website'],
				],
				'fixedValues' => ['caseType' => 'ct-1'],
			]
		);

		return $row;
	}//end mappingRow()

	/**
	 * The submission id is the idempotency key and `externalReference`; the
	 * answer is OpenRegister's, and one audit line records hash and outcome.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#scenario-a-valid-submit-returns-the-case-reference
	 *
	 * @return void
	 */
	public function testASubmitCreatesTheCaseWithTheSubmissionIdAsKey(): void {
		$formSubmit = $this->createMock(FormSubmitServiceInterface::class);
		$formSubmit->expects($this->once())->method('submit')
			->with(
				$this->callback(
					static fn (FormSubmission $submission): bool => $submission->payload === [
						'title' => 'Melding: Losse tegel',
						'summary' => 'Bij 1',
						'channel' => 'website',
						'externalReference' => 'of-1',
					]
					&& $submission->writes[0]['register'] === 'dossiq'
					&& $submission->writes[0]['schema'] === 'case'
					&& $submission->writes[0]['mapping']['fixed'] === ['caseType' => 'ct-1']
					&& $submission->scope === 'openformulieren:melding'
					&& $submission->subject !== null
				),
				'of-1'
			)
			->willReturn(['reference' => '2026-0042', 'id' => 'case-1']);

		$answer = $this->submitter([$this->mappingRow()], $formSubmit)->submit('melding', 'of-1', ['onderwerp' => 'Losse tegel', 'toelichting' => 'Bij 1'], 'abc');

		$this->assertSame(['reference' => '2026-0042', 'id' => 'case-1'], $answer);
		$this->assertCount(1, $this->audited);
		$this->assertSame(['of-1', 'abc', 'created 2026-0042'], $this->audited[0]->getParameters());
	}//end testASubmitCreatesTheCaseWithTheSubmissionIdAsKey()

	/**
	 * A form without an enabled mapping is refused with 422 before the submit path.
	 *
	 * @return void
	 */
	public function testAFormWithoutAMappingIsRefused(): void {
		$formSubmit = $this->createMock(FormSubmitServiceInterface::class);
		$formSubmit->expects($this->never())->method('submit');

		try {
			$this->submitter(['not-a-row'], $formSubmit)->submit('melding', 'of-1', [], 'abc');
			$this->fail('expected a refusal');
		} catch (FormSubmitRefusedException $refusal) {
			$this->assertSame(422, $refusal->getStatus());
			$this->assertSame('no-mapping', $refusal->getFindings()[0]['code']);
		}

		$this->assertSame(['of-1', 'abc', 'refused 422'], $this->audited[0]->getParameters());
	}//end testAFormWithoutAMappingIsRefused()

	/**
	 * A value the mapping needs but the submission lacks is a 422 finding.
	 *
	 * @return void
	 */
	public function testAnUnresolvableFieldIsRefused(): void {
		$formSubmit = $this->createMock(FormSubmitServiceInterface::class);
		$formSubmit->expects($this->never())->method('submit');

		$this->expectException(FormSubmitRefusedException::class);
		$this->submitter([$this->mappingRow()], $formSubmit)->submit('melding', 'of-1', ['onderwerp' => 'x'], 'abc');
	}//end testAnUnresolvableFieldIsRefused()

	/**
	 * OpenRegister's refusal passes through, audited with its status.
	 *
	 * @return void
	 */
	public function testARefusalOfTheDestinationPassesThrough(): void {
		$formSubmit = $this->createMock(FormSubmitServiceInterface::class);
		$formSubmit->method('submit')->willThrowException(new FormSubmitRefusedException('caseType missing', 422, [['property' => 'caseType', 'code' => 'required-missing', 'message' => 'm']]));

		try {
			$this->submitter([$this->mappingRow()], $formSubmit)->submit('melding', '', ['onderwerp' => 'x', 'toelichting' => 'y'], 'abc');
			$this->fail('expected a refusal');
		} catch (FormSubmitRefusedException $refusal) {
			$this->assertSame('caseType', $refusal->getFindings()[0]['property']);
		}

		$this->assertSame('refused 422', $this->audited[0]->getParameters()[2]);
	}//end testARefusalOfTheDestinationPassesThrough()
}//end class
