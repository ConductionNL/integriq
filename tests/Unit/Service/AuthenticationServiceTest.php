<?php

/**
 * Unit tests for AuthenticationService — WS-Security UsernameToken coverage.
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

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\AuthenticationService;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Tests for `AuthenticationService::buildWsSecurityHeader()` (REQ-STUF-012).
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
}//end class
