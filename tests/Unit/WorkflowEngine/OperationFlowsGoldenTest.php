<?php

/**
 * Golden tests for the flow loop of the two WorkflowEngine operations.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\WorkflowEngine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\WorkflowEngine;

use OCA\Integriq\Service\EndpointServiceInterface;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\WorkflowEngine\CallEndpointOperation;
use OCA\Integriq\WorkflowEngine\FireCloudEventOperation;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\Response;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IRuleMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins that each matched flow runs on its own: a failing one is logged and
 * the next still runs, a skipped one is skipped, and what each one sends.
 */
class OperationFlowsGoldenTest extends TestCase {

	/**
	 * What happened, in order.
	 *
	 * @var array<int, array>
	 */
	private array $seen = [];

	/**
	 * A rule matcher over the given operations.
	 *
	 * @param array<int, string> $operations The flows' operation JSON.
	 *
	 * @return IRuleMatcher The matcher.
	 */
	private function matcher(array $operations): IRuleMatcher {
		$matcher = $this->createMock(IRuleMatcher::class);
		$matcher->method('getFlows')->with(false)->willReturn(array_map(static fn (string $operation): array => ['operation' => $operation], $operations));
		return $matcher;

	}//end matcher()

	/**
	 * A logger that records its warnings.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->seen[] = ['warning', $message, array_keys($context), ($context['eventName'] ?? null)];
			}
		);
		return $logger;

	}//end logger()

	/**
	 * The l10n.
	 *
	 * @return IL10N The l10n.
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return $l10n;

	}//end l10n()

	/**
	 * Call endpoint: a throwing flow, a missing endpoint, a malformed one and a good one.
	 *
	 * @return void
	 */
	public function testCallEndpointFlows(): void {
		$this->seen = [];
		$endpoint   = $this->createMock(ObjectEntity::class);
		$service    = $this->createMock(EndpointServiceInterface::class);
		$service->method('getEndpointById')->willReturnCallback(
			function (string $id) use ($endpoint): ?ObjectEntity {
				$this->seen[] = ['get', $id];
				if ($id === 'boom') {
					throw new RuntimeException('lookup failed');
				}

				return ($id === 'gone') ? null : $endpoint;
			}
		);
		$service->method('triggerFromFlow')->willReturnCallback(
			function ($given, array $parameters): Response {
				$this->seen[] = ['trigger', $parameters];
				return new Response();
			}
		);

		$operation = new CallEndpointOperation($service, $this->l10n(), $this->createMock(IURLGenerator::class), $this->logger());
		$operation->onEvent(
			'evt',
			new Event(),
			$this->matcher(['{"endpointId":"boom"}', '{"endpointId":"gone"}', 'not json', '{"endpointId":"ok","parameters":"text"}', '{"endpointId":"ok","parameters":{"a":1}}'])
		);

		$this->assertSame(
			[
				['get', 'boom'],
				['warning', 'WorkflowEngine "Call endpoint" operation dispatch failed: lookup failed', ['exception', 'eventName'], 'evt'],
				['get', 'gone'],
				['warning', 'WorkflowEngine "Call endpoint" operation could not resolve endpoint: gone', ['eventName'], 'evt'],
				['get', 'ok'],
				['trigger', []],
				['get', 'ok'],
				['trigger', ['a' => 1]],
			],
			$this->seen
		);

	}//end testCallEndpointFlows()

	/**
	 * Fire CloudEvent: a throwing flow, a malformed one and two good ones.
	 *
	 * @return void
	 */
	public function testFireCloudEventFlows(): void {
		$this->seen = [];
		$events     = $this->createMock(EventServiceInterface::class);
		$events->method('emitCloudEvent')->willReturnCallback(
			function (string $type, string $source, ?string $subject=null, array $data=[]): array {
				$this->seen[] = ['emit', $type, $source, $subject, $data];
				if ($type === 'boom') {
					throw new RuntimeException('bus down');
				}

				return [];
			}
		);

		$operation = new FireCloudEventOperation($events, $this->l10n(), $this->createMock(IURLGenerator::class), $this->logger());
		$operation->onEvent(
			'evt',
			new Event(),
			$this->matcher(['{"type":"boom","source":"s"}', 'not json', '{"type":"t","source":"s","data":"text"}', '{"type":"t","source":"s","subject":"x","data":{"eventName":"own","k":2}}'])
		);

		$this->assertSame(
			[
				['emit', 'boom', 's', null, ['eventName' => 'evt']],
				['warning', 'WorkflowEngine "Fire CloudEvent" operation dispatch failed: bus down', ['exception', 'eventName'], 'evt'],
				['emit', 't', 's', null, ['eventName' => 'evt']],
				['emit', 't', 's', 'x', ['eventName' => 'own', 'k' => 2]],
			],
			$this->seen
		);

	}//end testFireCloudEventFlows()
}//end class
