<?php

/**
 * Unit tests for IdpConsumerSecretResolver: which exchange secret a consumer must present.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\IdpConsumer;
use OCA\Integriq\Auth\Idp\IdpConsumerSecretResolver;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Tests IdpConsumerSecretResolver.
 *
 * @covers \OCA\Integriq\Auth\Idp\IdpConsumerSecretResolver
 * @uses \OCA\Integriq\Auth\Idp\IdpConsumer
 */
class IdpConsumerSecretResolverTest extends TestCase {

	/**
	 * The mocked container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The resolver under test.
	 *
	 * @var IdpConsumerSecretResolver
	 */
	private IdpConsumerSecretResolver $resolver;

	/**
	 * Set up the resolver with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->resolver = new IdpConsumerSecretResolver(container: $this->container, logger: $this->logger);
	}

	/**
	 * A brokered consumer.
	 *
	 * @param string $organisation The secret's organisation.
	 *
	 * @return IdpConsumer The consumer.
	 */
	private function brokered(string $organisation = ''): IdpConsumer {
		return new IdpConsumer(id: 'portaliq', enabled: true, secretRef: ' cred-1 ', secretOrganisation: $organisation);
	}

	/**
	 * A disabled consumer has no secret, and the broker is not asked.
	 *
	 * @return void
	 */
	public function testADisabledConsumerHasNoSecret(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertSame('', $this->resolver->expectedSecret(new IdpConsumer(id: 'x', enabled: false, secretRef: 'cred-1')));
		$this->assertSame('', $this->resolver->expectedSecret(IdpConsumer::fromConfig('x', '')));
	}

	/**
	 * A legacy consumer's inline secret is used as-is, without the broker.
	 *
	 * @return void
	 */
	public function testALegacyConsumerUsesItsInlineSecret(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertSame('inline-secret', $this->resolver->expectedSecret(IdpConsumer::fromConfig('x', 'inline-secret')));
	}

	/**
	 * A brokered consumer without a reference has no secret.
	 *
	 * @return void
	 */
	public function testABlankReferenceHasNoSecret(): void {
		$this->container->expects($this->never())->method('get');

		$this->assertSame('', $this->resolver->expectedSecret(new IdpConsumer(id: 'x', enabled: true, secretRef: '   ')));
	}

	/**
	 * When the broker cannot be had, or lacks the method, the consumer cannot
	 * redeem and that is logged.
	 *
	 * @return void
	 */
	public function testAnUnavailableBrokerYieldsNoSecret(): void {
		$this->container->method('get')->with(IdpConsumerSecretResolver::BROKER_CLASS)
			->willReturnOnConsecutiveCalls(
				$this->throwException(new RuntimeException('not registered')),
				new stdClass()
			);
		$this->logger->expects($this->exactly(2))->method('warning')
			->with('Integriq idp-broker: the credential broker is unavailable, so consumer portaliq cannot redeem.');

		$this->assertSame('', $this->resolver->expectedSecret($this->brokered()));
		$this->assertSame('', $this->resolver->expectedSecret($this->brokered()));
	}

	/**
	 * The broker resolves the trimmed reference for this app, with no acting
	 * user and the trimmed organisation, and the secret comes back trimmed.
	 *
	 * @return void
	 */
	public function testTheBrokerResolvesTheReference(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->expects($this->once())->method('resolveInjectable')
			->with('cred-1', 'openconnector', $this->identicalTo(null), 'org-7')
			->willReturn("  s3cret \n");
		$this->container->method('get')->willReturn($broker);

		$this->assertSame('s3cret', $this->resolver->expectedSecret($this->brokered(organisation: ' org-7 ')));
	}

	/**
	 * A blank organisation is passed as null, and a null secret is empty.
	 *
	 * @return void
	 */
	public function testABlankOrganisationIsPassedAsNull(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->expects($this->once())->method('resolveInjectable')
			->with('cred-1', 'openconnector', $this->identicalTo(null), $this->identicalTo(null))
			->willReturn(null);
		$this->container->method('get')->willReturn($broker);

		$this->assertSame('', $this->resolver->expectedSecret($this->brokered(organisation: '  ')));
	}

	/**
	 * A broker refusal is logged by exception class only and yields no secret.
	 *
	 * @return void
	 */
	public function testABrokerRefusalYieldsNoSecret(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('resolveInjectable')->willThrowException(new RuntimeException('secret detail'));
		$this->container->method('get')->willReturn($broker);
		$this->logger->expects($this->once())->method('warning')
			->with('Integriq idp-broker: the credential broker refused the exchange secret of consumer portaliq (RuntimeException).');

		$this->assertSame('', $this->resolver->expectedSecret($this->brokered()));
	}
}
