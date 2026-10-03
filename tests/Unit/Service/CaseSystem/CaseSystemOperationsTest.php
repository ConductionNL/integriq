<?php

/**
 * Unit tests for the case-system source type, driven through the real CallService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-case-system-source-answers-five-operations-in-process-req-cso-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\CaseSystem;

use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemMock;
use OCA\Integriq\Service\CaseSystem\CaseSystemOperations;
use OCA\Integriq\Service\CaseSystem\ZgwCaseSystem;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Loader\ArrayLoader;

/**
 * The five operations answer in-process through CallService.
 */
class CaseSystemOperationsTest extends TestCase {

	private const SOURCE_UUID = 'bbbbbbbb-0000-4000-8000-000000000001';

	/**
	 * Objects CallService saved, in order.
	 *
	 * @var array<int,array{object:array,schema:mixed}>
	 */
	private array $saved = [];

	/**
	 * Build the real CallService with the case-system operations wired in.
	 *
	 * @param ZgwCaseSystem|null $zgw The ZGW mapping (a double when given).
	 *
	 * @return CallService
	 */
	private function callService(?ZgwCaseSystem $zgw = null): CallService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null) {
				$this->saved[] = ['object' => $object, 'schema' => $schema];
				$entity = new ObjectEntity();
				$entity->setUuid('cccccccc-0000-4000-8000-00000000000' . count($this->saved));
				$entity->setObject(is_array($object) === true ? $object : []);

				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		if ($zgw === null) {
			$zgw = new ZgwCaseSystem(transport: $this->createMock(CallServiceCaseSystemTransport::class));
		}

		return new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			new CaseSystemOperations(zgw: $zgw, mock: new CaseSystemMock()),
		);
	}//end callService()

	/**
	 * A case-system source.
	 *
	 * @param array $configuration Its configuration.
	 *
	 * @return ObjectEntity
	 */
	private function source(array $configuration = ['mock' => true]): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid(self::SOURCE_UUID);
		$source->setObject(
			[
				'name' => 'Zaken en Documenten',
				'slug' => 'zgw-zaken',
				'type' => 'case-system',
				// Unresolvable on purpose: an HTTP attempt would answer a 503, not the fixture.
				'location' => 'case-system://zgw-zaken.invalid',
				'isEnabled' => true,
				'configuration' => $configuration,
			]
		);

		return $source;
	}//end source()

	/**
	 * Call one operation the way decidiq's CaseSystemClient does.
	 *
	 * @param CallService $service   The service.
	 * @param string      $operation The operation.
	 * @param array       $body      The body.
	 * @param array       $config    The source configuration.
	 *
	 * @return array{status:int,answer:array,log:array}
	 */
	private function post(CallService $service, string $operation, array $body, array $config = ['mock' => true]): array {
		$log = $service->call(
			source: $this->source(configuration: $config),
			endpoint: '/case-system/' . $operation,
			method: 'POST',
			config: ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json']]
		)->getObject();

		return [
			'status' => (int)$log['response']['statusCode'],
			'answer' => (array)json_decode((string)$log['response']['body'], true),
			'log' => $log,
		];
	}//end post()

	/**
	 * Reading a case by its number answers from the mock and writes a call log naming the source.
	 *
	 * @return void
	 */
	public function testReadingACaseByItsNumber(): void {
		$result = $this->post($this->callService(), 'read-case', ['reference' => 'ZAAK-2026-0001']);

		$this->assertSame(200, $result['status']);
		$this->assertSame('ZAAK-2026-0001', $result['answer']['identification']);
		$this->assertNotSame('', $result['answer']['url']);
		$this->assertNotSame('', $result['answer']['title']);

		$logs = array_values(array_filter($this->saved, fn ($row) => $row['schema'] === 'call_log'));
		$this->assertCount(1, $logs);
		$this->assertSame(self::SOURCE_UUID, $logs[0]['object']['source']);
		$this->assertSame(200, $logs[0]['object']['statusCode']);
		$this->assertSame([], RegisterSchemaValidator::errors('call_log', $logs[0]['object']));
	}//end testReadingACaseByItsNumber()

	/**
	 * Every operation answers in mock mode, and a document added can be listed by its case.
	 *
	 * @return void
	 */
	public function testEveryOperationAnswersInMockMode(): void {
		$service = $this->callService();

		$case = $this->post($service, 'read-case', ['reference' => 'ZAAK-2026-0001'])['answer'];
		$list = $this->post($service, 'list-documents', ['case' => $case['url']]);
		$this->assertSame(200, $list['status']);
		$this->assertNotEmpty($list['answer']['documents']);

		$document = $this->post($service, 'read-document', ['document' => $list['answer']['documents'][0]['url']]);
		$this->assertSame(200, $document['status']);
		$this->assertSame($list['answer']['documents'][0]['name'], $document['answer']['name']);
		$this->assertNotFalse(base64_decode($document['answer']['content'], true));

		$added = $this->post(
			$service,
			'add-document',
			['case' => $case['url'], 'name' => 'Besluit.pdf', 'kind' => 'decision', 'content' => base64_encode('x'), 'confidential' => false, 'ground' => '']
		);
		$this->assertSame(200, $added['status']);
		$this->assertNotSame('', $added['answer']['url']);

		$created = $this->post($service, 'create-case', ['kind' => 'meeting', 'title' => 'Raad', 'date' => '2026-11-12']);
		$this->assertSame(200, $created['status']);
		$this->assertNotSame('', $created['answer']['url']);
		$this->assertNotSame('', $created['answer']['identification']);
	}//end testEveryOperationAnswersInMockMode()

	/**
	 * An unknown operation is refused with 404 naming the five operations.
	 *
	 * @return void
	 */
	public function testAnUnknownOperationIsRefused(): void {
		$result = $this->post($this->callService(), 'delete-case', ['case' => 'x']);

		$this->assertSame(404, $result['status']);
		foreach (['read-case', 'list-documents', 'read-document', 'add-document', 'create-case'] as $operation) {
			$this->assertStringContainsString($operation, $result['answer']['message']);
		}
	}//end testAnUnknownOperationIsRefused()

	/**
	 * Out of mock mode the operation goes to the ZGW mapping with the source's configuration.
	 *
	 * @return void
	 */
	public function testOutOfMockModeTheZgwMappingAnswers(): void {
		$zgw = $this->createMock(ZgwCaseSystem::class);
		$zgw->expects($this->once())->method('run')
			->with('read-case', ['reference' => 'ZAAK-9'], ['zakenSource' => 'z'], 'Zaken en Documenten')
			->willReturn(['url' => 'https://zaken.example.nl/zaken/9', 'identification' => 'ZAAK-9', 'title' => 'Negen']);

		$result = $this->post($this->callService(zgw: $zgw), 'read-case', ['reference' => 'ZAAK-9'], ['zakenSource' => 'z']);

		$this->assertSame(200, $result['status']);
		$this->assertSame('ZAAK-9', $result['answer']['identification']);
	}//end testOutOfMockModeTheZgwMappingAnswers()

	/**
	 * A body that is not a JSON object is refused with 400.
	 *
	 * @return void
	 */
	public function testABodyThatIsNotAnObjectIsRefused(): void {
		$log = $this->callService()->call(
			source: $this->source(),
			endpoint: '/case-system/read-case',
			method: 'POST',
			config: ['body' => 'not json']
		)->getObject();

		$this->assertSame(400, (int)$log['response']['statusCode']);
	}//end testABodyThatIsNotAnObjectIsRefused()
}//end class
