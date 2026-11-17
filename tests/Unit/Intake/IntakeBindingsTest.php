<?php

/**
 * Unit tests for the intake service bindings.
 *
 * The bindings are where an interface meets its implementation and where the
 * channel registry gets its adapters. The adapter ORDER is the collision
 * policy (the first adapter to claim a channel id keeps it), so it is pinned
 * here rather than left to discovery.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\Adapter\MessagingChannelAdapter;
use OCA\Integriq\Intake\Adapter\PublicSpaceReportAdapter;
use OCA\Integriq\Intake\Adapter\TeamsChannelAdapter;
use OCA\Integriq\Intake\IntakeAssignmentService;
use OCA\Integriq\Intake\IntakeAssignmentServiceInterface;
use OCA\Integriq\Intake\IntakeBindings;
use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Intake\IntakeChannelRegistryInterface;
use OCA\Integriq\Intake\IntakeReplyService;
use OCA\Integriq\Intake\IntakeReplyServiceInterface;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Intake\IntakeRoutingServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The aliases and the channel registry factory.
 *
 * @covers \OCA\Integriq\Intake\IntakeBindings
 */
class IntakeBindingsTest extends TestCase {

	/**
	 * Each intake interface is bound to its one implementation.
	 *
	 * @return void
	 */
	public function testEachInterfaceIsBoundToItsImplementation(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);
		$context->expects($this->once())->method('registerService')->with(IntakeChannelRegistry::class, $this->isInstanceOf(\Closure::class));

		(new IntakeBindings())->register($context);

		$this->assertSame(
			[
				IntakeRoutingServiceInterface::class => IntakeRoutingService::class,
				IntakeReplyServiceInterface::class => IntakeReplyService::class,
				IntakeAssignmentServiceInterface::class => IntakeAssignmentService::class,
				IntakeChannelRegistryInterface::class => IntakeChannelRegistry::class,
			],
			$aliases
		);
	}//end testEachInterfaceIsBoundToItsImplementation()

	/**
	 * The registry gets the four adapters in their declared order, so the form adapter wins a shared id.
	 *
	 * @return void
	 */
	public function testTheRegistryGetsTheAdaptersInTheirDeclaredOrder(): void {
		$factory = null;
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $callback) use (&$factory): void {
				$factory = $callback;
			}
		);
		(new IntakeBindings())->register($context);

		$form = $this->adapterMock(class: FormSubmissionAdapter::class, channelId: 'shared');
		$messaging = $this->adapterMock(class: MessagingChannelAdapter::class, channelId: 'shared');
		$report = $this->adapterMock(class: PublicSpaceReportAdapter::class, channelId: 'public-space-report');
		$teams = $this->adapterMock(class: TeamsChannelAdapter::class, channelId: 'teams');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$services = [
			'Psr\Log\LoggerInterface' => $logger,
			FormSubmissionAdapter::class => $form,
			MessagingChannelAdapter::class => $messaging,
			PublicSpaceReportAdapter::class => $report,
			TeamsChannelAdapter::class => $teams,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => $services[$id]);

		$registry = $factory($container);

		$this->assertInstanceOf(IntakeChannelRegistry::class, $registry);
		$this->assertSame($form, $registry->get('shared'));
		$this->assertSame($report, $registry->get('public-space-report'));
		$this->assertSame($teams, $registry->get('teams'));
	}//end testTheRegistryGetsTheAdaptersInTheirDeclaredOrder()

	/**
	 * An adapter double answering a channel id.
	 *
	 * @param class-string $class     The adapter class.
	 * @param string       $channelId The id it claims.
	 *
	 * @return object
	 */
	private function adapterMock(string $class, string $channelId): object {
		$adapter = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['getChannelId'])->getMock();
		$adapter->method('getChannelId')->willReturn($channelId);

		return $adapter;
	}//end adapterMock()
}//end class
