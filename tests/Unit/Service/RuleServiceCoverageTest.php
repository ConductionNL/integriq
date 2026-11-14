<?php

/**
 * Unit tests for RuleService's external-input extension: validation of the
 * fetched object, and a field holding a list of URLs.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\Plugin\EndpointRulePluginRegistryInterface;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * A rule that validates the objects it fetches, and a rule whose field holds
 * a list of URLs.
 *
 * @covers \OCA\Integriq\Service\RuleService
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */
class RuleServiceCoverageTest extends TestCase {

	/**
	 * @var MockObject
	 */
	private $orObjectService;

	/**
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * @var RuleService
	 */
	private RuleService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->callService = $this->createMock(CallService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->service = new RuleService(
			$this->objectService,
			$this->createMock(RegisterMapper::class),
			$this->schemaMapper,
			$this->callService,
			$this->orObjectService,
			$this->createMock(EndpointRulePluginRegistryInterface::class),
		);

	}//end setUp()

	/**
	 * A rule extending one property against schema 7.
	 *
	 * @param string $property The dotted property holding the URL(s).
	 * @param bool $validate Whether the fetched object is validated.
	 *
	 * @return ObjectEntity
	 */
	private function rule(string $property, bool $validate): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'configuration' => [
					'extend_external_input' => [
						'validate' => $validate,
						'properties' => [['property' => $property, 'schema' => 7]],
					],
				],
			],
			'rule-1'
		);

	}//end rule()

	/**
	 * A call log answering 200 with the given JSON body.
	 *
	 * @param array $body The decoded body.
	 *
	 * @return ObjectEntity
	 */
	private function answer(array $body): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200, 'response' => ['body' => json_encode($body)]], 'call-log');

	}//end answer()

	/**
	 * Wire an OpenRegister validate handler that answers the given verdict and records its arguments.
	 *
	 * The OpenRegister stub has no getValidateHandler(), so the facade returns a
	 * subclass of the stub that adds it, as the real ObjectService has it.
	 *
	 * @param bool $valid The verdict.
	 * @param stdClass $calls Receives the validateObject() arguments.
	 *
	 * @return void
	 */
	private function validatorAnswering(bool $valid, stdClass $calls): void {
		$result = new class($valid) {
			public function __construct(private readonly bool $valid) {
			}

			public function isValid(): bool {
				return $this->valid;
			}

			public function error(): mixed {
				return null;
			}
		};
		$handler = new class($result, $calls) {
			public function __construct(private readonly object $result, private readonly stdClass $calls) {
			}

			public function validateObject(array $object, mixed $schema): object {
				$this->calls->args[] = [$object, $schema];
				return $this->result;
			}
		};
		$openRegisters = new class($handler) extends ORObjectService {
			public function __construct(private readonly object $handler) {
			}

			public function getValidateHandler(): object {
				return $this->handler;
			}
		};

		$this->objectService->expects($this->once())->method('getOpenRegisters')->willReturn($openRegisters);

	}//end validatorAnswering()

	/**
	 * A fetched object that validates against the property's schema lands in the input.
	 *
	 * @return void
	 */
	public function testAValidatedObjectIsAdded(): void {
		$schema = new stdClass();
		$calls = new stdClass();
		$calls->args = [];
		$this->orObjectService->method('findAll')->willReturn(['results' => [ObjectServiceMockBuilder::objectEntity($this, [], 'src-1')]]);
		$this->callService->expects($this->once())->method('call')->willReturn($this->answer(['id' => 'zaak-1']));
		$this->schemaMapper->expects($this->once())->method('find')->with(7)->willReturn($schema);
		$this->validatorAnswering(valid: true, calls: $calls);

		$result = $this->service->extendExternalUrl(
			rule: $this->rule(property: 'zaak', validate: true),
			data: ['zaak' => 'https://api.example.test/zaken/1']
		);

		$this->assertSame(['zaak' => ['id' => 'zaak-1']], $result['extendedParameters']);
		$this->assertSame(['zaak' => ['id' => 'zaak-1']], $result['body']['_extendedInput']);
		$this->assertCount(1, $calls->args);
		$this->assertSame(['id' => 'zaak-1'], $calls->args[0][0]);
		$this->assertSame($schema, $calls->args[0][1]);
	}//end testAValidatedObjectIsAdded()

}//end class
