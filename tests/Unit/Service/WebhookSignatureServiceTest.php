<?php
/**
 * Unit tests for WebhookSignatureService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\WebhookSignatureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the shared webhook HMAC sign/verify service.
 *
 * @spec openspec/changes/openconnector-webhook-signing/tasks.md#task-6
 */
class WebhookSignatureServiceTest extends TestCase
{

    /**
     * @var WebhookSignatureService
     */
    private WebhookSignatureService $service;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->service = new WebhookSignatureService($this->logger);
    }//end setUp()


    /**
     * Generated secrets are prefixed and have sufficient entropy.
     *
     * @return void
     */
    public function testGenerateSecretShape(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertGreaterThan(40, strlen($secret));
        $this->assertNotSame($secret, $this->service->generateSecret());
    }//end testGenerateSecretShape()


    /**
     * REQ-WHS-001: openconnector scheme round trips.
     *
     * @return void
     */
    public function testOpenConnectorRoundTrip(): void
    {
        $secret = 'whsec_abc';
        $body   = '{"hello":"world"}';
        $header = $this->service->sign(rawBody: $body, secret: $secret);

        $ok = $this->service->verify(
            rawBody: $body,
            headerValue: $header,
            config: ['scheme' => 'openconnector', 'secret' => $secret]
        );
        $this->assertTrue($ok);
    }//end testOpenConnectorRoundTrip()


    /**
     * Signing covers the exact body bytes — any mutation fails verification.
     *
     * @return void
     */
    public function testRawBytesInvariance(): void
    {
        $secret = 'whsec_abc';
        $header = $this->service->sign(rawBody: '{"a":1}', secret: $secret);

        $tampered = $this->service->verify(
            rawBody: '{"a":2}',
            headerValue: $header,
            config: ['scheme' => 'openconnector', 'secret' => $secret]
        );
        $this->assertFalse($tampered);
    }//end testRawBytesInvariance()


    /**
     * REQ-WHS-003: a timestamp outside the tolerance window is rejected.
     *
     * @return void
     */
    public function testStaleTimestampRejected(): void
    {
        $secret = 'whsec_abc';
        $body   = '{"a":1}';
        $old    = (time() - 600);
        $header = $this->service->sign(rawBody: $body, secret: $secret, previousSecret: null, timestamp: $old);

        $ok = $this->service->verify(
            rawBody: $body,
            headerValue: $header,
            config: ['scheme' => 'openconnector', 'secret' => $secret, 'toleranceSeconds' => 300]
        );
        $this->assertFalse($ok);
    }//end testStaleTimestampRejected()


    /**
     * REQ-WHS-002: dual-signed header verifies against either secret.
     *
     * @return void
     */
    public function testDualSignVerifiesAgainstEitherSecret(): void
    {
        $current  = 'whsec_new';
        $previous = 'whsec_old';
        $body     = '{"a":1}';
        $header   = $this->service->sign(rawBody: $body, secret: $current, previousSecret: $previous);

        $this->assertCount(2, array_filter(explode(',', $header), static fn($p) => str_starts_with(trim($p), 'v1=')));

        $byNew = $this->service->verify($body, $header, ['scheme' => 'openconnector', 'secret' => $current]);
        $byOld = $this->service->verify($body, $header, ['scheme' => 'openconnector', 'secret' => $previous]);
        $this->assertTrue($byNew);
        $this->assertTrue($byOld);
    }//end testDualSignVerifiesAgainstEitherSecret()


    /**
     * REQ-WHS-003: github scheme verifies without a timestamp.
     *
     * @return void
     */
    public function testGithubSchemeRoundTrip(): void
    {
        $secret = 'whsec_abc';
        $body   = '{"a":1}';
        $header = 'sha256='.hash_hmac('sha256', $body, $secret);

        $ok = $this->service->verify(
            rawBody: $body,
            headerValue: $header,
            config: ['scheme' => 'github', 'secret' => $secret]
        );
        $this->assertTrue($ok);
    }//end testGithubSchemeRoundTrip()


    /**
     * A missing or malformed header fails verification (no exception).
     *
     * @return void
     */
    public function testMalformedHeaderFails(): void
    {
        $this->assertFalse(
            $this->service->verify('{"a":1}', '', ['scheme' => 'openconnector', 'secret' => 'whsec_abc'])
        );
        $this->assertFalse(
            $this->service->verify('{"a":1}', 'garbage', ['scheme' => 'openconnector', 'secret' => 'whsec_abc'])
        );
    }//end testMalformedHeaderFails()


    /**
     * REQ-WHS-002: rotation grace window expiry is computed correctly.
     *
     * @return void
     */
    public function testRotationGraceWindow(): void
    {
        $this->assertTrue($this->service->isRotationGraceActive((new \DateTime('-1 hour'))->format('c')));
        $this->assertFalse($this->service->isRotationGraceActive((new \DateTime('-25 hour'))->format('c')));
        $this->assertFalse($this->service->isRotationGraceActive(null));
    }//end testRotationGraceWindow()
}//end class
