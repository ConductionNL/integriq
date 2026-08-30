<?php

/**
 * Integration test: `nextcloud-form` source dispatch through a REAL
 * SynchronizationService -> FormsSyncAdapter chain, using a mocked
 * FormsClientInterface (only the outermost HTTP boundary is a test double —
 * proposal.md Risk 1: no live Forms-enabled Nextcloud instance is available
 * to this repo). Mirrors the standalone-with-mocked-collaborators convention
 * `tests/Integration/NotificatiesCallbackTest.php` already uses.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Integration\Forms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/nextcloud-forms-connector/tasks.md#task-10-integration-tests--mocked-formsclientinterface-end-to-end-dispatch
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Integration\Forms;

use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Forms\FormsAnswerResolver;
use OCA\Integriq\Service\Forms\FormsClientInterface;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002
 */
class FormsSyncIntegrationTest extends TestCase {

	/**
	 * @var SynchronizationService
	 */
	private SynchronizationService $service;

	/**
	 * @var FormsClientInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $formsClient;

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var MappingService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mappingService;

	/**
	 * Set up test fixtures: a real SynchronizationService + FormsSyncAdapter,
	 * a mocked FormsClientInterface as the sole HTTP boundary.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->mappingService = $this->createMock(MappingService::class);

		$this->formsClient = $this->createMock(FormsClientInterface::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		$formsSyncAdapter = new FormsSyncAdapter($this->formsClient, $appManager, $this->createMock(LoggerInterface::class));

		$callService = $this->createMock(CallService::class);
		$container = $this->createMock(ContainerInterface::class);
		$objectService = $this->createMock(ObjectService::class);
		$synchronizationLogService = $this->createMock(SynchronizationLogService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$approvalService = $this->createMock(ApprovalService::class);

		$this->service = new SynchronizationService(
			$callService,
			$this->mappingService,
			$container,
			$this->orObjectService,
			$objectService,
			$this->createMock(LoggerInterface::class),
			$synchronizationLogService,
			$appConfig,
			$approvalService,
			null,
			$formsSyncAdapter,
		);

	}//end setUp()

	/**
	 * TC-4/Task 10: a `nextcloud-form` source dispatch fetches the full
	 * submission (with `answers`) and a `multiple`-type question's answer
	 * stays array-valued end to end — through the real
	 * SynchronizationService -> FormsSyncAdapter chain and into
	 * MappingService::executeMapping()'s `$input`.
	 *
	 * @return void
	 */
	public function testFormsSourceDispatchFetchesFullSubmissionsWithArrayValuedAnswerIntact(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');
		$this->orObjectService->method('find')->willReturn($source);

		$answers = [
			['id' => 1, 'questionId' => 4, 'questionName' => null, 'text' => 'Red'],
			['id' => 2, 'questionId' => 4, 'questionName' => null, 'text' => 'Blue'],
		];
		$this->formsClient->method('listSubmissions')->willReturnCallback(
			function ($source, $formId, $page, $pageSize) use ($answers) {
				if ($page === 1) {
					return [['id' => 1, 'formId' => 42, 'userId' => 'admin', 'timestamp' => 100, 'answers' => $answers]];
				}

				return [];
			}
		);

		$synchronization = [
			'sourceType' => 'nextcloud-form',
			'sourceId' => 'source-uuid-1',
			'sourceConfig' => ['formId' => 42],
		];

		$objects = $this->service->getAllObjectsFromSource(synchronization: $synchronization);

		$this->assertCount(1, $objects);
		$this->assertSame(1, $objects[0]['id']);
		$this->assertSame($answers, $objects[0]['answers']);

		// Feed the fetched submission through the real answer-resolution
		// machinery (FormsAnswerResolver) and confirm the multiple-type
		// question's answer is still an array before it reaches
		// MappingService::executeMapping()'s $input (nextcloud-forms-connector
		// REQ-003's array coercion applies before REQ-002's mapping step).
		$resolver = new FormsAnswerResolver();
		$resolved = $resolver->resolve(
			questions: [['id' => 4, 'text' => 'Interested in', 'name' => '', 'type' => 'multiple']],
			answers: $objects[0]['answers'],
			questionRef: 4
		);

		$this->assertSame(['Red', 'Blue'], $resolved);

		$this->mappingService->expects($this->once())->method('executeMapping')
			->with($this->anything(), $this->callback(static fn (array $input) => ($input['Interested in'] ?? null) === ['Red', 'Blue']))
			->willReturn(['leadInterests' => ['Red', 'Blue']]);

		$mapped = $this->mappingService->executeMapping('mapping-1', ['Interested in' => $resolved]);
		$this->assertSame(['Red', 'Blue'], $mapped['leadInterests']);

	}//end testFormsSourceDispatchFetchesFullSubmissionsWithArrayValuedAnswerIntact()

	/**
	 * TC-16: `getAllObjectsFromSource()` dispatches `sourceType: nextcloud-form`
	 * to the Forms adapter — a regression guard on the `synchronization-engine`
	 * REQ-020 dispatch case.
	 *
	 * @return void
	 */
	public function testSourceDispatchReachesFormsAdapter(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');
		$this->orObjectService->method('find')->willReturn($source);
		$this->formsClient->expects($this->atLeastOnce())->method('listSubmissions')->willReturn([]);

		$synchronization = [
			'sourceType' => 'nextcloud-form',
			'sourceId' => 'source-uuid-1',
			'sourceConfig' => ['formId' => 42],
		];

		$objects = $this->service->getAllObjectsFromSource(synchronization: $synchronization);

		$this->assertSame([], $objects);

	}//end testSourceDispatchReachesFormsAdapter()

	/**
	 * TC-17: `targetType: nextcloud-form` still throws `Unsupported target
	 * type` — no target/deletion branch was added for `nextcloud-form`
	 * (nextcloud-forms-connector REQ-002, synchronization-engine REQ-020).
	 *
	 * @return void
	 */
	public function testTargetTypeNextcloudFormStillThrowsUnsupported(): void {
		$sync = ObjectServiceMockBuilder::objectEntity(
			$this,
			['targetType' => 'nextcloud-form'],
			'sync-uuid-1'
		);
		$this->orObjectService->method('find')->willReturn($sync);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Unsupported target type: nextcloud-form');

		$this->service->updateTarget(['synchronizationId' => 'sync-uuid-1']);

	}//end testTargetTypeNextcloudFormStillThrowsUnsupported()
}//end class
