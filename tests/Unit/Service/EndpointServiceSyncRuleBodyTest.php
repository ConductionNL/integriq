<?php

/**
 * A synchronization rule on a request body that is not an object.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * An earlier rule can leave the body as a string. A JSON object string is
 * synchronized as that object; any other string is answered with a 400
 * instead of a TypeError escaping the rule pipeline.
 */
class EndpointServiceSyncRuleBodyTest extends TestCase {

	/**
	 * Synchronization service double.
	 *
	 * @var SynchronizationService&MockObject
	 */
	private SynchronizationService $sync;

	/**
	 * The engine with a recording synchronization service.
	 *
	 * @return EndpointService
	 */
	private function service(): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);
		$this->sync = $this->createMock(SynchronizationService::class);
		$synchronization = ObjectServiceMockBuilder::objectEntity($this, ['sourceConfig' => []], 'sync-uuid');
		$synchronization->setName('Sync');
		$this->sync->method('getSynchronization')->willReturn($synchronization);

		$orObjectService = ObjectServiceMockBuilder::make($this);

		return new EndpointService(
			$this->createMock(ObjectService::class),
			$this->createMock(CallService::class),
			$logger,
			$this->createMock(IURLGenerator::class),
			$this->createMock(MappingService::class),
			$orObjectService,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$this->sync,
			$this->createMock(RuleService::class),
			new WebhookSignatureService($logger),
			$this->createMock(InboundRateLimitService::class),
			new CompositeFanoutRule($orObjectService, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$this->createMock(ConsumerScopeService::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(FileService::class),
		);
	}//end service()

	/**
	 * Run the synchronization rule on the given body.
	 *
	 * @param EndpointService $service The engine.
	 * @param mixed           $body    The request body.
	 *
	 * @return mixed
	 */
	private function runRule(EndpointService $service, mixed $body): mixed {
		$rule = ObjectServiceMockBuilder::objectEntity($this, ['configuration' => ['synchronization' => 'sync-ref']], 'rule-1');
		$method = new ReflectionMethod(EndpointService::class, 'processSyncRule');

		return $method->invoke($service, $rule, ['body' => $body], new FlowToken());
	}//end runRule()

	/**
	 * A JSON object string is synchronized as the object, flags included.
	 *
	 * @return void
	 */
	public function testAJsonObjectStringIsSynchronizedAsTheObject(): void {
		$service = $this->service();
		$this->sync->expects($this->once())->method('synchronize')
			->with($this->anything(), true, false, $this->anything(), $this->anything(), $this->anything(), ['naam' => 'x', 'isTest' => true])
			->willReturn(['result' => []]);

		$result = $this->runRule($service, '{"naam":"x","isTest":true}');

		$this->assertSame(['body' => ['result' => []]], $result);
	}//end testAJsonObjectStringIsSynchronizedAsTheObject()

	/**
	 * Any other string is answered with a 400 and synchronizes nothing.
	 *
	 * @return void
	 */
	public function testAStringThatIsNoJsonObjectIsABadRequest(): void {
		foreach (['plain text', '"quoted"', '42', ''] as $body) {
			$service = $this->service();
			$this->sync->expects($this->never())->method('synchronize');
			$this->sync->expects($this->never())->method('getSynchronization');

			$result = $this->runRule($service, $body);

			$this->assertInstanceOf(JSONResponse::class, $result, $body);
			$this->assertSame(400, $result->getStatus());
			$this->assertSame(['error' => 'A synchronization rule needs the request body to be an object'], $result->getData());
		}
	}//end testAStringThatIsNoJsonObjectIsABadRequest()

	/**
	 * An array body and an absent body are synchronized as before.
	 *
	 * @return void
	 */
	public function testArrayAndAbsentBodiesAreUnchanged(): void {
		$service = $this->service();
		$this->sync->expects($this->exactly(2))->method('synchronize')->willReturn(['result' => []]);

		$this->assertSame(['body' => ['result' => []]], $this->runRule($service, ['naam' => 'x']));
		$this->assertSame(['body' => ['result' => []]], $this->runRule($service, null));
	}//end testArrayAndAbsentBodiesAreUnchanged()
}//end class
