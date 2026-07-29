<?php
/**
 * Unit tests for SensitiveFieldRegistry.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Security
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Security;

use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * TC-1 / TC-2 — redaction matrix for the shared sensitive-field registry.
 */
class SensitiveFieldRegistryTest extends TestCase
{

    /**
     * @var SensitiveFieldRegistry
     */
    private SensitiveFieldRegistry $registry;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new SensitiveFieldRegistry();
    }//end setUp()


    /**
     * TC-1: every secret-shaped field/header name from #1013/#964 is detected.
     *
     * @return void
     */
    public function testIsSensitiveNameMatchesEverySecretShapedName(): void
    {
        $secretNames = [
            'Authorization',
            'Proxy-Authorization',
            'Cookie',
            'Set-Cookie',
            'X-Api-Key',
            'client_secret',
            'apikey',
            'password',
            'passwd',
            'access_token',
            'bearer',
            'signature',
            'assertion',
            'private_key',
            'username',
            'authorizationHeader',
            'jwt',
            'jwtId',
        ];

        foreach ($secretNames as $name) {
            $this->assertTrue($this->registry->isSensitiveName(name: $name), "expected '{$name}' to be sensitive");
        }
    }//end testIsSensitiveNameMatchesEverySecretShapedName()


    /**
     * TC-1: non-secret control names are NOT flagged.
     *
     * @return void
     */
    public function testIsSensitiveNameRejectsControlNames(): void
    {
        foreach (['Accept', 'page', 'title'] as $name) {
            $this->assertFalse($this->registry->isSensitiveName(name: $name), "expected '{$name}' to be non-sensitive");
        }
    }//end testIsSensitiveNameRejectsControlNames()


    /**
     * TC-1: the exact-match names lifted from SourceHandler return true even
     * though some (e.g. `username`) do not match the regex pattern alone.
     *
     * @return void
     */
    public function testIsSensitiveNameMatchesSourceHandlerExactList(): void
    {
        $exactNames = [
            'authorizationHeader',
            'auth',
            'authenticationConfig',
            'authorizationPassthroughMethod',
            'jwt',
            'jwtId',
            'secret',
            'username',
            'password',
            'apikey',
        ];

        foreach ($exactNames as $name) {
            $this->assertTrue($this->registry->isSensitiveName(name: $name), "expected exact-match name '{$name}' to be sensitive");
        }
    }//end testIsSensitiveNameMatchesSourceHandlerExactList()


    /**
     * TC-1: matching is case-insensitive.
     *
     * @return void
     */
    public function testIsSensitiveNameIsCaseInsensitive(): void
    {
        $this->assertTrue($this->registry->isSensitiveName(name: 'AUTHORIZATION'));
        $this->assertTrue($this->registry->isSensitiveName(name: 'Client_Secret'));
        $this->assertTrue($this->registry->isSensitiveName(name: 'USERNAME'));
    }//end testIsSensitiveNameIsCaseInsensitive()


    /**
     * TC-1: `isSensitiveName('client_secret')` explicitly returns true (proposal example).
     *
     * @return void
     */
    public function testIsSensitiveNameClientSecret(): void
    {
        $this->assertTrue($this->registry->isSensitiveName(name: 'client_secret'));
    }//end testIsSensitiveNameClientSecret()


    /**
     * TC-2: redactArray masks a deeply nested secret value without disturbing
     * sibling keys at any level or the array's key order.
     *
     * @return void
     */
    public function testRedactArrayMasksNestedSecretWithoutDisturbingStructure(): void
    {
        $fixture = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'Authorization' => 'Bearer live-secret-token',
                        'Accept'        => 'application/json',
                    ],
                    'title'  => 'unchanged',
                ],
                'page'   => 1,
            ],
        ];

        $result = $this->registry->redactArray(data: $fixture);

        $this->assertSame('***REDACTED***', $result['level1']['level2']['level3']['Authorization']);
        $this->assertSame('application/json', $result['level1']['level2']['level3']['Accept']);
        $this->assertSame('unchanged', $result['level1']['level2']['title']);
        $this->assertSame(1, $result['level1']['page']);

        // Key order preserved at every level.
        $this->assertSame(['level1'], array_keys($result));
        $this->assertSame(['level2', 'page'], array_keys($result['level1']));
        $this->assertSame(['level3', 'title'], array_keys($result['level1']['level2']));
        $this->assertSame(['Authorization', 'Accept'], array_keys($result['level1']['level2']['level3']));
    }//end testRedactArrayMasksNestedSecretWithoutDisturbingStructure()


    /**
     * GIVEN a nested array `{"action":{"headers":{"Authorization":"Bearer x"}}}`
     * WHEN redactArray(...) is called THEN the nested Authorization value
     * SHALL be ***REDACTED*** and all other keys SHALL be unchanged.
     *
     * @return void
     */
    public function testRedactArrayHandlesActionHeadersAuthorizationExample(): void
    {
        $fixture = ['action' => ['headers' => ['Authorization' => 'Bearer x']]];

        $result = $this->registry->redactArray(data: $fixture);

        $this->assertSame('***REDACTED***', $result['action']['headers']['Authorization']);
    }//end testRedactArrayHandlesActionHeadersAuthorizationExample()


    /**
     * redactArray also matches on the last dot-segment of a flat dot-notation
     * key (the shape Source's `configuration` array uses).
     *
     * @return void
     */
    public function testRedactArrayMatchesLastDotSegmentOfFlatKeys(): void
    {
        $fixture = [
            'headers.Authorization' => 'Bearer live-token',
            'headers.Accept'        => 'application/json',
        ];

        $result = $this->registry->redactArray(data: $fixture);

        $this->assertSame('***REDACTED***', $result['headers.Authorization']);
        $this->assertSame('application/json', $result['headers.Accept']);
    }//end testRedactArrayMatchesLastDotSegmentOfFlatKeys()


    /**
     * redactArray does not mutate the caller's original array (operates on a copy).
     *
     * @return void
     */
    public function testRedactArrayDoesNotMutateCallerArray(): void
    {
        $fixture = ['Authorization' => 'Bearer live-token'];

        $this->registry->redactArray(data: $fixture);

        $this->assertSame('Bearer live-token', $fixture['Authorization']);
    }//end testRedactArrayDoesNotMutateCallerArray()
}//end class
