<?php

/**
 * Unit tests for AzureVirtualDesktopAdapter.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Adapter;

use OCA\OpenConnector\Service\Adapter\EndpointWorkspace\AzureVirtualDesktopAdapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the AVD reference adapter (REQ-EWC-001/REQ-EWC-002).
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-2
 */
class AzureVirtualDesktopAdapterTest extends TestCase
{

    /**
     * @var CredentialBrokerService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $credentialBroker;

    /**
     * @var AzureVirtualDesktopAdapter
     */
    private AzureVirtualDesktopAdapter $adapter;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialBroker = $this->createMock(CredentialBrokerService::class);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('cred-uuid-avd');

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->adapter = new AzureVirtualDesktopAdapter(
            credentialBroker: $this->credentialBroker,
            appConfig: $appConfig,
            logger: $this->createMock(LoggerInterface::class),
            l10n: $l10n
        );
    }//end setUp()

    /**
     * Declares the fixed REQ-EWC-002 capability vocabulary.
     *
     * @return void
     */
    public function testCapabilities(): void
    {
        $this->assertSame(
            ['session-enumeration', 'user-mapping', 'audit-event-ingestion'],
            $this->adapter->getCapabilities()
        );
    }//end testCapabilities()

    /**
     * `listSessions()` calls the ARM `userSessions` path and normalises the
     * response into flat session summaries.
     *
     * @return void
     */
    public function testListSessionsNormalisesArmResponse(): void
    {
        $armResponse = [
            'value' => [
                [
                    'name'       => 'session-1',
                    'properties' => [
                        'userPrincipalName' => 'alice@example.com',
                        'sessionState'      => 'Active',
                        'createTime'        => '2026-01-01T00:00:00Z',
                    ],
                ],
            ],
        ];

        $this->credentialBroker->expects($this->once())
            ->method('request')
            ->with(
                'cred-uuid-avd',
                'openconnector',
                'GET',
                $this->stringContains('/subscriptions/sub-1/resourceGroups/rg-1/providers/Microsoft.DesktopVirtualization/hostPools/pool-1/sessionHosts/host-1/userSessions'),
                [],
                null,
                null
            )
            ->willReturn(['status' => 200, 'headers' => [], 'body' => json_encode($armResponse)]);

        $sessions = $this->adapter->listSessions('sub-1', 'rg-1', 'pool-1', 'host-1');

        $this->assertCount(1, $sessions);
        $this->assertSame('session-1', $sessions[0]['id']);
        $this->assertSame('alice@example.com', $sessions[0]['userPrincipalName']);
        $this->assertSame('Active', $sessions[0]['sessionState']);
    }//end testListSessionsNormalisesArmResponse()

    /**
     * A non-2xx upstream response degrades to an empty list, not an exception.
     *
     * @return void
     */
    public function testListSessionsReturnsEmptyOnUpstreamError(): void
    {
        $this->credentialBroker->method('request')
            ->willReturn(['status' => 403, 'headers' => [], 'body' => '']);

        $this->assertSame([], $this->adapter->listSessions('sub-1', 'rg-1', 'pool-1', 'host-1'));
    }//end testListSessionsReturnsEmptyOnUpstreamError()

    /**
     * `mapSessionToUser()` derives a display name from the UPN's local part.
     *
     * @return void
     */
    public function testMapSessionToUserDerivesDisplayName(): void
    {
        $mapped = $this->adapter->mapSessionToUser(['userPrincipalName' => 'bob@example.com']);

        $this->assertSame('bob@example.com', $mapped['userPrincipalName']);
        $this->assertSame('bob', $mapped['displayName']);
    }//end testMapSessionToUserDerivesDisplayName()

    /**
     * `ingestAuditEvent()` normalises an Azure Monitor Activity Log event shape.
     *
     * @return void
     */
    public function testIngestAuditEventNormalisesActivityLogEvent(): void
    {
        $event = [
            'operationName'  => ['value' => 'Microsoft.DesktopVirtualization/hostPools/write'],
            'caller'         => 'admin@example.com',
            'eventTimestamp' => '2026-01-01T00:00:00Z',
            'level'          => 'Informational',
        ];

        $normalised = $this->adapter->ingestAuditEvent($event);

        $this->assertSame('azure-virtual-desktop', $normalised['source']);
        $this->assertSame('Microsoft.DesktopVirtualization/hostPools/write', $normalised['eventName']);
        $this->assertSame('admin@example.com', $normalised['caller']);
    }//end testIngestAuditEventNormalisesActivityLogEvent()

    /**
     * `list()` requires all four scoping filter keys; missing any of them
     * returns an empty array rather than throwing.
     *
     * @return void
     */
    public function testListRequiresAllScopingFilters(): void
    {
        $this->credentialBroker->expects($this->never())->method('request');

        $this->assertSame(
            [],
            $this->adapter->list('register', 'schema', 'object-id', ['subscriptionId' => 'sub-1'])
        );
    }//end testListRequiresAllScopingFilters()
}//end class
