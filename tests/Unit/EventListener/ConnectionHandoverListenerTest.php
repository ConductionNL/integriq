<?php

/**
 * Tests for ConnectionHandoverListener: which events it takes, how it reads them
 * and how it answers.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/source-credential-custody/spec.md#requirement-a-connection-handed-over-by-another-app-keeps-its-secrets-in-keepiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Event {

	use OCP\EventDispatcher\Event;

	if (class_exists(BrpConnectionHandoverEvent::class, false) === false) {
		/**
		 * Stand-in for the sender's event, with getters and an answer setter.
		 */
		class BrpConnectionHandoverEvent extends Event {

			/**
			 * The answer integriq gave.
			 *
			 * @var array<string, string>|null
			 */
			public ?array $received = null;

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $settings   The settings.
			 * @param string               $sourceSlug The source slug, '' for the default.
			 */
			public function __construct(private readonly array $settings, private readonly string $sourceSlug = '') {
				parent::__construct();

			}//end __construct()

			/**
			 * The settings.
			 *
			 * @return array<string, mixed>
			 */
			public function getSettings(): array {
				return $this->settings;

			}//end getSettings()

			/**
			 * The source slug.
			 *
			 * @return string
			 */
			public function getSourceSlug(): string {
				return $this->sourceSlug;

			}//end getSourceSlug()

			/**
			 * Receive the answer.
			 *
			 * @param array<string, string> $answer The answer.
			 *
			 * @return void
			 */
			public function setAnswer(array $answer): void {
				$this->received = $answer;

			}//end setAnswer()
		}//end class
	}//end if
}

namespace OCA\Integriq\Tests\Unit\EventListener {

	use OCA\Integriq\EventListener\ConnectionHandoverListener;
	use OCA\Integriq\Service\Security\ConnectionHandoverService;
	use OCA\Pipelinq\Event\BrpConnectionHandoverEvent;
	use OCP\EventDispatcher\Event;
	use PHPUnit\Framework\Attributes\CoversClass;
	use PHPUnit\Framework\MockObject\MockObject;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;
	use RuntimeException;

	/**
	 * Tests for the handover listener.
	 */
	#[CoversClass(ConnectionHandoverListener::class)]
	class ConnectionHandoverListenerTest extends TestCase {

		/**
		 * Service mock.
		 *
		 * @var ConnectionHandoverService&MockObject
		 */
		private ConnectionHandoverService&MockObject $service;

		/**
		 * Logger mock.
		 *
		 * @var LoggerInterface&MockObject
		 */
		private LoggerInterface&MockObject $logger;

		/**
		 * The listener under test.
		 *
		 * @var ConnectionHandoverListener
		 */
		private ConnectionHandoverListener $listener;

		/**
		 * Set up the listener with a container that yields the service mock.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			$this->service = $this->createMock(ConnectionHandoverService::class);
			$this->logger = $this->createMock(LoggerInterface::class);
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->with(ConnectionHandoverService::class)->willReturn($this->service);
			$this->listener = new ConnectionHandoverListener(container: $container, logger: $this->logger);

		}//end setUp()

		/**
		 * The handover fills the default source and the answer goes back on the event.
		 *
		 * @return void
		 */
		public function testAHandoverFillsTheDefaultSourceAndAnswers(): void {
			$settings = ['baseUrl' => 'https://brp.example.nl', 'clientSecret' => 'abc'];
			$this->service->expects(self::once())
				->method('takeOver')
				->with('brp-haalcentraal', $settings)
				->willReturn(['baseUrl' => 'taken', 'clientSecret' => 'taken']);
			$event = new BrpConnectionHandoverEvent(settings: $settings);

			$this->listener->handle($event);

			self::assertSame(['baseUrl' => 'taken', 'clientSecret' => 'taken'], $event->received);

		}//end testAHandoverFillsTheDefaultSourceAndAnswers()

		/**
		 * An event naming its own source fills that one.
		 *
		 * @return void
		 */
		public function testAnEventNamingItsSourceFillsThatSource(): void {
			$this->service->expects(self::once())
				->method('takeOver')
				->with('brp-test', ['clientId' => 'c'])
				->willReturn(['clientId' => 'taken']);

			$this->listener->handle(new BrpConnectionHandoverEvent(settings: ['clientId' => 'c'], sourceSlug: ' brp-test '));

		}//end testAnEventNamingItsSourceFillsThatSource()

		/**
		 * Another event is ignored.
		 *
		 * @return void
		 */
		public function testAnotherEventIsIgnored(): void {
			$this->service->expects(self::never())->method('takeOver');

			$this->listener->handle(new Event());

		}//end testAnotherEventIsIgnored()

		/**
		 * A service that throws answers every known given part as failed, and logs no value.
		 *
		 * @return void
		 */
		public function testAThrowingServiceAnswersEveryKnownPartAsFailed(): void {
			$this->service->method('takeOver')->willThrowException(new RuntimeException('boom secret-value'));
			$this->logger->expects(self::once())
				->method('error')
				->with(
					self::anything(),
					self::callback(static fn (array $context): bool => str_contains((string)json_encode($context), 'secret-value') === false)
				);
			$event = new BrpConnectionHandoverEvent(settings: ['clientSecret' => 'secret-value', 'privateKey' => 'k', 'other' => 'x']);

			$this->listener->handle($event);

			self::assertSame(['clientSecret' => 'failed', 'privateKey' => 'failed'], $event->received);

		}//end testAThrowingServiceAnswersEveryKnownPartAsFailed()
	}//end class
}
