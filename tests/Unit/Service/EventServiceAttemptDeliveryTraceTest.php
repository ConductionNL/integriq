<?php

/**
 * EventService::attemptDelivery() mints and persists the event trace of one
 * delivery attempt.
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
 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The trace wrapper around one delivery attempt.
 */
class EventServiceAttemptDeliveryTraceTest extends TestCase {

	/**
	 * Every persist call: entry point, entry point id, status.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $persisted = [];

	/**
	 * Every warning: message and context.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Build the service.
	 *
	 * @param bool $persistThrows Whether persisting the trace throws.
	 *
	 * @return EventService The service.
	 */
	private function service(bool $persistThrows): EventService {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(
			fn ($id, $register = null, $schema = null) => ObjectServiceMockBuilder::objectEntity($this, ['name' => 'job-' . $id], 'job-' . $id)
		);
		$objects->method('saveObject')->willReturnCallback(
			fn ($object, $register = null, $schema = null, $uuid = null) => ObjectServiceMockBuilder::objectEntity($this, (array)$object, (string)$uuid)
		);

		$traces = $this->createMock(ExecutionTraceService::class);
		$traces->method('persist')->willReturnCallback(
			function (ExecutionTraceContext $trace, string $status) use ($persistThrows) {
				$this->persisted[] = [$trace->getEntryPoint(), $trace->getEntryPointId(), $status];
				if ($persistThrows === true) {
					throw new RuntimeException('trace store down');
				}

				return new ObjectEntity();
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message, array $context = []) {
				$this->warnings[] = [$message, array_keys($context), ($context['exception'] ?? null)];
			}
		);

		$jobs = $this->createMock(JobService::class);
		$jobs->method('executeJob')->willReturn(null);

		return new EventService(
			$objects,
			$this->createMock(IClientService::class),
			$logger,
			$this->createMock(WebhookSignatureService::class),
			$this->createMock(SynchronizationService::class),
			$jobs,
			$this->createMock(CallService::class),
			$this->createMock(FlowRunnerService::class),
			null,
			null,
			null,
			$traces
		);

	}//end service()

	/**
	 * Attempt one delivery through the private entry point.
	 *
	 * @param EventService $service The service.
	 * @param string       $kind    The subscription's action kind.
	 *
	 * @return bool The attempt's outcome.
	 */
	private function attempt(EventService $service, string $kind): bool {
		$message      = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending', 'attempts' => []], 'msg-1');
		$subscription = ObjectServiceMockBuilder::objectEntity($this, ['action' => ['kind' => $kind, 'jobId' => 'j-1']], 'sub-1');
		$method       = new ReflectionMethod(EventService::class, 'attemptDelivery');
		return $method->invoke($service, $message, $subscription);

	}//end attempt()

	/**
	 * A successful attempt persists an `event` trace for the message as success.
	 *
	 * @return void
	 */
	public function testASuccessfulAttemptPersistsASuccessTrace(): void {
		$this->assertTrue($this->attempt($this->service(false), 'job'));
		$this->assertSame([['event', 'msg-1', 'success']], $this->persisted);
		$this->assertSame([], $this->warnings);

	}//end testASuccessfulAttemptPersistsASuccessTrace()

	/**
	 * A failed attempt persists the trace as failed.
	 *
	 * @return void
	 */
	public function testAFailedAttemptPersistsAFailedTrace(): void {
		$this->assertFalse($this->attempt($this->service(false), 'no-such-kind'));
		$this->assertSame([['event', 'msg-1', 'failed']], $this->persisted);

	}//end testAFailedAttemptPersistsAFailedTrace()

	/**
	 * A trace that cannot be persisted is logged and leaves the outcome alone.
	 *
	 * @return void
	 */
	public function testATraceThatCannotBePersistedIsOnlyLogged(): void {
		$this->assertTrue($this->attempt($this->service(true), 'job'));
		$this->assertSame(
			[['EventService: failed to persist execution_trace.', ['traceId', 'exception'], 'trace store down']],
			$this->warnings
		);

	}//end testATraceThatCannotBePersistedIsOnlyLogged()
}//end class
