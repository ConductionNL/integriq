<?php

/**
 * Integriq — how the PKIoverheid resolver finds the credential broker, and
 * what it refuses when the broker is missing or answers badly.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Digikoppeling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Digikoppeling;

use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Exception\DigikoppelingException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Every way the resolver fails closed, through its real broker lookup.
 *
 * The unit bootstrap always declares OpenRegister's broker class (as a
 * stub), so the lookup goes to the container here.
 *
 * @covers \OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver
 * @uses \OCA\Integriq\Exception\DigikoppelingException
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class PkiOverheidCredentialBrokerTest extends TestCase {

	/**
	 * The exception message of a resolve call that must fail.
	 *
	 * @param PkiOverheidCredentialResolver $resolver The resolver.
	 * @param string                        $ref      The certificateRef.
	 *
	 * @return string The message.
	 */
	private function refusal(PkiOverheidCredentialResolver $resolver, string $ref): string {
		try {
			$resolver->resolveSigningMaterial($ref);
		} catch (DigikoppelingException $e) {
			return $e->getMessage();
		}

		$this->fail('signing material was resolved');
	}//end refusal()

	/**
	 * A blank certificateRef is refused before the broker is looked up.
	 *
	 * @return void
	 */
	public function testABlankRefIsRefusedBeforeAnyLookup(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertSame(
			'Digikoppeling signing requires a PKIoverheid certificateRef — none is configured.',
			$this->refusal(new PkiOverheidCredentialResolver($container), "  \t")
		);
	}//end testABlankRefIsRefusedBeforeAnyLookup()

	/**
	 * A broker the container fails to build is treated as absent.
	 *
	 * @return void
	 */
	public function testABrokerTheContainerCannotBuildIsAbsent(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(PkiOverheidCredentialResolver::BROKER_CLASS)
			->willThrowException(new \RuntimeException('cannot build'));

		$this->assertStringStartsWith(
			'Digikoppeling signing requires the OpenRegister credential broker, which is not available.',
			$this->refusal(new PkiOverheidCredentialResolver($container), 'pkio-ref')
		);
	}//end testABrokerTheContainerCannotBuildIsAbsent()

	/**
	 * The broker the container builds is the one asked for material.
	 *
	 * @return void
	 */
	public function testTheContainerBrokerIssuesTheMaterial(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new AnsweringBroker(['certificatePem' => 'C', 'privateKeyPem' => 'K', 'extra' => 'dropped']));

		$this->assertSame(
			['certificatePem' => 'C', 'privateKeyPem' => 'K'],
			(new PkiOverheidCredentialResolver($container))->resolveSigningMaterial('pkio-ref')
		);
	}//end testTheContainerBrokerIssuesTheMaterial()

	/**
	 * OpenRegister's broker as it is today (proxy only) cannot sign in-process, and says so.
	 *
	 * @return void
	 */
	public function testTheProxyOnlyBrokerFromTheContainerIsRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->createMock(PkiOverheidCredentialResolver::BROKER_CLASS));

		$this->assertSame(
			'The credential broker cannot supply in-process PKIoverheid signing material for certificateRef "pkio-ref". '
			.'Digikoppeling WUS/ebMS2 message signing needs the private key in-process; '
			.'no plaintext-key-on-disk fallback is permitted (ADR-007). This capability is a documented '
			.'follow-on: the broker must expose a signing-material issuance path.',
			$this->refusal(new PkiOverheidCredentialResolver($container), 'pkio-ref')
		);
	}//end testTheProxyOnlyBrokerFromTheContainerIsRefused()

	/**
	 * Material without both PEMs, or not an array, is refused and names the ref.
	 *
	 * @return void
	 */
	public function testUnusableMaterialIsRefused(): void {
		foreach ([null, 'pem', [], ['certificatePem' => 'C'], ['privateKeyPem' => 'K'], ['certificatePem' => null, 'privateKeyPem' => 'K']] as $material) {
			$this->assertSame(
				'The credential broker returned no usable signing material for certificateRef "pkio-ref".',
				$this->refusal($this->withBroker(new AnsweringBroker($material)), 'pkio-ref')
			);
		}
	}//end testUnusableMaterialIsRefused()

	/**
	 * Scalar PEMs are handed on as strings.
	 *
	 * @return void
	 */
	public function testScalarPemsAreReturnedAsStrings(): void {
		$this->assertSame(
			['certificatePem' => '123', 'privateKeyPem' => '1'],
			$this->withBroker(new AnsweringBroker(['certificatePem' => 123, 'privateKeyPem' => true]))->resolveSigningMaterial('ref')
		);
	}//end testScalarPemsAreReturnedAsStrings()

	/**
	 * The broker is asked for exactly the ref given.
	 *
	 * @return void
	 */
	public function testTheBrokerIsAskedForTheGivenRef(): void {
		$broker = new AnsweringBroker(['certificatePem' => 'C', 'privateKeyPem' => 'K']);

		$this->withBroker($broker)->resolveSigningMaterial('pkio-ref-2');

		$this->assertSame(['pkio-ref-2'], $broker->asked);
	}//end testTheBrokerIsAskedForTheGivenRef()

	/**
	 * A resolver pinned to a broker, for the tests that are not about the lookup.
	 *
	 * @param object $broker The broker.
	 *
	 * @return PkiOverheidCredentialResolver The resolver.
	 */
	private function withBroker(object $broker): PkiOverheidCredentialResolver {
		return new class($this->createMock(ContainerInterface::class), $broker) extends PkiOverheidCredentialResolver {
			/**
			 * @param ContainerInterface $container The container.
			 * @param object             $broker    The broker.
			 */
			public function __construct(ContainerInterface $container, private readonly object $broker) {
				parent::__construct($container);
			}

			/**
			 * @return object|null The pinned broker.
			 */
			protected function resolveBroker(): ?object {
				return $this->broker;
			}
		};
	}//end withBroker()
}//end class

/**
 * A broker that answers fixed signing material and records what it was asked.
 */
class AnsweringBroker {

	/**
	 * The refs asked for.
	 *
	 * @var list<string>
	 */
	public array $asked = [];

	/**
	 * Constructor.
	 *
	 * @param mixed $material What issueSigningMaterial() answers.
	 */
	public function __construct(private readonly mixed $material) {
	}

	/**
	 * Issue the fixed material.
	 *
	 * @param string $ref The certificateRef.
	 *
	 * @return mixed The material.
	 */
	public function issueSigningMaterial(string $ref): mixed {
		$this->asked[] = $ref;
		return $this->material;
	}
}
