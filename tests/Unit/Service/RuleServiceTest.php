<?php

/**
 * Unit tests for RuleService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\SoftwareCatalogueService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the rule processing service (OR cutover — no deleted Db types).
 */
class RuleServiceTest extends TestCase {

	/**
	 * @var RuleService
	 */
	private RuleService $service;

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->logger = $this->createMock(LoggerInterface::class);

		$objectService = $this->createMock(ObjectService::class);
		$catalogueService = $this->createMock(SoftwareCatalogueService::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$callService = $this->createMock(CallService::class);

		// RuleService constructor signature (6 args, no $logger):
		//   objectService, catalogueService, registerMapper, schemaMapper,
		//   callService, orObjectService.
		// The previous version prepended $this->logger which pushed every
		// dependency one slot to the right (objectService got the logger
		// instance and crashed). Pre-existing test bug surfaced once #1015
		// unblocked the suite from crashing in setUp.
		unset($this->logger);
		$this->service = new RuleService(
			$objectService,
			$catalogueService,
			$registerMapper,
			$schemaMapper,
			$callService,
			$this->orObjectService,
		);
	}//end setUp()

	/**
	 * Test that the constructor instantiates RuleService without errors.
	 *
	 * @return void
	 */
	public function testConstructorWiresDependencies(): void {
		$this->assertInstanceOf(RuleService::class, $this->service);
	}//end testConstructorWiresDependencies()

	/**
	 * Test that processCustomRule throws for an unknown rule type.
	 *
	 * @return void
	 */
	public function testProcessCustomRuleThrowsForUnknownType(): void {
		// Arrange
		$ruleEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['type' => 'unknownType']],
			'rule-uuid-1'
		);

		// Assert
		$this->expectException(Exception::class);

		// Act
		$this->service->processCustomRule($ruleEntity, ['someData' => 'value']);
	}//end testProcessCustomRuleThrowsForUnknownType()

	/**
	 * Test that processCustomRule throws when configuration key is absent.
	 *
	 * @return void
	 */
	public function testProcessCustomRuleThrowsWhenConfigurationAbsent(): void {
		// Arrange
		$ruleEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			[],
			'rule-uuid-2'
		);

		// Assert
		$this->expectException(Exception::class);

		// Act
		$this->service->processCustomRule($ruleEntity, []);
	}//end testProcessCustomRuleThrowsWhenConfigurationAbsent()

	/**
	 * Test that processCustomRule accepts a connectRelations type without throwing (delegates to sub-method).
	 *
	 * The sub-method will throw on incomplete config, but we only need to
	 * confirm the dispatch routing does not itself throw an "Unsupported" error.
	 *
	 * @return void
	 */
	public function testProcessCustomRuleDispatchesConnectRelationsType(): void {
		// Arrange
		$ruleEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['configuration' => ['type' => 'connectRelations', 'configuration' => ['register' => 'r', 'schema' => 's', 'relatedRegister' => 'rr', 'relatedSchema' => 'rs', 'selfField' => 'sf', 'relatedField' => 'rf']]],
			'rule-uuid-3'
		);

		$this->orObjectService->method('findAll')
			->willReturn(['results' => [], 'total' => 0]);

		// Act — expect it runs without "Unsupported" exception
		try {
			$result = $this->service->processCustomRule($ruleEntity, ['items' => []]);
			$this->assertIsArray($result);
		} catch (Exception $e) {
			// Any exception other than "Unsupported" is acceptable here.
			$this->assertStringNotContainsString('Unsupported', $e->getMessage());
		}
	}//end testProcessCustomRuleDispatchesConnectRelationsType()

}//end class
