<?php

/**
 * Unit tests for RestPeppolAccessPointProvider.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Peppol
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/peppol-access-point-connector/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Peppol;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\BrokeredCallConfigurationException;
use OCA\Integriq\Exception\PeppolProviderException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\Peppol\RestPeppolAccessPointProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic REST Peppol Access Point provider (brokered dispatch).
 *
 * @spec openspec/changes/peppol-access-point-connector/specs/peppol-access-point-connector/spec.md#scenario-the-rest-provider-brokers-its-api-key
 */
class RestPeppolAccessPointProviderTest extends TestCase {

	/**
	 * @var BrokeredCallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $brokeredCallService;

	/**
	 * @var IL10N|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $l;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var RestPeppolAccessPointProvider
	 */
	private RestPeppolAccessPointProvider $provider;

	/**
	 * The valid configuration used by every "happy path" test.
	 *
	 * @var array
	 */
	private array $configuration = [
		'baseUrl' => 'https://ap.example/peppol',
		'authentication' => ['credentialRef' => ['credentialId' => 'cred-uuid']],
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->brokeredCallService = $this->createMock(BrokeredCallService::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->provider = new RestPeppolAccessPointProvider(
			$this->brokeredCallService,
			$this->l,
			$this->logger
		);

	}//end setUp()

	/**
	 * A source with no credentialRef fails closed before any dispatch — REQ-006.
	 *
	 * @return void
	 */
	public function testSubmitDocumentWithoutCredentialRefFailsClosed(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(false);
		$this->brokeredCallService->expects($this->never())->method('dispatch');

		$this->expectException(PeppolProviderException::class);

		$this->provider->submitDocument(
			sourceConfiguration: ['baseUrl' => 'https://ap.example/peppol'],
			recipientPeppolId: '0192:1234567890',
			documentType: 'ubl-invoice-2.1',
			payload: 'https://example.com/invoice.xml'
		);

	}//end testSubmitDocumentWithoutCredentialRefFailsClosed()

	/**
	 * A brokered configuration error is mapped to a secret-free PeppolProviderException.
	 *
	 * @return void
	 */
	public function testSubmitDocumentBrokerConfigurationErrorIsMapped(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')
			->willThrowException(new BrokeredCallConfigurationException(message: 'credential not found'));

		$this->expectException(PeppolProviderException::class);
		$this->expectExceptionMessage('credential not found');

		$this->provider->submitDocument(
			sourceConfiguration: $this->configuration,
			recipientPeppolId: '0192:1234567890',
			documentType: 'ubl-invoice-2.1',
			payload: 'https://example.com/invoice.xml'
		);

	}//end testSubmitDocumentBrokerConfigurationErrorIsMapped()

	/**
	 * A successful brokered dispatch returns the AP-assigned transmissionId.
	 *
	 * @return void
	 */
	public function testSubmitDocumentSuccessReturnsTransmissionId(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
		$this->brokeredCallService->method('dispatch')->willReturn(
			new Response(200, [], json_encode(['transmissionId' => 'AP-TX-123']))
		);

		$transmissionId = $this->provider->submitDocument(
			sourceConfiguration: $this->configuration,
			recipientPeppolId: '0192:1234567890',
			documentType: 'ubl-invoice-2.1',
			payload: 'https://example.com/invoice.xml'
		);

		$this->assertSame('AP-TX-123', $transmissionId);

	}//end testSubmitDocumentSuccessReturnsTransmissionId()

	/**
	 * A non-2xx AP response is a descriptive PeppolProviderException, never a crash.
	 *
	 * @return void
	 */
	public function testSubmitDocumentNonSuccessStatusIsMappedNotCrash(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
		$this->brokeredCallService->method('dispatch')->willReturn(new Response(503, [], 'upstream down'));

		$this->expectException(PeppolProviderException::class);

		$this->provider->submitDocument(
			sourceConfiguration: $this->configuration,
			recipientPeppolId: '0192:1234567890',
			documentType: 'ubl-invoice-2.1',
			payload: 'https://example.com/invoice.xml'
		);

	}//end testSubmitDocumentNonSuccessStatusIsMappedNotCrash()

	/**
	 * lookupParticipant parses exists/supportedDocTypes from a successful AP response.
	 *
	 * @return void
	 */
	public function testLookupParticipantParsesSuccessfulResponse(): void {
		$this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
		$this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
		$this->brokeredCallService->method('dispatch')->willReturn(
			new Response(200, [], json_encode(['exists' => true, 'supportedDocTypes' => ['ubl-invoice-2.1']]))
		);

		$result = $this->provider->lookupParticipant(sourceConfiguration: $this->configuration, peppolId: '0192:1234567890');

		$this->assertTrue($result['exists']);
		$this->assertSame(['ubl-invoice-2.1'], $result['supportedDocTypes']);

	}//end testLookupParticipantParsesSuccessfulResponse()
}//end class
