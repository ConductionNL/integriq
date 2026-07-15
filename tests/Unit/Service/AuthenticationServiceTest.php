<?php

/**
 * Unit tests for AuthenticationService — WS-Security UsernameToken coverage
 * and JWT private-key temp-file hygiene (secret-hygiene).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
 */

declare(strict_types=1);

// Test spy for AuthenticationService::getRSJWK()'s temp-file creation calls.
// PHP resolves an unqualified function call against the CALLER's namespace
// before falling back to the global namespace, so declaring `tempnam()` /
// `chmod()` here (in the SAME namespace as AuthenticationService) lets the
// tests below observe the exact filename/permission calls the private
// getRSJWK() method makes — every call is transparently delegated to the
// real global function, so production behaviour is completely unchanged.
// This is the "decorated temp-dir helper / spy pattern" the secret-hygiene
// test plan (TC-14/TC-15) calls out as acceptable, since getRSJWK() deletes
// its temp file in a `finally` block before control returns to the caller,
// making the live file otherwise unobservable from a black-box test.
namespace OCA\OpenConnector\Service {
    if (function_exists(__NAMESPACE__.'\\tempnam') === false) {
        $GLOBALS['__oc_tempnam_spy_calls'] = [];

        /**
         * Spy wrapper around the global tempnam().
         *
         * @param string $directory Directory in which to create the file.
         * @param string $prefix    Filename prefix.
         *
         * @return string|false The real tempnam() return value.
         */
        function tempnam(string $directory, string $prefix): string|false
        {
            $result = \tempnam($directory, $prefix);
            $GLOBALS['__oc_tempnam_spy_calls'][] = $result;

            return $result;
        }//end tempnam()
    }//end if

    if (function_exists(__NAMESPACE__.'\\chmod') === false) {
        $GLOBALS['__oc_chmod_spy_calls'] = [];

        /**
         * Spy wrapper around the global chmod().
         *
         * @param string $filename    The file to chmod.
         * @param integer $permissions The permission bits to apply.
         *
         * @return boolean The real chmod() return value.
         */
        function chmod(string $filename, int $permissions): bool
        {
            $GLOBALS['__oc_chmod_spy_calls'][] = [
                'filename'    => $filename,
                'permissions' => $permissions,
            ];

            return \chmod($filename, $permissions);
        }//end chmod()
    }//end if
}//end namespace

namespace OCA\OpenConnector\Tests\Unit\Service {

    use OCA\OpenConnector\Service\AuthenticationService;
    use Symfony\Component\HttpFoundation\Exception\BadRequestException;
    use PHPUnit\Framework\TestCase;
    use Twig\Loader\ArrayLoader;

/**
 * Tests for `AuthenticationService::buildWsSecurityHeader()` (REQ-STUF-012)
 * and `AuthenticationService::fetchJWTToken()`/`getRSJWK()` private-key
 * temp-file hygiene (secret-hygiene TC-14/TC-15).
 *
 * The `PasswordDigest` assertions do not merely check "a non-empty string is
 * present" — they extract the actual nonce/created values the method
 * produced from the returned XML and independently recompute
 * `Base64(SHA1(rawNonce + Created + Password))` per the WS-Security
 * UsernameToken 1.0 profile, then assert the method's own digest matches
 * that hand-computed value. This proves the formula, not just its presence.
 *
 * @spec openspec/changes/connector-adapter-e2e-traceability/tasks.md#task-4
 */
class AuthenticationServiceTest extends TestCase
{

    /**
     * @var AuthenticationService
     */
    private AuthenticationService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AuthenticationService(new ArrayLoader([]));

    }//end setUp()

    /**
     * REQ-STUF-012 scenario "UsernameToken header added to SOAP request":
     * the returned XML fragment contains a `wsse:Security` header with a
     * `wsse:UsernameToken` carrying the configured username.
     *
     * @return void
     */
    public function testUsernameTokenHeaderStructureAndUsername(): void
    {
        $xml = $this->service->buildWsSecurityHeader(
            [
                'username' => 'stuf-gebruiker',
                'password' => 'stuf-wachtwoord',
            ]
        );

        $this->assertStringContainsString('<wsse:Security', $xml);
        $this->assertStringContainsString('<wsse:UsernameToken>', $xml);
        $this->assertStringContainsString('<wsse:Username>stuf-gebruiker</wsse:Username>', $xml);
        $this->assertStringContainsString('<wsse:Nonce>', $xml);
        $this->assertStringContainsString('<wsu:Created>', $xml);

    }//end testUsernameTokenHeaderStructureAndUsername()

    /**
     * REQ-STUF-012 scenario "PasswordDigest hashing applied": the digest is
     * verified against an independently, hand-computed
     * `Base64(SHA1(rawNonce + Created + Password))` value derived from the
     * nonce/created the method itself emitted — not just checked for
     * non-emptiness.
     *
     * @return void
     */
    public function testPasswordDigestMatchesHandComputedFormula(): void
    {
        $password = 'stuf-wachtwoord';

        $xml = $this->service->buildWsSecurityHeader(
            [
                'username'     => 'stuf-gebruiker',
                'password'     => $password,
                'passwordType' => 'PasswordDigest',
            ]
        );

        $this->assertStringContainsString('Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest"', $xml);

        $nonce   = $this->extractElement($xml, 'wsse:Nonce');
        $created = $this->extractElement($xml, 'wsu:Created');
        $digest  = $this->extractPasswordValue($xml);

        $this->assertNotEmpty($nonce);
        $this->assertNotEmpty($created);
        $this->assertNotEmpty($digest);

        // Hand-computed per the WS-Security UsernameToken 1.0 profile:
        // PasswordDigest = Base64(SHA1(rawNonce + Created + Password)).
        $expectedDigest = base64_encode(sha1(base64_decode($nonce).$created.$password, true));

        $this->assertSame($expectedDigest, $digest);

    }//end testPasswordDigestMatchesHandComputedFormula()

    /**
     * Two calls to `buildWsSecurityHeader()` with PasswordDigest MUST produce
     * different nonces (and therefore different digests), proving the nonce
     * is genuinely randomized per call and not a fixed/reused value.
     *
     * @return void
     */
    public function testPasswordDigestNonceIsRandomizedPerCall(): void
    {
        $config = [
            'username'     => 'stuf-gebruiker',
            'password'     => 'stuf-wachtwoord',
            'passwordType' => 'PasswordDigest',
        ];

        $first  = $this->service->buildWsSecurityHeader($config);
        $second = $this->service->buildWsSecurityHeader($config);

        $this->assertNotSame(
            $this->extractElement($first, 'wsse:Nonce'),
            $this->extractElement($second, 'wsse:Nonce')
        );

    }//end testPasswordDigestNonceIsRandomizedPerCall()

    /**
     * REQ-STUF-012 scenario "PasswordText included as plaintext": the exact,
     * unmodified plaintext password is present in the `wsse:Password`
     * element.
     *
     * @return void
     */
    public function testPasswordTextIncludesPlaintextUnmodified(): void
    {
        $password = 'plain-text-secret!';

        $xml = $this->service->buildWsSecurityHeader(
            [
                'username'     => 'stuf-gebruiker',
                'password'     => $password,
                'passwordType' => 'PasswordText',
            ]
        );

        $this->assertStringContainsString('Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText"', $xml);
        $this->assertSame($password, $this->extractPasswordValue($xml));

    }//end testPasswordTextIncludesPlaintextUnmodified()

    /**
     * Defaulting `passwordType` (omitted) MUST behave as `PasswordText`, per
     * the method's own docblock default.
     *
     * @return void
     */
    public function testDefaultPasswordTypeIsPasswordText(): void
    {
        $xml = $this->service->buildWsSecurityHeader(
            [
                'username' => 'stuf-gebruiker',
                'password' => 'default-mode-secret',
            ]
        );

        $this->assertStringContainsString('#PasswordText', $xml);
        $this->assertSame('default-mode-secret', $this->extractPasswordValue($xml));

    }//end testDefaultPasswordTypeIsPasswordText()

    /**
     * Missing username or password MUST fail closed with a
     * `BadRequestException` rather than emitting a header with an empty
     * credential.
     *
     * @return void
     */
    public function testMissingCredentialsThrows(): void
    {
        $this->expectException(BadRequestException::class);

        $this->service->buildWsSecurityHeader(['username' => 'only-username']);

    }//end testMissingCredentialsThrows()

    /**
     * Special XML characters in the username are escaped, so a maliciously
     * or accidentally XML-unsafe username cannot break out of the
     * `wsse:Username` element.
     *
     * @return void
     */
    public function testUsernameIsXmlEscaped(): void
    {
        $xml = $this->service->buildWsSecurityHeader(
            [
                'username' => 'a&b<c>',
                'password' => 'irrelevant',
            ]
        );

        $this->assertStringContainsString('<wsse:Username>a&amp;b&lt;c&gt;</wsse:Username>', $xml);
        $this->assertStringNotContainsString('<wsse:Username>a&b<c></wsse:Username>', $xml);

    }//end testUsernameIsXmlEscaped()

    /**
     * Extract the text content of the first occurrence of a simple XML
     * element by tag name (test helper — the fragment is a flat, known
     * shape so a regex is sufficient and avoids pulling in a full XML
     * parser dependency just for assertions).
     *
     * @param string $xml The XML fragment.
     * @param string $tag The element tag name (e.g. `wsse:Nonce`).
     *
     * @return string The element's text content.
     */
    private function extractElement(string $xml, string $tag): string
    {
        $pattern = '#<'.preg_quote($tag, '#').'>(.*?)</'.preg_quote($tag, '#').'>#';
        $matches = [];
        preg_match($pattern, $xml, $matches);

        return ($matches[1] ?? '');

    }//end extractElement()

    /**
     * Extract the `wsse:Password` element's text content, ignoring its
     * `Type` attribute.
     *
     * @param string $xml The XML fragment.
     *
     * @return string The password element's text content.
     */
    private function extractPasswordValue(string $xml): string
    {
        $matches = [];
        preg_match('#<wsse:Password Type="[^"]*">(.*?)</wsse:Password>#', $xml, $matches);

        return ($matches[1] ?? '');

    }//end extractPasswordValue()

    /**
     * A fixture 2048-bit RSA private key (PKCS#1, PEM) used only by the
     * JWT temp-file-hygiene tests below — not a real credential.
     *
     * @var string
     */
    private const RSA_PRIVATE_KEY_PEM = <<<'PEM'
    -----BEGIN RSA PRIVATE KEY-----
    MIIEowIBAAKCAQEAoWByC28xnKMI6XlZxxkt3RfkBe1xzw8I/1y2KvQ6IgYB2rS2
    FXAMh5anF7ReA2c8ly432+6Ka3EHtT3IQ+qwSfxJQEz3k9sdV5NpC7eNuO0PWlZd
    B3U/Ft1pP/0qS43rs146UT/168q25HpXiBej8OsXZLzD9jBB9W3YWmrGyOsk95Ek
    MojwRXW8sMi305Umvs3X/XWMdaixKmySxcCwYKSQM3YWP80TZDdRdKSIq3XxwAnZ
    8jlF6NqztZo1W9cXTq407xCKhrgia6+rn1ViVna+QOze42xZjIxcIRkfvYjHHv3/
    TqOpN3NcYVMn+cxpBdVBF1oH1Q9txjf9eC7saQIDAQABAoIBAAOsgmwoN+TtAULv
    dE/IDvc9l/9ajIC+QuItZihMLxafNGOaQZrzVhWwJFWx0YIaU5LNhpAHOjd/90D1
    Cx4gtaq5h6FjHy/KiTx5KqcNorhXDUZtOOj2jl0i5UaDqPbXYEpRFtrKrfqUPt2s
    u1lp0F2nvHyan4t3RckkmwxT6fqg9pBAFkJDvesb1ULtIKzVpkmXvj5jvQAT272Z
    0CnQc65pgGgVc4WzDmFGB/B3gIjLVOJNfqF6ijr/iPZlB4pG8p643SWJDLzNR3Zm
    bm2ul5niIdqw+e4buI/eIqIvcG/mRCeC5wE01dX8uL49LY4EhceF60bqNzgHfy9o
    U68lJgECgYEA0WROKs7D9ODJceWLkFeLSu0jZPE/edbfWmLnR243AprzbgTX4shf
    sU9b3l/O+NgSidG9c3LhZLIGX95Uy3huMnvQ9LzFzh4CKElMbrj1/FnZK56QWvnT
    q0c3fQFMB7vfUhsFjoizPc3T7edJmoguHWLsRLDPeFXZMMuVe8kI9BkCgYEAxUwd
    e2NtGcny65t47t54ggpIVStxM5h2UxjtUDXNXlpsBGXkRiSgKF+Yqu5+6a8JNYxu
    jnsGQbjtunV4jmH0EHZMdVF8cqeBxVeYoRWN2TVIg2TK6EX3nz4BZZbdzU9we5Lj
    a0OQf4SjFL8G17SFn1xFKN5kckk2X3u/fPhxRNECgYEAr/cuZXUbYlfhklDIR4X6
    bf35J6RBpr93Nfs1x2aM3iifeA6j6lZfbJ93Ydp8Ec1rTtyu7C1X0wp0pu4trkxH
    ty8sO+/D/2Jih76Jd+cB+Y78HVcEkx+tzRttOyTy4vD0TIie09h3YPHvLteWmEHn
    FxUB3vwDbmoeuo3r0nnwh0kCgYABIcaphpCBrV7vaxzugeg/FsADfRRRL3a+U05J
    P4XGHM6x18PPgzZIBQRjNqsTvCVZYUzhFGOczOrQPwxKBNXZolQd+DG2lq9v6mi9
    w9nkfSHFXzaqznv1Ne3cH1l2bBZBHz6exux1TtWAsPfhFPAPUgAzk9MPtMvTEGqw
    1NwRgQKBgDrFVDJ/eRA2T6ZLrgYM/VC1radKEdhCSwMSsqQPAGU94RuZ+vRna4GG
    zjCR8UBbxXgg3Vkm1ZIGUXBo6t7Wjx386rbgnFdvyN21FfLD+5dv0FWFv8ufQyMw
    orFHAN58DRl6weUosC3c017tgftILx/URWlGmxm3jXZ7hjOsig7E
    -----END RSA PRIVATE KEY-----
    PEM;

    /**
     * TC-14 — the private-key temp file `getRSJWK()` allocates is created via
     * `tempnam()` (unpredictable name, NOT the legacy
     * `privatekey-<microtime><pid>` shape) and is `chmod`-ed to `0600` both
     * immediately after allocation and again after the key bytes are
     * written. The file no longer exists once `fetchJWTToken()` returns.
     *
     * @return void
     *
     * @spec openspec/specs/authentication-twig/spec.md#requirement-jwt-token-minting-with-hsrsps-algorithms-req-002
     */
    public function testGetRSJWKTempFileHasMode0600AndUnpredictableName(): void
    {
        $GLOBALS['__oc_tempnam_spy_calls'] = [];
        $GLOBALS['__oc_chmod_spy_calls']   = [];

        $configuration = [
            'payload'   => '{"sub":"x"}',
            'secret'    => base64_encode(self::RSA_PRIVATE_KEY_PEM),
            'algorithm' => 'RS256',
        ];

        $token = $this->service->fetchJWTToken($configuration);

        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        // Exactly one temp file was allocated for the private key.
        $this->assertCount(1, $GLOBALS['__oc_tempnam_spy_calls']);
        $tempPath = $GLOBALS['__oc_tempnam_spy_calls'][0];
        $this->assertIsString($tempPath);

        // Unpredictable tempnam()-generated name, not the legacy
        // `privatekey-<microtime><pid>` shape.
        $this->assertStringContainsString('oc_privatekey_', basename($tempPath));
        $this->assertDoesNotMatchRegularExpression('/^privatekey-[0-9. ]+$/', basename($tempPath));

        // chmod(0600) asserted at least twice for this exact file (immediately
        // after tempnam(), and again after the key bytes were written).
        $chmodCallsForFile = array_values(
            array_filter(
                $GLOBALS['__oc_chmod_spy_calls'],
                static function ($call) use ($tempPath) {
                    return ($call['filename'] === $tempPath);
                }
            )
        );
        $this->assertGreaterThanOrEqual(2, count($chmodCallsForFile));
        foreach ($chmodCallsForFile as $call) {
            $this->assertSame(0600, $call['permissions']);
        }

        // The temp file no longer exists once fetchJWTToken() has returned.
        $this->assertFileDoesNotExist($tempPath);
    }//end testGetRSJWKTempFileHasMode0600AndUnpredictableName()

    /**
     * TC-15 — when `configuration.secret` does not decode to valid RSA key
     * material, `JWKFactory::createFromKeyFile` throws, the exception
     * propagates to the caller, and the temp file created for the (invalid)
     * key material does NOT remain on disk afterward.
     *
     * @return void
     *
     * @spec openspec/specs/authentication-twig/spec.md#requirement-jwt-token-minting-with-hsrsps-algorithms-req-002
     */
    public function testGetRSJWKRemovesTempFileWhenKeyParsingFails(): void
    {
        $GLOBALS['__oc_tempnam_spy_calls'] = [];
        $GLOBALS['__oc_chmod_spy_calls']   = [];

        $configuration = [
            'payload'   => '{"sub":"x"}',
            'secret'    => base64_encode('not a valid RSA private key'),
            'algorithm' => 'RS256',
        ];

        $thrown = null;
        try {
            $this->service->fetchJWTToken($configuration);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'Expected an exception to propagate for invalid key material.');

        $this->assertCount(1, $GLOBALS['__oc_tempnam_spy_calls']);
        $tempPath = $GLOBALS['__oc_tempnam_spy_calls'][0];
        $this->assertFileDoesNotExist($tempPath);
    }//end testGetRSJWKRemovesTempFileWhenKeyParsingFails()
}//end class
}//end namespace
