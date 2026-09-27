<?php

/**
 * Unit tests for OsoKennisnetClient.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Oso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-oso/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Oso;

use GuzzleHttp\Client;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Service\Oso\OsoKennisnetClient;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Kennisnet OSO export provider. The happy-path signed
 * dispatch is NOT tested here — resolveSigningMaterial() fails closed for
 * every certificateRef until OpenRegister's credential broker ships
 * issueSigningMaterial.
 *
 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
 */
class OsoKennisnetClientTest extends TestCase {

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
	 * @return OsoKennisnetClient The client under test.
	 */
	private function buildClient(): OsoKennisnetClient {
		return new OsoKennisnetClient(
			new Client(),
			$this->credentialResolver,
			$this->wusProfileService,
			$this->l,
			$this->logger
		);
	}//end buildClient()

	/**
	 * getProviderId() returns "kennisnet".
	 *
	 * @return void
	 */
	public function testGetProviderIdReturnsKennisnet(): void {
		$this->assertSame('kennisnet', $this->buildClient()->getProviderId());

	}//end testGetProviderIdReturnsKennisnet()

	/**
	 * sendExport() refuses closed, naming the missing certificate reference,
	 * when the credential broker cannot issue signing material.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-oso/specs/oso-adapter/spec.md#scenario-the-kennisnet-provider-refuses-closed-without-a-certificate-reference
	 */
	public function testSendExportRefusesClosedWhenSigningMaterialUnresolvable(): void {
		$this->credentialResolver->method('resolveSigningMaterial')
			->willThrowException(new DigikoppelingException('OSO export signing requires a PKIoverheid certificateRef — none is configured.'));

		$this->wusProfileService->expects($this->never())->method('buildSignedRequest');

		$this->expectException(OsoProviderException::class);
		$this->expectExceptionMessage('OSO export refused');

		$this->buildClient()->sendExport(
			['endpoint' => 'https://oso.kennisnet.example.nl/export'],
			'kenmerk-1',
			'<OsoOverstapdossier/>'
		);

	}//end testSendExportRefusesClosedWhenSigningMaterialUnresolvable()
}//end class
