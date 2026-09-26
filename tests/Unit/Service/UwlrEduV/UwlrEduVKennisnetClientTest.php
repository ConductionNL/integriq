<?php

/**
 * Unit tests for UwlrEduVKennisnetClient.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\UwlrEduV;

use GuzzleHttp\Client;
use OCA\Integriq\Adapters\Digikoppeling\PkiOverheidCredentialResolver;
use OCA\Integriq\Adapters\Digikoppeling\WusProfileService;
use OCA\Integriq\Exception\DigikoppelingException;
use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVKennisnetClient;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Kennisnet-adjacent UWLR/Edu-V/Basispoort/Entree-content
 * provider. The happy-path signed dispatch is NOT tested here —
 * resolveSigningMaterial() fails closed for every certificateRef until
 * OpenRegister's credential broker ships issueSigningMaterial.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
 */
class UwlrEduVKennisnetClientTest extends TestCase {

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
	 * @return UwlrEduVKennisnetClient The client under test.
	 */
	private function buildClient(): UwlrEduVKennisnetClient {
		return new UwlrEduVKennisnetClient(
			new Client(),
			$this->credentialResolver,
			$this->wusProfileService,
			$this->l,
			$this->logger
		);
	}//end buildClient()

	/**
	 * getProviderId() returns "uwlr-eduv".
	 *
	 * @return void
	 */
	public function testGetProviderIdReturnsUwlrEduv(): void {
		$this->assertSame('uwlr-eduv', $this->buildClient()->getProviderId());

	}//end testGetProviderIdReturnsUwlrEduv()

	/**
	 * send() refuses closed, naming the missing certificate reference,
	 * when the credential broker cannot issue signing material.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-the-uwlr-eduv-provider-refuses-closed-without-a-certificate-reference
	 */
	public function testSendRefusesClosedWhenSigningMaterialUnresolvable(): void {
		$this->credentialResolver->method('resolveSigningMaterial')
			->willThrowException(new DigikoppelingException('UWLR/Edu-V send signing requires a PKIoverheid certificateRef — none is configured.'));

		$this->wusProfileService->expects($this->never())->method('buildSignedRequest');

		$this->expectException(UwlrEduVProviderException::class);
		$this->expectExceptionMessage('UWLR/Edu-V send refused');

		$this->buildClient()->send(
			['endpoint' => 'https://uwlr-eduv.kennisnet.example.nl'],
			'uwlr',
			'kenmerk-1',
			'<UwlrExport/>'
		);

	}//end testSendRefusesClosedWhenSigningMaterialUnresolvable()
}//end class
