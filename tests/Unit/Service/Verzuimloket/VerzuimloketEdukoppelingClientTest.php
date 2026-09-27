<?php

/**
 * Unit tests for VerzuimloketEdukoppelingClient.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Verzuimloket
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Verzuimloket;

use GuzzleHttp\Client;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketEdukoppelingClient;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Edukoppeling Verzuimloket provider. The happy-path signed
 * dispatch is NOT tested here — resolveSigningMaterial() fails closed for
 * every certificateRef until OpenRegister's credential broker ships
 * issueSigningMaterial (see class docblock).
 *
 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
 */
class VerzuimloketEdukoppelingClientTest extends TestCase {

	/**
	 * @var PkiOverheidCredentialResolver|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $credentialResolver;

	/**
	 * @var WusProfileService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $wusProfileService;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->credentialResolver = $this->createMock(PkiOverheidCredentialResolver::class);
		$this->wusProfileService = $this->createMock(WusProfileService::class);

		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);

		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build a client under test.
	 *
	 * @return VerzuimloketEdukoppelingClient The client under test.
	 */
	private function buildClient(): VerzuimloketEdukoppelingClient {
		return new VerzuimloketEdukoppelingClient(
			new Client(),
			$this->credentialResolver,
			$this->wusProfileService,
			$this->l,
			$this->logger
		);
	}//end buildClient()

	/**
	 * getProviderId() returns "edukoppeling".
	 *
	 * @return void
	 */
	public function testGetProviderIdReturnsEdukoppeling(): void {
		$this->assertSame('edukoppeling', $this->buildClient()->getProviderId());

	}//end testGetProviderIdReturnsEdukoppeling()

	/**
	 * send() refuses closed, naming the missing certificate reference, when
	 * the credential broker cannot issue signing material.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-verzuimloket/specs/verzuimloket-adapter/spec.md#scenario-the-edukoppeling-provider-refuses-closed-without-a-certificate-reference
	 */
	public function testSendRefusesClosedWhenSigningMaterialUnresolvable(): void {
		$this->credentialResolver->method('resolveSigningMaterial')
			->willThrowException(new DigikoppelingException('DUO Verzuimloket signing requires a PKIoverheid certificateRef — none is configured.'));

		$this->wusProfileService->expects($this->never())->method('buildSignedRequest');

		$this->expectException(VerzuimloketProviderException::class);
		$this->expectExceptionMessage('DUO Verzuimloket send refused');

		$this->buildClient()->send(
			['endpoint' => 'https://verzuimloket.duo.example.nl/berichten'],
			'eerste-melding',
			'kenmerk-1',
			'<VerzuimloketMelding/>'
		);

	}//end testSendRefusesClosedWhenSigningMaterialUnresolvable()
}//end class
