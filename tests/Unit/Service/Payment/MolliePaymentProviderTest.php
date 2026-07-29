<?php

/**
 * Unit tests for MolliePaymentProvider.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Payment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/live-payment-providers/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Payment;

use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\BrokeredCallConfigurationException;
use OCA\OpenConnector\Exception\PaymentProviderException;
use OCA\OpenConnector\Service\BrokeredCallService;
use OCA\OpenConnector\Service\Payment\MolliePaymentProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Mollie Payments API v2 provider (brokered dispatch).
 *
 * @spec openspec/changes/live-payment-providers/specs/live-payment-providers/spec.md#scenario-the-mollie-provider-brokers-its-api-key
 */
class MolliePaymentProviderTest extends TestCase
{

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
     * @var MolliePaymentProvider
     */
    private MolliePaymentProvider $provider;

    /**
     * The valid configuration used by every "happy path" test.
     *
     * @var array
     */
    private array $configuration = [
        'baseUrl'        => 'https://api.mollie.example/v2',
        'authentication' => ['credentialRef' => ['credentialId' => 'cred-uuid']],
    ];

    /**
     * The create-payment envelope used by every test.
     *
     * @var array
     */
    private array $payload = [
        'amount'      => ['value' => '10.00', 'currency' => 'EUR'],
        'description' => 'Invoice INV-1',
        'redirectUrl' => 'https://example.com/return',
        'webhookUrl'  => 'https://example.com/webhook',
        'method'      => 'ideal',
    ];

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->brokeredCallService = $this->createMock(BrokeredCallService::class);
        $this->l                   = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->provider = new MolliePaymentProvider($this->brokeredCallService, $this->l, $this->logger);

    }//end setUp()

    /**
     * A source with no credentialRef fails closed before any dispatch — REQ-LPP-006.
     *
     * @return void
     */
    public function testCreatePaymentWithoutCredentialRefFailsClosed(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(false);
        $this->brokeredCallService->expects($this->never())->method('dispatch');

        $this->expectException(PaymentProviderException::class);

        $this->provider->createPayment(sourceConfiguration: ['baseUrl' => 'https://api.mollie.example/v2'], payload: $this->payload);

    }//end testCreatePaymentWithoutCredentialRefFailsClosed()

    /**
     * A brokered configuration error is mapped to a secret-free PaymentProviderException.
     *
     * @return void
     */
    public function testCreatePaymentBrokerConfigurationErrorIsMapped(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')
            ->willThrowException(new BrokeredCallConfigurationException(message: 'credential not found'));

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessage('credential not found');

        $this->provider->createPayment(sourceConfiguration: $this->configuration, payload: $this->payload);

    }//end testCreatePaymentBrokerConfigurationErrorIsMapped()

    /**
     * A successful brokered create dispatch returns the Mollie payment id + checkout URL.
     *
     * @return void
     */
    public function testCreatePaymentSuccessReturnsIdAndCheckoutUrl(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->method('dispatch')->willReturn(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'id'     => 'tr_mock_a1b2c3',
                        'status' => 'open',
                        '_links' => ['checkout' => ['href' => 'https://mollie.example/checkout/tr_mock_a1b2c3']],
                    ]
                )
            )
        );

        $result = $this->provider->createPayment(sourceConfiguration: $this->configuration, payload: $this->payload);

        $this->assertSame('tr_mock_a1b2c3', $result['providerPaymentId']);
        $this->assertSame('open', $result['paymentStatus']);
        $this->assertSame('https://mollie.example/checkout/tr_mock_a1b2c3', $result['checkoutUrl']);

    }//end testCreatePaymentSuccessReturnsIdAndCheckoutUrl()

    /**
     * A response missing the checkout link is a descriptive exception, never a crash.
     *
     * @return void
     */
    public function testCreatePaymentMissingCheckoutUrlIsMappedNotCrash(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->method('dispatch')->willReturn(
            new Response(200, [], json_encode(['id' => 'tr_mock_a1b2c3', 'status' => 'open']))
        );

        $this->expectException(PaymentProviderException::class);

        $this->provider->createPayment(sourceConfiguration: $this->configuration, payload: $this->payload);

    }//end testCreatePaymentMissingCheckoutUrlIsMappedNotCrash()

    /**
     * A non-2xx Mollie response is a descriptive PaymentProviderException, never a crash.
     *
     * @return void
     */
    public function testCreatePaymentNonSuccessStatusIsMappedNotCrash(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->method('dispatch')->willReturn(new Response(503, [], 'upstream down'));

        $this->expectException(PaymentProviderException::class);

        $this->provider->createPayment(sourceConfiguration: $this->configuration, payload: $this->payload);

    }//end testCreatePaymentNonSuccessStatusIsMappedNotCrash()

    /**
     * fetchPaymentStatus parses the current status from a successful Mollie response.
     *
     * @return void
     */
    public function testFetchPaymentStatusParsesSuccessfulResponse(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->method('dispatch')->willReturn(
            new Response(200, [], json_encode(['id' => 'tr_mock_a1b2c3', 'status' => 'paid']))
        );

        $result = $this->provider->fetchPaymentStatus(sourceConfiguration: $this->configuration, providerPaymentId: 'tr_mock_a1b2c3');

        $this->assertSame('tr_mock_a1b2c3', $result['providerPaymentId']);
        $this->assertSame('paid', $result['paymentStatus']);

    }//end testFetchPaymentStatusParsesSuccessfulResponse()

    /**
     * fetchPaymentStatus without a credentialRef fails closed before any dispatch.
     *
     * @return void
     */
    public function testFetchPaymentStatusWithoutCredentialRefFailsClosed(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(false);
        $this->brokeredCallService->expects($this->never())->method('dispatch');

        $this->expectException(PaymentProviderException::class);

        $this->provider->fetchPaymentStatus(sourceConfiguration: ['baseUrl' => 'https://api.mollie.example/v2'], providerPaymentId: 'tr_mock_a1b2c3');

    }//end testFetchPaymentStatusWithoutCredentialRefFailsClosed()
}//end class
