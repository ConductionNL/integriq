<?php

/**
 * Unit tests for RestPsd2AggregatorProvider.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Psd2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Psd2;

use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\BrokeredCallConfigurationException;
use OCA\OpenConnector\Exception\Psd2ConsentRevokedException;
use OCA\OpenConnector\Exception\Psd2ProviderException;
use OCA\OpenConnector\Service\BrokeredCallService;
use OCA\OpenConnector\Service\Psd2\RestPsd2AggregatorProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the generic GoCardless-shape REST PSD2 provider (brokered dispatch).
 *
 * @spec openspec/changes/psd2-ais-bank-feed-connector/specs/psd2-ais-bank-feed-connector/spec.md#scenario-the-rest-provider-brokers-its-token
 */
class RestPsd2AggregatorProviderTest extends TestCase
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
     * @var RestPsd2AggregatorProvider
     */
    private RestPsd2AggregatorProvider $provider;

    /**
     * The valid configuration used by every "happy path" test.
     *
     * @var array
     */
    private array $configuration = [
        'baseUrl'        => 'https://aggregator.example/psd2',
        'authentication' => ['credentialRef' => ['credentialId' => 'cred-uuid']],
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

        $this->provider = new RestPsd2AggregatorProvider(
            $this->brokeredCallService,
            $this->l,
            $this->logger
        );

    }//end setUp()

    /**
     * A source with no credentialRef fails closed before any dispatch, with an
     * actionable message and NO plaintext fallback — REQ-006.
     *
     * @return void
     */
    public function testCreateRequisitionWithoutCredentialRefFailsClosed(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(false);
        $this->brokeredCallService->expects($this->never())->method('dispatch');

        $this->expectException(Psd2ProviderException::class);
        $this->expectExceptionMessage('credentialRef');

        $this->provider->createRequisition(
            sourceConfiguration: ['baseUrl' => 'https://aggregator.example/psd2'],
            institutionId: 'BANK_NL',
            redirectUrl: 'https://nc.example/return'
        );

    }//end testCreateRequisitionWithoutCredentialRefFailsClosed()

    /**
     * A brokered configuration error is mapped to a secret-free Psd2ProviderException.
     *
     * @return void
     */
    public function testBrokerConfigurationErrorIsMapped(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')
            ->willThrowException(new BrokeredCallConfigurationException(message: 'credential not found'));

        $this->expectException(Psd2ProviderException::class);
        $this->expectExceptionMessage('credential not found');

        $this->provider->createRequisition(
            sourceConfiguration: $this->configuration,
            institutionId: 'BANK_NL',
            redirectUrl: 'https://nc.example/return'
        );

    }//end testBrokerConfigurationErrorIsMapped()

    /**
     * A successful requisition creation routes through the broker and parses id + SCA link (REQ-001).
     *
     * @return void
     */
    public function testCreateRequisitionRoutesThroughBrokerAndParsesResponse(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->expects($this->once())
            ->method('dispatch')
            ->with(
                'cred-uuid',
                null,
                'POST',
                $this->stringContains('/requisitions/'),
                $this->callback(
                    static function (array $config): bool {
                        return ($config['json']['institution_id'] ?? null) === 'BANK_NL';
                    }
                )
            )
            ->willReturn(
                new Response(201, [], json_encode(['id' => 'REQ-123', 'link' => 'https://bank.example/sca/REQ-123']))
            );

        $result = $this->provider->createRequisition(
            sourceConfiguration: $this->configuration,
            institutionId: 'BANK_NL',
            redirectUrl: 'https://nc.example/return'
        );

        $this->assertSame('REQ-123', $result['reference']);
        $this->assertSame('https://bank.example/sca/REQ-123', $result['redirectUrl']);

    }//end testCreateRequisitionRoutesThroughBrokerAndParsesResponse()

    /**
     * listTransactions parses the GoCardless booked-transactions shape.
     *
     * @return void
     */
    public function testListTransactionsParsesBookedRows(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->method('dispatch')->willReturn(
            new Response(
                200,
                [],
                json_encode(
                    [
                        'transactions' => [
                            'booked'  => [['transactionId' => 'TX-1', 'bookingDate' => '2026-07-01']],
                            'pending' => [['transactionId' => 'TX-PENDING']],
                        ],
                    ]
                )
            )
        );

        $rows = $this->provider->listTransactions(
            sourceConfiguration: $this->configuration,
            accountId: 'ACC-1',
            since: '2026-06-30T00:00:00+00:00',
            until: '2026-07-01T00:00:00+00:00'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('TX-1', $rows[0]['transactionId']);

    }//end testListTransactionsParsesBookedRows()

    /**
     * A 401 on a consent-scoped pull maps to Psd2ConsentRevokedException so the
     * sync can move the connection to revoked (REQ-005).
     *
     * @return void
     */
    public function testConsentScoped401MapsToConsentRevoked(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->method('dispatch')->willReturn(new Response(401, [], 'consent expired'));

        $this->expectException(Psd2ConsentRevokedException::class);

        $this->provider->listTransactions(
            sourceConfiguration: $this->configuration,
            accountId: 'ACC-1',
            since: '2026-06-30T00:00:00+00:00',
            until: '2026-07-01T00:00:00+00:00'
        );

    }//end testConsentScoped401MapsToConsentRevoked()

    /**
     * A non-2xx aggregator response is a descriptive Psd2ProviderException, never a crash.
     *
     * @return void
     */
    public function testNonSuccessStatusIsMappedNotCrash(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->method('dispatch')->willReturn(new Response(503, [], 'upstream down'));

        $this->expectException(Psd2ProviderException::class);

        $this->provider->createRequisition(
            sourceConfiguration: $this->configuration,
            institutionId: 'BANK_NL',
            redirectUrl: 'https://nc.example/return'
        );

    }//end testNonSuccessStatusIsMappedNotCrash()

    /**
     * finaliseConsent resolves requisition accounts to IBAN details and returns no token
     * (GoCardless model: the brokered API key is the only secret) — REQ-002/REQ-006.
     *
     * @return void
     */
    public function testFinaliseConsentResolvesAccountsAndReturnsNoToken(): void
    {
        $this->brokeredCallService->method('hasCredentialRef')->willReturn(true);
        $this->brokeredCallService->method('prepare')->willReturn(['credentialId' => 'cred-uuid', 'actingUserId' => null]);
        $this->brokeredCallService->method('dispatch')->willReturnCallback(
            static function (string $credentialId, ?string $actingUserId, string $method, string $url, array $config): Response {
                if (str_contains($url, '/requisitions/') === true) {
                    return new Response(200, [], json_encode(['id' => 'REQ-123', 'status' => 'LN', 'accounts' => ['ACC-1']]));
                }

                return new Response(
                    200,
                    [],
                    json_encode(['account' => ['iban' => 'NL91ABNA0417164300', 'bic' => 'ABNANL2A', 'currency' => 'EUR']])
                );
            }
        );

        $consent = $this->provider->finaliseConsent(sourceConfiguration: $this->configuration, reference: 'REQ-123');

        $this->assertSame('REQ-123', $consent['consentReference']);
        $this->assertNull($consent['consentToken']);
        $this->assertCount(1, $consent['accounts']);
        $this->assertSame('NL91ABNA0417164300', $consent['accounts'][0]['iban']);
        $this->assertSame('ACC-1', $consent['accounts'][0]['aggregatorAccountId']);
        $this->assertNotEmpty($consent['consentExpiresAt']);

    }//end testFinaliseConsentResolvesAccountsAndReturnsNoToken()
}//end class
