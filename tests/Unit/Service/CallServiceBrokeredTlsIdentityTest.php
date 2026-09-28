<?php

/**
 * CallService resolves a brokered TLS client identity before it writes the certificate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Integriq#2102: the source configuration (including `cert` / `ssl_key`) is
 * merged into the call configuration at Phase 7, before credentials are
 * hydrated, so a certificate kept in the broker must be resolved on the call
 * configuration as well, or Phase 9 would write a placeholder array as the
 * certificate. This drives the caller, `resolveCallCredentials()`.
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
 */
class CallServiceBrokeredTlsIdentityTest extends TestCase {

	/**
	 * The call configuration comes back with the certificate resolved.
	 *
	 * @return void
	 */
	public function testTheCallConfigurationCarriesTheResolvedCertificate(): void {
		$placeholder = ['credentialRef' => ['credentialId' => 'cert-uuid']];
		$config = ['cert' => $placeholder, 'headers' => ['Accept' => 'application/json']];

		$broker = $this->createMock(BrokeredCallService::class);
		$broker->method('hasCredentialRef')->willReturn(false);
		$broker->method('hasInjectableCredentials')->willReturn(false);
		$broker->method('hasInjectableTlsIdentity')->willReturn(true);
		$broker->expects($this->once())->method('hydrateInjectableTlsIdentity')
			->with($config)
			->willReturn(['cert' => 'PEM-CERT', 'headers' => ['Accept' => 'application/json']]);

		$service = (new ReflectionClass(CallService::class))->newInstanceWithoutConstructor();
		$property = new ReflectionProperty(CallService::class, 'brokeredCallService');
		$property->setAccessible(true);
		$property->setValue($service, $broker);

		$method = new ReflectionMethod(CallService::class, 'resolveCallCredentials');
		$method->setAccessible(true);

		$source = new ObjectEntity();
		$source->setObject(['configuration' => ['cert' => $placeholder]]);

		$result = $method->invoke($service, $source, $config, $source->getObject(), false, null);

		$this->assertNull($result['shortCircuit']);
		$this->assertSame('PEM-CERT', $result['config']['cert']);
	}//end testTheCallConfigurationCarriesTheResolvedCertificate()
}//end class
