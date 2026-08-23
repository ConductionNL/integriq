<?php

/**
 * Integriq — PKIoverheid credential resolver tests.
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
 * A broker that lacks a signing-material capability (constrained proxy).
 */
class ProxyOnlyBroker {
	/**
	 * The only capability of the constrained-proxy broker.
	 *
	 * @return array{status:int,headers:array,body:string}
	 */
	public function request(): array {
		return ['status' => 200, 'headers' => [], 'body' => ''];
	}
}

/**
 * A broker that can issue in-process signing material.
 */
class MaterialIssuingBroker {
	/**
	 * Issue signing material for a certificateRef.
	 *
	 * @param string $ref The certificateRef.
	 *
	 * @return array{certificatePem:string,privateKeyPem:string}
	 */
	public function issueSigningMaterial(string $ref): array {
		return ['certificatePem' => 'CERT(' . $ref . ')', 'privateKeyPem' => 'KEY(' . $ref . ')'];
	}
}

/**
 * Tests for PKIoverheid signing-material resolution + fail-closed (REQ-DK-005).
 *
 * @spec openspec/specs/digikoppeling-adapter/spec.md
 */
class PkiOverheidCredentialResolverTest extends TestCase {

	/**
	 * Build a resolver whose container returns the given broker (or throws).
	 *
	 * @param object|null $broker The broker instance, or null to simulate absence.
	 *
	 * @return PkiOverheidCredentialResolver
	 */
	private function makeResolver(?object $broker): PkiOverheidCredentialResolver {
		$container = $this->createMock(ContainerInterface::class);
		if ($broker === null) {
			$container->method('get')->willThrowException(new \RuntimeException('no broker'));
		} else {
			$container->method('get')->willReturn($broker);
		}

		// The resolver only resolves the real broker FQCN when it class_exists;
		// in the unit environment it does not, so override resolveBroker() to
		// pin our test double.
		return new class($container, $broker) extends PkiOverheidCredentialResolver {
			private ?object $testBroker;

			public function __construct(ContainerInterface $container, ?object $broker) {
				parent::__construct($container);
				$this->testBroker = $broker;
			}

			protected function resolveBroker(): ?object {
				return $this->testBroker;
			}
		};
	}//end makeResolver()

	/**
	 * An empty certificateRef fails closed.
	 *
	 * @return void
	 */
	public function testEmptyRefFailsClosed(): void {
		$this->expectException(DigikoppelingException::class);
		$this->makeResolver(new MaterialIssuingBroker())->resolveSigningMaterial('');
	}//end testEmptyRefFailsClosed()

	/**
	 * A constrained-proxy broker (no signing-material capability) fails closed —
	 * never a plaintext fallback.
	 *
	 * @return void
	 */
	public function testProxyOnlyBrokerFailsClosed(): void {
		$this->expectException(DigikoppelingException::class);
		$this->expectExceptionMessageMatches('/signing material|no plaintext|fail/i');
		$this->makeResolver(new ProxyOnlyBroker())->resolveSigningMaterial('pkio-ref');
	}//end testProxyOnlyBrokerFailsClosed()

	/**
	 * When the broker can issue signing material, it is returned for signing.
	 *
	 * @return void
	 */
	public function testMaterialIssuingBrokerReturnsMaterial(): void {
		$material = $this->makeResolver(new MaterialIssuingBroker())->resolveSigningMaterial('pkio-ref');

		$this->assertSame('CERT(pkio-ref)', $material['certificatePem']);
		$this->assertSame('KEY(pkio-ref)', $material['privateKeyPem']);
	}//end testMaterialIssuingBrokerReturnsMaterial()
}//end class
