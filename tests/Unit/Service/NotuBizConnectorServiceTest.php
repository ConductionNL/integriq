<?php
/**
 * Unit tests for NotuBizConnectorService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\NotuBizConnectorService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the NotuBiz connector service.
 *
 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-8
 */
class NotuBizConnectorServiceTest extends TestCase
{

    /**
     * @var NotuBizConnectorService
     */
    private NotuBizConnectorService $service;

    /**
     * @var CallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;

    /**
     * @var AuthenticationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $authService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->callService = $this->createMock(CallService::class);
        $this->authService = $this->createMock(AuthenticationService::class);
        $logger            = $this->createMock(LoggerInterface::class);

        $this->service = new NotuBizConnectorService(
            $this->callService,
            $this->authService,
            $logger
        );

    }//end setUp()


    /**
     * Test that testConnection fails without organisatieId.
     *
     * @return void
     */
    public function testTestConnectionFailsWithoutOrganisatieId(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => []],
            'notubiz-source-1'
        );

        $result = $this->service->testConnection($source);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Organisation ID', $result['message']);

    }//end testTestConnectionFailsWithoutOrganisatieId()


    /**
     * Test that testConnection returns success on HTTP 200.
     *
     * @return void
     */
    public function testTestConnectionSuccess(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => ['organisatieId' => 'org-abc']],
            'notubiz-source-2'
        );

        $orgBody       = json_encode(['naam' => 'Gemeente Test', 'id' => 'org-abc']);
        $callLogEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['statusCode' => 200, 'response' => ['statusCode' => 200, 'body' => $orgBody]],
            'call-log-nb-1'
        );

        $this->callService
            ->method('call')
            ->willReturn($callLogEntity);

        $result = $this->service->testConnection($source);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Gemeente Test', $result['message']);

    }//end testTestConnectionSuccess()


    /**
     * Test that testConnection returns auth failure on HTTP 401.
     *
     * @return void
     */
    public function testTestConnectionAuthFailure(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => ['organisatieId' => 'org-abc']],
            'notubiz-source-3'
        );

        $callLogEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['statusCode' => 401],
            'call-log-nb-2'
        );

        $this->callService
            ->method('call')
            ->willReturn($callLogEntity);

        $result = $this->service->testConnection($source);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('401', $result['message']);

    }//end testTestConnectionAuthFailure()


    /**
     * Test that refreshToken returns false when credentials are missing.
     *
     * @return void
     */
    public function testRefreshTokenMissingCredentials(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => ['clientId' => 'id-only']],
            'notubiz-source-4'
        );

        $result = $this->service->refreshToken($source);

        $this->assertFalse($result);

    }//end testRefreshTokenMissingCredentials()


    /**
     * Test that refreshToken returns true when token obtained successfully.
     *
     * @return void
     */
    public function testRefreshTokenSuccess(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'configuration' => [
                    'clientId'      => 'my-client',
                    'clientSecret'  => 'my-secret',
                    'tokenEndpoint' => 'https://auth.notubiz.nl/token',
                ],
            ],
            'notubiz-source-5'
        );

        $this->authService
            ->method('fetchOAuthTokens')
            ->willReturn('access-token-xyz');

        $result = $this->service->refreshToken($source);

        $this->assertTrue($result);

    }//end testRefreshTokenSuccess()


    /**
     * Test that refreshToken returns false when token fetch throws.
     *
     * @return void
     */
    public function testRefreshTokenException(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'configuration' => [
                    'clientId'      => 'my-client',
                    'clientSecret'  => 'my-secret',
                    'tokenEndpoint' => 'https://auth.notubiz.nl/token',
                ],
            ],
            'notubiz-source-6'
        );

        $this->authService
            ->method('fetchOAuthTokens')
            ->willThrowException(new \Exception('Token endpoint unreachable'));

        $result = $this->service->refreshToken($source);

        $this->assertFalse($result);

    }//end testRefreshTokenException()


    /**
     * Test that pushVergaderstuk fails without organisatieId.
     *
     * @return void
     */
    public function testPushVergaderstukFailsWithoutOrganisatieId(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => []],
            'notubiz-source-7'
        );

        $result = $this->service->pushVergaderstuk($source, ['onderwerp' => 'Test']);

        $this->assertFalse($result['success']);
        $this->assertNull($result['vergaderstukId']);

    }//end testPushVergaderstukFailsWithoutOrganisatieId()


    /**
     * Test that pushVergaderstuk returns failure when API call fails.
     *
     * @return void
     */
    public function testPushVergaderstukApiFailure(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => ['organisatieId' => 'org-abc']],
            'notubiz-source-8'
        );

        // Return a call log entity with statusCode 0 to simulate a failed API call.
        $failedLog = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['statusCode' => 0],
            'call-log-nb-fail'
        );

        $this->callService
            ->method('call')
            ->willReturn($failedLog);

        $result = $this->service->pushVergaderstuk(
            $source,
            ['onderwerp' => 'Raadsvoorstel subsidies', 'vergadertype' => 'raad']
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['vergaderstukId']);

    }//end testPushVergaderstukApiFailure()


    /**
     * Test that pushVergaderstuk succeeds on HTTP 201.
     *
     * @return void
     */
    public function testPushVergaderstukSuccess(): void
    {
        $source = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['configuration' => ['organisatieId' => 'org-abc']],
            'notubiz-source-9'
        );

        $responseBody  = json_encode(['id' => 'stuk-789']);
        $callLogEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['statusCode' => 201, 'response' => ['statusCode' => 201, 'body' => $responseBody]],
            'call-log-nb-3'
        );

        $this->callService
            ->method('call')
            ->willReturn($callLogEntity);

        $result = $this->service->pushVergaderstuk(
            $source,
            ['onderwerp' => 'Raadsvoorstel subsidies', 'vergadertype' => 'raad']
        );

        $this->assertTrue($result['success']);
        $this->assertSame('stuk-789', $result['vergaderstukId']);

    }//end testPushVergaderstukSuccess()


    /**
     * Test mapVergadertype handles college correctly.
     *
     * @return void
     */
    public function testMapVergadertypeCollege(): void
    {
        $result = $this->service->mapVergadertype('college');
        $this->assertSame('collegevergadering', $result);

    }//end testMapVergadertypeCollege()


    /**
     * Test mapVergadertype handles raad correctly.
     *
     * @return void
     */
    public function testMapVergadertypeRaad(): void
    {
        $result = $this->service->mapVergadertype('raad');
        $this->assertSame('raadsvergadering', $result);

    }//end testMapVergadertypeRaad()


    /**
     * Test mapVergadertype handles commissie correctly.
     *
     * @return void
     */
    public function testMapVergadertypeCommissie(): void
    {
        $result = $this->service->mapVergadertype('commissie');
        $this->assertSame('commissievergadering', $result);

    }//end testMapVergadertypeCommissie()


    /**
     * Test mapVergadertype falls back to collegevergadering for unknown types.
     *
     * @return void
     */
    public function testMapVergadertypeDefaultsToCollege(): void
    {
        $result = $this->service->mapVergadertype('unknown-type');
        $this->assertSame('collegevergadering', $result);

    }//end testMapVergadertypeDefaultsToCollege()


}//end class
