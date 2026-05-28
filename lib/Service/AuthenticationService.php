<?php
/**
 * OpenConnector authentication service.
 *
 * Service class for handling authentication on other services. Builds
 * OAuth/JWT/Decos call options and signs JWT tokens used by outbound calls.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service;


use DateTime;
use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JSONFlattenedSerializer;
use OAuthException;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Service class for handling authentication on other services.
 *
 * @todo We should test the effect of @Authors & @Package(s) in Class doc-blocks. And add them if possible.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UndefinedVariable)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class AuthenticationService
{

    public const REQUIRED_PARAMETERS_CLIENT_CREDENTIALS = [
        'grant_type',
        'scope',
        'authentication',
        'client_id',
        'client_secret',
    ];
    public const REQUIRED_PARAMETERS_PASSWORD           = [
        'grant_type',
        'scope',
        'authentication',
        'username',
        'password',
    ];

    public const REQUIRED_PARAMETERS_JWT = [
        'payload',
        'secret',
        'algorithm',
    ];

    /**
     * Twig environment used for template rendering in authentication flows.
     *
     * @var Environment
     */
    private Environment $twig;

    /**
     * Setting up the class with required service.
     *
     * @param ArrayLoader $loader The ArrayLoader for Twig.
     */
    public function __construct(
        ArrayLoader $loader
    ) {
        $this->twig = new Environment(loader: $loader);

        // Sandbox the authentication Twig environment — templates here expand
        // OAuth/JWT configuration tokens and should not call any PHP methods on
        // injected objects.  Only basic control-flow tags and string filters are
        // needed.
        $authSandboxPolicy = new SecurityPolicy(
            allowedTags: ['if', 'for', 'set'],
            allowedFilters: ['upper', 'lower', 'trim', 'default', 'escape', 'raw', 'replace'],
            allowedFunctions: ['date', 'max', 'min', 'random'],
        );
        $this->twig->addExtension(new SandboxExtension(policy: $authSandboxPolicy, sandboxed: true));
    }//end __construct()

    /**
     * Assert that a token endpoint URL is safe to call (C4: SSRF guard).
     *
     * Blocks URLs that resolve to RFC-1918 private ranges, loopback addresses,
     * the AWS Instance Metadata Service (169.254.169.254), and cloud-internal
     * metadata endpoints.  An admin who can set `tokenUrl` on a source could
     * otherwise pivot to internal services (IMDS, internal APIs, etc.).
     *
     * Allowed schemes: https only (http is rejected to prevent credential
     * exposure in transit and to limit the SSRF blast radius).
     *
     * @param string $url The token endpoint URL to validate.
     *
     * @return void
     *
     * @throws BadRequestException When the URL is missing, uses a disallowed
     *                             scheme, or resolves to a private/loopback host.
     */
    private function assertSafeTokenUrl(string $url): void
    {
        if ($url === '') {
            throw new BadRequestException(message: 'Token URL must not be empty');
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'https') {
            throw new BadRequestException(
                message: 'Token URL scheme not allowed: only https is permitted (got "'.$scheme.'")'
            );
        }

        $host = strtolower(trim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '[]'));
        if ($host === '') {
            throw new BadRequestException(message: 'Token URL does not contain a valid host');
        }

        // Block loopback.
        if ($host === 'localhost' || $host === '::1' || str_starts_with($host, '127.')) {
            throw new BadRequestException(message: 'Token URL resolves to a loopback address - SSRF blocked');
        }

        // Block link-local (AWS IMDS: 169.254.169.254, Azure: 169.254.169.254, GCP: metadata.google.internal).
        if (str_starts_with($host, '169.254.')) {
            throw new BadRequestException(message: 'Token URL resolves to a link-local address - SSRF blocked');
        }

        if ($host === 'metadata.google.internal' || $host === 'metadata') {
            throw new BadRequestException(message: 'Token URL resolves to a cloud metadata endpoint - SSRF blocked');
        }

        // Block RFC-1918 private ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16.
        if (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')) {
            throw new BadRequestException(message: 'Token URL resolves to an RFC-1918 private address - SSRF blocked');
        }

        // 172.16.0.0 – 172.31.255.255 (second octet 16..31).
        if (str_starts_with($host, '172.') === true) {
            $parts = explode('.', $host);
            if (count($parts) >= 2) {
                $secondOctet = (int) $parts[1];
                if ($secondOctet >= 16 && $secondOctet <= 31) {
                    throw new BadRequestException(
                        message: 'Token URL resolves to an RFC-1918 private address - SSRF blocked'
                    );
                }
            }
        }

        // Block the unroutable 0.0.0.0 address.
        if ($host === '0.0.0.0' || $host === '::') {
            throw new BadRequestException(message: 'Token URL resolves to an unroutable address - SSRF blocked');
        }

    }//end assertSafeTokenUrl()

    /**
     * Create call options for OAuth with Client Credentials
     *
     * @param array $configuration Configuration array for authentication.
     *
     * @return array|array[] The call options for OAuth with Client Credentials.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-1
     */
    private function createClientCredentialConfig(array $configuration): array
    {
        $diff = array_diff(self::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS, array_keys(array: $configuration));
        if ($diff !== []) {
            throw new BadRequestException(message: 'Some required parameters are not set: ['.implode(separator: ',', array: $diff).']');
        }

        $callConfig = [
            'form_params' => [
                'grant_type' => $configuration['grant_type'],
                'scope'      => $configuration['scope'],
            ],
        ];

        if ($configuration['authentication'] === 'body') {
            $callConfig['form_params']['client_id']     = $configuration['client_id'];
            $callConfig['form_params']['client_secret'] = $configuration['client_secret'];
        } else if ($configuration['authentication'] === 'basic_auth') {
            $callConfig['auth'] = [
                'username' => $configuration['client_id'],
                'password' => $configuration['client_secret'],
            ];
        }

        // @todo: check for off-cases, i.e. camelCase (not according to OAuth standards).
        $jwtBearer = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
        if (isset($configuration['client_assertion_type']) === true
            && $configuration['client_assertion_type'] === $jwtBearer
        ) {
            $callConfig['form_params']['client_assertion_type'] = $configuration['client_assertion_type'];
            $callConfig['form_params']['client_assertion']      = $this->fetchJWTToken(
                configuration: [
                    'algorithm' => 'PS256',
                    'secret'    => $configuration['private_key'],
                    'x5t'       => $configuration['x5t'],
                    'payload'   => $configuration['payload'],
                ]
            );
        }

        return $callConfig;
    }//end createClientCredentialConfig()

    /**
     * Create call options for OAuth with Password Credentials
     *
     * @param array $configuration Configuration array for authentication.
     *
     * @return array|array[] The call options for OAuth with Password Credentials
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-1
     */
    private function createPasswordConfig(array $configuration): array
    {
        $diff = array_diff(self::REQUIRED_PARAMETERS_PASSWORD, array_keys(array: $configuration));
        if ($diff !== []) {
            throw new BadRequestException(message: 'Some required parameters are not set: ['.implode(separator: ',', array: $diff).']');
        }

        $callConfig = [
            'form_params' => [
                'grant_type' => $configuration['grant_type'],
                'scope'      => $configuration['scope'],
            ],
        ];

        if ($configuration['authentication'] === 'body') {
            $callConfig['form_params']['username'] = $configuration['username'];
            $callConfig['form_params']['password'] = $configuration['password'];
        } else if ($configuration['authentication'] === 'basic_auth') {
            $callConfig['auth'] = [
                'username' => $configuration['username'],
                'password' => $configuration['password'],
            ];
        }

        return $callConfig;
    }//end createPasswordConfig()

    /**
     * Requests an OAuth Access Token with predefined configuration
     *
     * @param array $configuration The configuration for the OAuth call.

     * @return string The resulting access token
     *
     * @throws BadRequestException                     Thrown if the configuration is not compatible with OAuth,
     *                                                 or if the tokenUrl is unsafe (C4: SSRF guard).
     * @throws \GuzzleHttp\Exception\GuzzleException Thrown if the token endpoint does not respond with an access token.
     * @todo   Convert GuzzleException to another error.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-1
     */
    public function fetchOAuthTokens(array $configuration): string
    {
        if (isset($configuration['grant_type']) === false) {
            throw new BadRequestException(message: 'Grant type not set, cannot request token');
        }

        if (isset($configuration['tokenUrl']) === false) {
            throw new BadRequestException(message: 'Token URL not set, cannot request token');
        }

        // C4: SSRF guard — validate the token endpoint before making the outbound call.
        $this->assertSafeTokenUrl(url: (string) $configuration['tokenUrl']);

        switch ($configuration['grant_type']) {
            case 'client_credentials':
                $callConfig = $this->createClientCredentialConfig(configuration: $configuration);
                break;
            case 'password':
                $callConfig = $this->createPasswordConfig(configuration: $configuration);
                break;
            default:
                throw new BadRequestException(message: 'Grant type not supported');
        }

        $client = new Client();

        $response = $client->post(uri: $configuration['tokenUrl'], options: $callConfig);

        $result = json_decode(json: $response->getBody()->getContents(), associative: true);

        if (isset($configuration['tokenLocation']) === true) {
            return $result[$configuration['tokenLocation']];
        }

        return $result['access_token'];
    }//end fetchOAuthTokens()

    /**
     * Fetch an access token from the DeCOS non-implementation of OAuth 2.0
     *
     * @param array $configuration The configuration of the source.
     *
     * @return string The access token
     *
     * @throws BadRequestException                    When the tokenUrl is unsafe (C4: SSRF guard).
     * @throws \GuzzleHttp\Exception\GuzzleException
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-3
     */
    public function fetchDecosToken(array $configuration): string
    {
        $url           = $configuration['tokenUrl'];
        $tokenLocation = $configuration['tokenLocation'];
        unset($configuration['tokenUrl']);

        // C4: SSRF guard — validate the token endpoint before making the outbound call.
        $this->assertSafeTokenUrl(url: (string) $url);

        $callConfig['json'] = $configuration;

        $client   = new Client();
        $response = $client->post(uri: $url, options: $callConfig);

        $result = json_decode(json: $response->getBody()->getContents(), associative: true);

        if (isset($tokenLocation) === true) {
            return $result[$tokenLocation];
        }

        return $result['token'];
    }//end fetchDecosToken()

    /**
     * Get RSA key for RS and PS (asymmetrical) encryption.
     *
     * @param array $configuration The auth configuration for the source.
     *
     * @return JWK|null The resulting JWK key, or null when the secret cannot be parsed.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-2
     */
    private function getRSJWK(array $configuration): ?JWK
    {
        // #1012(a): private keys were previously written to
        // /var/tmp/privatekey-<microtime><pid> with default-umask perms (often
        // world-readable on shared hosting) and a predictable name derived
        // from process metadata. If the process died between
        // file_put_contents and unlink, the key leaked indefinitely.
        // Use tempnam() + chmod 0600 + try/finally so:
        // - the filename is unpredictable,
        // - the bytes are never readable to other local users,
        // - cleanup runs even when JWKFactory::createFromKeyFile throws.
        $filename = tempnam(sys_get_temp_dir(), 'oc_privatekey_');
        if ($filename === false) {
            throw new Exception('Could not allocate a temp file for the private key.');
        }

        @chmod($filename, 0600);
        file_put_contents($filename, base64_decode($configuration['secret']));
        @chmod($filename, 0600);

        try {
            $jwk = JWKFactory::createFromKeyFile(
                $filename,
                null,
                ['use' => 'sig']
            );
        } finally {
            if (file_exists($filename) === true) {
                @unlink($filename);
            }
        }

        return $jwk;
    }//end getRSJWK()

    /**
     * Get OCT key for HS (symmetrical) encryption.
     *
     * The `k` parameter in an oct JWK must be the raw secret encoded as
     * base64url (RFC 4648 §5) — NOT base64 with `addslashes` applied.
     * `addslashes` would corrupt binary secrets and produce non-standard
     * padding, breaking HMAC verification.
     *
     * @param array $configuration The source configuration.
     *
     * @return JWK|null
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-2
     */
    private function getHSJWK(array $configuration): ?JWK
    {
        // Base64url: replace +/ with -_, strip trailing =.
        $base64url = rtrim(strtr(base64_encode($configuration['secret']), '+/', '-_'), '=');
        return new JWK(
            [
                'kty' => 'oct',
                'k'   => $base64url,
            ]
        );
    }//end getHSJWK()

    /**
     * Generates the JWT Payload by rendering the payload before decoding it.
     *
     * @param array $configuration The source auth configuration.
     *
     * @return array The resulting JWT payload.
     *
     * @throws \Twig\Error\LoaderError When the template cannot be loaded.
     * @throws \Twig\Error\SyntaxError When the template has invalid syntax.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-2
     */
    private function getJWTPayload(array $configuration): array
    {
        $renderedPayload = $this->twig->createTemplate($configuration['payload'])->render($configuration);

        return json_decode($renderedPayload, true);
    }//end getJWTPayload()

    /**
     * Gets the JWK key based upon algorithm and secret in the configuration.
     *
     * @param array $configuration The auth configuration for the source.
     *
     * @return JWK|null The resulting JWK key.
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-2
     */
    private function getJWK(array $configuration): ?JWK
    {
        $jwk = null;
        if (in_array(needle: $configuration['algorithm'], haystack: ['HS256', 'HS512']) === true) {
            return $this->getHSJWK(configuration: $configuration);
        } else if (in_array(needle: $configuration['algorithm'], haystack: ['RS256', 'RS384', 'RS512', 'PS256']) === true) {
            return $this->getRSJWK(configuration: $configuration);
        }

        throw new BadRequestException('Algorithm not supported by key generator');
    }//end getJWK()

    /**
     * Generates a signed JWT token based on key, payload and algorithm.
     *
     * @param array       $payload   The payload for the JWT token.
     * @param JWK         $jwk       The JWT Key for the token.
     * @param string      $algorithm The algorithm.
     * @param string|null $x5t       If applicable: the Base64 encoded SHA-1 thumbprint of the used certificate.
     *
     * @return string The serialised JWT token.
     *
     * @throws BadRequestException When JWS building fails — callers must NOT silently swallow this.
     *                             Returning the error message as a Bearer token would send the raw
     *                             exception text to a third-party endpoint (C1).
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-2
     */
    private function generateJWT(array $payload, JWK $jwk, string $algorithm, ?string $x5t=null): string
    {
        $algorithmManager = new AlgorithmManager(
          [
              new HS256(),
              new HS384(),
              new HS512(),
              new RS256(),
              new RS384(),
              new RS512(),
              new PS256(),
          ]
          );
        $jwsBuilder       = new JWSBuilder($algorithmManager);
        $jwsSerializer    = new CompactSerializer();

        $header = ['alg' => $algorithm, 'typ' => 'JWT'];
        if ($x5t !== null) {
            $header['x5t'] = $x5t;
        }

        // C1 fix: rethrow instead of returning the error message as a JWT string.
        // Previously `catch (Exception $e) { return $e->getMessage(); }` would hand the
        // raw error text to the caller as the Bearer token value, silently sending it to
        // the remote service.  Now we let the exception propagate so callers can log and
        // surface a proper error response.
        $jws = $jwsBuilder
            ->create()
            ->withPayload(json_encode($payload))
            ->addSignature($jwk, $header)
            ->build();

        return $jwsSerializer->serialize($jws, 0);
    }//end generateJWT()

    /**
     * Generates a JWT token that can be used for authentication.
     *
     * @param array $configuration The auth configuration for the JWT token. Must at least contain payload, algorithm and secret.
     *
     * @return string The generated JWT token
     *
     * @throws BadRequestException When required parameters are missing or the JWK cannot be formed.
     * @throws Exception           When JWS signing fails (propagated from generateJWT — C1 fix).
     *
     * @spec openspec/changes/retrofit-2026-05-24-authentication-twig/tasks.md#task-2
     */
    public function fetchJWTToken(array $configuration): string
    {
        $diff = array_diff(self::REQUIRED_PARAMETERS_JWT, array_keys(array: $configuration));
        if ($diff !== []) {
            throw new BadRequestException(message: 'Some required parameters are not set: ['.implode(separator: ',', array: $diff).']');
        }

        $payload = $this->getJWTPayload(configuration: $configuration);

        $jwk = $this->getJWK(configuration: $configuration);

        if ($jwk === null) {
            throw new BadRequestException('No JWK key could be formed with given data');
        }

        if (isset($configuration['x5t']) === true) {
            return $this->generateJWT(
                payload: $payload,
                jwk: $jwk,
                algorithm: $configuration['algorithm'],
                x5t: $configuration['x5t']
            );
        }

        return $this->generateJWT(payload: $payload, jwk: $jwk, algorithm: $configuration['algorithm']);
    }//end fetchJWTToken()
}//end class
