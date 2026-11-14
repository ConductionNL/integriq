<?php

/**
 * The exchange secret a consumer must present: legacy, brokered, and every way it resolves to nothing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-one-time-signed-subject-envelope-handoff
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\IdpConsumer;
use OCA\Integriq\Auth\Idp\IdpConsumerSecretResolver;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Auth\Idp\IdpConsumerSecretResolver
 * @uses   \OCA\Integriq\Auth\Idp\IdpConsumer
 */
class IdpConsumerSecretResolverTest extends TestCase {

	/**
	 * The calls the fake broker received.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * A resolver whose broker is the given object (or none), with a strict container and the given logger.
	 *
	 * @param object|null          $broker The broker.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return IdpConsumerSecretResolver
	 */
	private function resolver(?object $broker, ?LoggerInterface $logger = null): IdpConsumerSecretResolver {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		return new class($container, ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)), $broker) extends IdpConsumerSecretResolver {
			/**
			 * Constructor.
			 *
			 * @param ContainerInterface $container The container.
			 * @param LoggerInterface    $logger    The logger.
			 * @param object|null        $broker    The broker to hand out.
			 */
			public function __construct(ContainerInterface $container, LoggerInterface $logger, private readonly ?object $broker) {
				parent::__construct(container: $container, logger: $logger);
			}//end __construct()

			/**
			 * The broker given to the test.
			 *
			 * @return object|null
			 */
			protected function resolveBroker(): ?object {
				return $this->broker;
			}//end resolveBroker()
		};
	}//end resolver()

	/**
	 * A broker that records its calls and answers with the given secret.
	 *
	 * @param string $secret The secret.
	 *
	 * @return object
	 */
	private function broker(string $secret): object {
		$calls = &$this->calls;

		return new class($secret, $calls) {
			/**
			 * Constructor.
			 *
			 * @param string                       $secret The secret.
			 * @param array<int,array<int,mixed>> $calls  Where the calls go.
			 */
			public function __construct(private readonly string $secret, private array &$calls) {
			}//end __construct()

			/**
			 * Record a resolution and answer.
			 *
			 * @param string      $reference    The reference.
			 * @param string      $app          The app.
			 * @param mixed       $user         The user.
			 * @param string|null $organisation The organisation.
			 *
			 * @return string
			 */
			public function resolveInjectable(string $reference, string $app, mixed $user, ?string $organisation): string {
				$this->calls[] = [$reference, $app, $user, $organisation];
				return $this->secret;
			}//end resolveInjectable()
		};
	}//end broker()

	/**
	 * A disabled consumer has no secret, whatever it carries.
	 *
	 * @return void
	 */
	public function testADisabledConsumerHasNoSecret(): void {
		$consumer = new IdpConsumer(id: 'portal', enabled: false, secretRef: 'ref', legacySecret: 'inline');

		$this->assertSame(expected: '', actual: $this->resolver(broker: $this->broker(secret: 's'))->expectedSecret(consumer: $consumer));
		$this->assertSame(expected: [], actual: $this->calls);
	}//end testADisabledConsumerHasNoSecret()

	/**
	 * A legacy consumer uses its inline secret without asking the broker.
	 *
	 * @return void
	 */
	public function testALegacyConsumerUsesItsInlineSecret(): void {
		$consumer = IdpConsumer::fromConfig(id: 'portal', entry: 'inline-secret');

		$this->assertSame(expected: 'inline-secret', actual: $this->resolver(broker: $this->broker(secret: 's'))->expectedSecret(consumer: $consumer));
		$this->assertSame(expected: [], actual: $this->calls);
	}//end testALegacyConsumerUsesItsInlineSecret()

	/**
	 * A consumer without a secret reference has no secret.
	 *
	 * @return void
	 */
	public function testAConsumerWithoutAReferenceHasNoSecret(): void {
		$consumer = new IdpConsumer(id: 'portal', enabled: true, secretRef: '   ');

		$this->assertSame(expected: '', actual: $this->resolver(broker: $this->broker(secret: 's'))->expectedSecret(consumer: $consumer));
		$this->assertSame(expected: [], actual: $this->calls);
	}//end testAConsumerWithoutAReferenceHasNoSecret()

	/**
	 * The broker resolves the trimmed reference for this app, in the consumer's organisation, and the answer is trimmed.
	 *
	 * @return void
	 */
	public function testTheBrokerResolvesTheReference(): void {
		$consumer = new IdpConsumer(id: 'portal', enabled: true, secretRef: ' cred-1 ', secretOrganisation: ' org-9 ');

		$secret = $this->resolver(broker: $this->broker(secret: "  s3cret\n"))->expectedSecret(consumer: $consumer);

		$this->assertSame(expected: 's3cret', actual: $secret);
		$this->assertSame(expected: [['cred-1', 'openconnector', null, 'org-9']], actual: $this->calls);
	}//end testTheBrokerResolvesTheReference()

	/**
	 * Without an organisation the broker is asked with none.
	 *
	 * @return void
	 */
	public function testWithoutAnOrganisationTheBrokerIsAskedWithNone(): void {
		$consumer = new IdpConsumer(id: 'portal', enabled: true, secretRef: 'cred-1', secretOrganisation: '  ');

		$this->resolver(broker: $this->broker(secret: 's'))->expectedSecret(consumer: $consumer);

		$this->assertSame(expected: [['cred-1', 'openconnector', null, null]], actual: $this->calls);
	}//end testWithoutAnOrganisationTheBrokerIsAskedWithNone()

	/**
	 * Without a broker the consumer cannot redeem, and that is logged.
	 *
	 * @return void
	 */
	public function testWithoutABrokerThereIsNoSecret(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Integriq idp-broker: the credential broker is unavailable, so consumer portal cannot redeem.'
		);
		$consumer = new IdpConsumer(id: 'portal', enabled: true, secretRef: 'cred-1');

		$this->assertSame(expected: '', actual: $this->resolver(broker: null, logger: $logger)->expectedSecret(consumer: $consumer));
	}//end testWithoutABrokerThereIsNoSecret()

	/**
	 * A broker that cannot resolve injectables counts as no broker.
	 *
	 * @return void
	 */
	public function testABrokerWithoutResolveInjectableCountsAsNone(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$consumer = new IdpConsumer(id: 'portal', enabled: true, secretRef: 'cred-1');

		$this->assertSame(expected: '', actual: $this->resolver(broker: new \stdClass(), logger: $logger)->expectedSecret(consumer: $consumer));
	}//end testABrokerWithoutResolveInjectableCountsAsNone()

	/**
	 * A broker refusal yields no secret and is logged by exception class, not message.
	 *
	 * @return void
	 */
	public function testABrokerRefusalYieldsNoSecret(): void {
		$broker = new class {
			/**
			 * Refuse.
			 *
			 * @param mixed ...$arguments Ignored.
			 *
			 * @return string
			 */
			public function resolveInjectable(mixed ...$arguments): string {
				throw new RuntimeException('secret detail');
			}//end resolveInjectable()
		};
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Integriq idp-broker: the credential broker refused the exchange secret of consumer portal (RuntimeException).'
		);
		$consumer = new IdpConsumer(id: 'portal', enabled: true, secretRef: 'cred-1');

		$this->assertSame(expected: '', actual: $this->resolver(broker: $broker, logger: $logger)->expectedSecret(consumer: $consumer));
	}//end testABrokerRefusalYieldsNoSecret()

	/**
	 * The real lookup takes OpenRegister's credential broker from the container.
	 *
	 * @return void
	 */
	public function testTheRealLookupTakesTheBrokerFromTheContainer(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(IdpConsumerSecretResolver::BROKER_CLASS)
			->willReturn($this->broker(secret: 'from-container'));
		$resolver = new IdpConsumerSecretResolver(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertSame(
			expected: 'from-container',
			actual: $resolver->expectedSecret(consumer: new IdpConsumer(id: 'portal', enabled: true, secretRef: 'cred-1'))
		);
		$this->assertSame(expected: [['cred-1', 'openconnector', null, null]], actual: $this->calls);
	}//end testTheRealLookupTakesTheBrokerFromTheContainer()

	/**
	 * A container that cannot build the broker counts as no broker.
	 *
	 * @return void
	 */
	public function testAContainerFailureCountsAsNoBroker(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Integriq idp-broker: the credential broker is unavailable, so consumer portal cannot redeem.'
		);
		$resolver = new IdpConsumerSecretResolver(container: $container, logger: $logger);

		$this->assertSame(
			expected: '',
			actual: $resolver->expectedSecret(consumer: new IdpConsumer(id: 'portal', enabled: true, secretRef: 'cred-1'))
		);
	}//end testAContainerFailureCountsAsNoBroker()
}//end class
