<?php

/**
 * Unit tests for IBabsConnectorService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\IBabsConnectorService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the iBabs connector service.
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-8
 */
class IBabsConnectorServiceTest extends TestCase {

	/**
	 * @var IBabsConnectorService
	 */
	private IBabsConnectorService $service;

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var IRootFolder|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $rootFolder;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// IBabsConnectorService constructor signature (2 args): CallService,
		// LoggerInterface. The previous version mocked the legacy SourceMapper
		// and passed it as arg 2 — a pre-existing test bug from before OR
		// cutover, surfaced once #1015 unblocked the suite from crashing at
		// the moment SourceMapper was looked up (OR removed that class).
		$this->callService = $this->createMock(CallService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);

		$this->service = new IBabsConnectorService(
			$this->callService,
			$logger,
			$this->rootFolder
		);

	}//end setUp()

	/**
	 * Test that besluit status mapping works correctly for aangenomen.
	 *
	 * @return void
	 */
	public function testMapBesluitStatusAangenomen(): void {
		$result = $this->service->mapDecisionStatus('aangenomen');
		$this->assertSame('Besluit: aangenomen', $result);

	}//end testMapBesluitStatusAangenomen()

	/**
	 * Test that besluit status mapping handles verworpen.
	 *
	 * @return void
	 */
	public function testMapBesluitStatusVerworpen(): void {
		$result = $this->service->mapDecisionStatus('verworpen');
		$this->assertSame('Besluit: verworpen', $result);

	}//end testMapBesluitStatusVerworpen()

	/**
	 * Test that besluit status mapping handles aangehouden.
	 *
	 * @return void
	 */
	public function testMapBesluitStatusAangehouden(): void {
		$result = $this->service->mapDecisionStatus('aangehouden');
		$this->assertSame('Besluit: aangehouden', $result);

	}//end testMapBesluitStatusAangehouden()

	/**
	 * Test that besluit status mapping handles doorgeschoven.
	 *
	 * @return void
	 */
	public function testMapBesluitStatusDoorgeschoven(): void {
		$result = $this->service->mapDecisionStatus('doorgeschoven');
		$this->assertSame('Besluit: doorgeschoven', $result);

	}//end testMapBesluitStatusDoorgeschoven()

	/**
	 * Test that unknown besluit status returns onbekend.
	 *
	 * @return void
	 */
	public function testMapBesluitStatusUnknown(): void {
		$result = $this->service->mapDecisionStatus('unknown-status');
		$this->assertSame('Besluit: onbekend', $result);

	}//end testMapBesluitStatusUnknown()

	/**
	 * Test that testConnection fails without organisatieId.
	 *
	 * @return void
	 */
	public function testTestConnectionFailsWithoutOrganisatieId(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => []],
			'ibabs-source-1'
		);

		$result = $this->service->testConnection($source);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('Organisation ID', $result['message']);

	}//end testTestConnectionFailsWithoutOrganisatieId()

	/**
	 * Test that testConnection returns success on HTTP 200.
	 *
	 * @return void
	 */
	public function testTestConnectionSuccess(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-2'
		);

		$callLogEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => '[]']],
			'call-log-1'
		);

		$this->callService
			->method('call')
			->willReturn($callLogEntity);

		$result = $this->service->testConnection($source);

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('successful', $result['message']);

	}//end testTestConnectionSuccess()

	/**
	 * Test that testConnection returns failure on HTTP 401.
	 *
	 * @return void
	 */
	public function testTestConnectionInvalidKey(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-3'
		);

		$callLogEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 401],
			'call-log-2'
		);

		$this->callService
			->method('call')
			->willReturn($callLogEntity);

		$result = $this->service->testConnection($source);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('401', $result['message']);

	}//end testTestConnectionInvalidKey()

	/**
	 * Test that pushVoorstel fails without organisatieId.
	 *
	 * @return void
	 */
	public function testPushVoorstelFailsWithoutOrganisatieId(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => []],
			'ibabs-source-4'
		);

		$result = $this->service->pushProposal($source, ['onderwerp' => 'Test voorstel']);

		$this->assertFalse($result['success']);
		$this->assertNull($result['vergaderstukId']);

	}//end testPushVoorstelFailsWithoutOrganisatieId()

	/**
	 * Test that pushVoorstel returns failure when CallService returns status 0.
	 *
	 * @return void
	 */
	public function testPushVoorstelFailsWhenCallServiceFails(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'test-123']],
			'ibabs-source-5'
		);

		// Return a call log with statusCode 0 (e.g. rate-limit early exit).
		$failedLog = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 0],
			'call-log-fail'
		);

		$this->callService
			->method('call')
			->willReturn($failedLog);

		$result = $this->service->pushProposal($source, ['onderwerp' => 'Test voorstel']);

		$this->assertFalse($result['success']);
		$this->assertNull($result['vergaderstukId']);

	}//end testPushVoorstelFailsWhenCallServiceFails()

	/**
	 * Test that pushVoorstel succeeds on HTTP 201.
	 *
	 * @return void
	 */
	public function testPushVoorstelSuccess(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-6'
		);

		$responseBody = json_encode(['id' => 'doc-456']);
		$callLogEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 201, 'response' => ['statusCode' => 201, 'body' => $responseBody]],
			'call-log-3'
		);

		$this->callService
			->method('call')
			->willReturn($callLogEntity);

		$result = $this->service->pushProposal(
			$source,
			['onderwerp' => 'Bestemmingsplan Centrum', 'geheimhouding' => false]
		);

		$this->assertTrue($result['success']);
		$this->assertSame('doc-456', $result['vergaderstukId']);

	}//end testPushVoorstelSuccess()

	/**
	 * Test that pushVoorstel propagates geheimhouding flag.
	 *
	 * @return void
	 */
	public function testPushVoorstelGeheimhouding(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-7'
		);

		$capturedConfig = null;
		$failedLog = ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 0], 'call-log-g');
		$this->callService
			->method('call')
			->willReturnCallback(
				function ($s, $e, $m, $config) use (&$capturedConfig, $failedLog) {
					$capturedConfig = $config;
					return $failedLog;
				}
			);

		$this->service->pushProposal(
			$source,
			['onderwerp' => 'Geheim voorstel', 'geheimhouding' => true]
		);

		$this->assertNotNull($capturedConfig);
		$this->assertTrue($capturedConfig['json']['vertrouwelijk']);

	}//end testPushVoorstelGeheimhouding()

	/**
	 * Test that createAgendapunt fails without organisatieId.
	 *
	 * @return void
	 */
	public function testCreateAgendapuntFailsWithoutOrganisatieId(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => []],
			'ibabs-source-8'
		);

		$result = $this->service->createAgendaItem($source, 'doc-123');

		$this->assertFalse($result['success']);
		$this->assertNull($result['agendapuntId']);
		$this->assertStringContainsString('Organisation ID', $result['message']);

	}//end testCreateAgendapuntFailsWithoutOrganisatieId()

	/**
	 * Test that createAgendapunt returns pending when no vergadering available.
	 *
	 * @return void
	 */
	public function testCreateAgendapuntNoVergaderingAvailable(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-9'
		);

		// First call (GET vergaderingen) returns empty list.
		$emptyListLog = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => json_encode(['items' => []])]],
			'call-log-4'
		);

		$this->callService
			->method('call')
			->willReturn($emptyListLog);

		$result = $this->service->createAgendaItem($source, 'doc-123');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('pending', $result['message']);

	}//end testCreateAgendapuntNoVergaderingAvailable()

	/**
	 * Test that pollBesluiten returns empty array without organisatieId.
	 *
	 * @return void
	 */
	public function testPollBesluitenNoOrganisatieId(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => []],
			'ibabs-source-10'
		);

		$result = $this->service->pollDecisions($source, [['caseId' => 'z-1', 'risMeetingId' => 'v-1']]);
		$this->assertSame([], $result);

	}//end testPollBesluitenNoOrganisatieId()

	/**
	 * Test that pollBesluiten returns empty array when no sync items passed.
	 *
	 * @return void
	 */
	public function testPollBesluitenEmptySyncItems(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-11'
		);

		$result = $this->service->pollDecisions($source, []);
		$this->assertSame([], $result);

	}//end testPollBesluitenEmptySyncItems()

	/**
	 * Test that pollBesluiten maps returned besluit status correctly.
	 *
	 * @return void
	 */
	public function testPollBesluitenMapsStatus(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-12'
		);

		$decisionBody = json_encode(['besluitStatus' => 'aangenomen', 'besluitDatum' => '2026-06-01']);
		$callLogEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => $decisionBody]],
			'call-log-5'
		);

		$this->callService
			->method('call')
			->willReturn($callLogEntity);

		$syncItems = [['caseId' => 'zaak-001', 'risMeetingId' => 'verg-001']];
		$result = $this->service->pollDecisions($source, $syncItems);

		$this->assertNotEmpty($result);
		$this->assertSame('Besluit: aangenomen', $result[0]['zaakStatus']);
		$this->assertSame('zaak-001', $result[0]['caseId']);

	}//end testPollBesluitenMapsStatus()

	/**
	 * Test retrieveBesluitenlijst fails without organisatieId.
	 *
	 * @return void
	 */
	public function testRetrieveBesluitenlijstNoOrganisatieId(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => []],
			'ibabs-source-13'
		);

		$result = $this->service->retrieveBesluitenlijst($source, 'verg-1', '2026-06-01', 'admin');

		$this->assertFalse($result['success']);
		$this->assertNull($result['filePath']);

	}//end testRetrieveBesluitenlijstNoOrganisatieId()

	/**
	 * Test retrieveBesluitenlijst returns pending when besluitenlijst not yet published.
	 *
	 * @return void
	 */
	public function testRetrieveBesluitenlijstNotYetPublished(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-14'
		);

		$callLogEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 404],
			'call-log-6'
		);

		$this->callService
			->method('call')
			->willReturn($callLogEntity);

		$result = $this->service->retrieveBesluitenlijst($source, 'verg-1', '2026-06-01', 'admin');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not yet', $result['message']);

	}//end testRetrieveBesluitenlijstNotYetPublished()

	/**
	 * Test retrieveBesluitenlijst stores file in correct path on success.
	 *
	 * @return void
	 */
	public function testRetrieveBesluitenlijstStoresFile(): void {
		$source = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['organisatieId' => 'org-123']],
			'ibabs-source-15'
		);

		$pdfContent = '%PDF-1.4 test';
		$callLogEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => $pdfContent]],
			'call-log-7'
		);

		$this->callService
			->method('call')
			->willReturn($callLogEntity);

		$mockFolder = $this->createMock(Folder::class);
		$mockUserFolder = $this->createMock(Folder::class);

		$mockUserFolder->method('nodeExists')->willReturn(false);
		$mockUserFolder->method('newFolder')->willReturn($mockFolder);
		$mockUserFolder->method('newFile')->willReturn($this->createMock(\OCP\Files\File::class));

		$this->rootFolder
			->method('getUserFolder')
			->willReturn($mockUserFolder);

		$result = $this->service->retrieveBesluitenlijst($source, 'verg-1', '2026-06-01', 'admin');

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('/RIS-besluiten/2026/2026-06-01', $result['filePath']);

	}//end testRetrieveBesluitenlijstStoresFile()

}//end class
