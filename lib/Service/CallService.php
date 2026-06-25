<?php
/**
 * OpenConnector Call Service.
 *
 * Provides functionality to handle API calls to a specified source within the
 * NextCloud environment. Manages the execution of HTTP requests using the
 * Guzzle HTTP client, while rendering templates with Twig and managing call
 * logs.
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
 *
 * @todo We should test the effect of @Authors & @Package(s) in Class doc-blocks. And add them if possible.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */

namespace OCA\OpenConnector\Service;

use Adbar\Dot;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Twig\AuthenticationExtension;
use OCA\OpenConnector\Twig\AuthenticationRuntimeLoader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

/**
 * Executes outbound API calls against configured Sources and persists CallLog entries.
 */
class CallService
{

    private const BASE_FILENAME_LOCATION = "%s-%s";

    // Retention defaults moved to job_log / call_log x-openregister-archival annotations (adr-004).

    /**
     * Guzzle HTTP client used to dispatch outbound requests.
     *
     * @var Client
     */
    private Client $client;

    /**
     * Twig environment used to render templated configuration values.
     *
     * @var Environment
     */
    private Environment $twig;

    /**
     * Cookie jar shared across calls in the same service instance.
     *
     * @var CookieJar
     */
    private CookieJar $cookieJar;

    /**
     * Retention (ms) applied to error CallLogs.
     *
     * @var integer
     */
    private int $errorRetention;

    /**
     * Retention (ms) applied to successful CallLogs.
     *
     * @var integer
     */
    private int $successRetention;

    /**
     * The constructor sets all needed variables.
     *
     * @param ORObjectService       $objectService         Object service used to persist CallLog rows.
     * @param ArrayLoader           $loader                Twig loader used to render templated config strings.
     * @param AuthenticationService $authenticationService Authentication service exposed to Twig templates.
     * @param IAppConfig            $appConfig             App config used to read global retention overrides.
     * @param LoggerInterface       $logger                Nextcloud logger used for security-policy warnings (#1011).
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        ArrayLoader $loader,
        AuthenticationService $authenticationService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client([]);
        $this->twig   = new Environment($loader);

        // Sandbox the call Twig environment — authentication templates should
        // only need to call the declared auth-helper functions; no PHP method
        // calls on arbitrary objects are permitted.
        $callSandboxPolicy = new SecurityPolicy(
            allowedTags: ['if', 'for', 'set', 'block'],
            allowedFilters: ['upper', 'lower', 'trim', 'default', 'escape', 'raw', 'replace'],
            allowedFunctions: ['oauthToken', 'decosToken', 'jwtToken', 'date', 'max', 'min', 'random'],
        );
        $this->twig->addExtension(new SandboxExtension(policy: $callSandboxPolicy, sandboxed: true));

        $this->twig->addExtension(new AuthenticationExtension());
        $this->twig->addRuntimeLoader(new AuthenticationRuntimeLoader(authenticationService: $authenticationService));
        $this->cookieJar = new CookieJar();

        $this->errorRetention   = 2592000000;
        $this->successRetention = 3600000;
        if ($this->appConfig->hasKey(app: 'openconnector', key: 'retention') === true) {
            $retentionPayload       = json_decode(
                $this->appConfig->getValueString(app: 'openconnector', key: 'retention'),
                true
            );
            $this->errorRetention   = ($retentionPayload['callLogRetention'] ?? 2592000000);
            $this->successRetention = ($retentionPayload['successLogRetention'] ?? 3600000);
        }

    }//end __construct()

    /**
     * Calculates the used retention for created logs.
     *
     * Consists of the maximum of the retention from the source, and the global
     * retention, unless either of both is 0, in which case retention is indefinite.
     *
     * @param integer ...$retentions The list of retentions in milliseconds to find the maximum duration for.
     *
     * @return \DateTime|null The calculated expiry.
     *
     * @throws \DateMalformedStringException On invalid datetime string composition.
     *
     * @TODO: At a later point in time this should be changed to using the most specific source for expiration
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-1
     */
    private function calculateExpires(...$retentions): ?\DateTime
    {
        if (in_array(0, $retentions, true) === true) {
            return null;
        }

        return new \DateTime('now +'.max($retentions).'milliseconds');

    }//end calculateExpires()

    /**
     * Renders a value using Twig templating if the value contains template syntax.
     *
     * If the value is an array, recursively renders each element.
     *
     * @param array|string $value      The value to render, which can be a string or an array.
     * @param array        $sourceData The source data array used as context for rendering templates.
     *
     * @return array|string The rendered value, either as a processed string or an array.
     *
     * @throws LoaderError If there is an error loading a Twig template.
     * @throws SyntaxError If there is a syntax error in a Twig template.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-1
     */
    private function renderValue(array|string $value, array $sourceData): array|string
    {
        if (is_array($value) === false
            && str_contains(haystack: $value, needle: "{{") === true
            && str_contains(haystack: $value, needle: "}}") === true
        ) {
            return $this->twig->createTemplate(template: $value, name: "sourceConfig")->render(context: ['source' => $sourceData]);
        }

        if (is_array($value) === true) {
            $value = array_map(
            function ($value) use ($sourceData) {
                if (is_string($value) === false && is_array($value) === false) {
                    return $value;
                }

                    return $this->renderValue(value: $value, sourceData: $sourceData);
            },
                $value
            );
        }

            return $value;

    }//end renderValue()

    /**
     * Renders configuration values using Twig templating, applying the provided source as context.
     *
     * Recursively processes all values in the configuration array.
     *
     * @param array $configuration The configuration array to render.
     * @param array $sourceData    The source data array used as context for rendering templates.
     *
     * @return array The rendered configuration array.
     *
     * @throws LoaderError If there is an error loading a Twig template.
     * @throws SyntaxError If there is a syntax error in a Twig template.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-1
     */
    private function renderConfiguration(array $configuration, array $sourceData): array
    {
        return array_map(
          function ($value) use ($sourceData) {
            if (is_string($value) === true || is_array($value) === true) {
                return $this->renderValue(value: $value, sourceData: $sourceData);
            }

            return $value;
          },
          $configuration
          );

    }//end renderConfiguration()

    /**
     * Decides method based on configuration and returns that configuration.
     *
     * @param string  $default       The default method, used if no override is set.
     * @param array   $configuration The configuration to find overrides in.
     * @param boolean $read          For GET as default: decides if we are in a list or read (singular) endpoint.
     *
     * @return string
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-1
     */
    private function decideMethod(string $default, array $configuration, bool $read=false): string
    {
        switch ($default) {
            case 'POST':
                if (isset($configuration['createMethod']) === true) {
                    return $configuration['createMethod'];
                }
                return $default;
            case 'PUT':
            case 'PATCH':
                if (isset($configuration['updateMethod']) === true) {
                    return $configuration['updateMethod'];
                }
                return $default;
            case 'DELETE':
                if (isset($configuration['destroyMethod']) === true) {
                    return $configuration['destroyMethod'];
                }
                return $default;
            case 'GET':
            default:
                if (isset($configuration['listMethod']) === true && $read === false) {
                    return $configuration['listMethod'];
                }

                if (isset($configuration['readMethod']) === true && $read === true) {
                    return $configuration['readMethod'];
                }
                return $default;
        }//end switch

    }//end decideMethod()

    /**
     * Writes temporary file.
     *
     * @param string $baseFileName The base filename used as filename prefix.
     * @param string $contents     File contents to write.
     *
     * @return string File location on disk.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-2
     */
    private function writeFile(string $baseFileName, string $contents): string
    {
        // #1012(a): private keys and certs were previously written to
        // /var/tmp/<prefix>-<microtime><pid> with default umask perms
        // (world-readable on shared hosts) and a predictable name. Use
        // tempnam() in the system temp dir for unpredictable names + chmod 0600
        // immediately so the bytes are never readable to other local users.
        $prefix       = 'oc_'.$baseFileName.'_';
        $tempDir      = sys_get_temp_dir();
        $tempLocation = tempnam($tempDir, $prefix);
        if ($tempLocation === false) {
            // Fall back to legacy path; still chmod 0600 so we don't expand the
            // exposure window if tempnam genuinely fails.
            $stamp        = (microtime().getmypid());
            $tempLocation = sprintf($this::BASE_FILENAME_LOCATION, $baseFileName, $stamp);
        }

        // Replace escaped new lines with actual new lines for certificates.
        $contents = str_replace('\n', "\n", $contents);

        // Chmod BEFORE the contents land so the race window between create and
        // chmod is empty (tempnam creates with 0600 on Linux but we re-assert).
        @chmod($tempLocation, 0600);
        file_put_contents($tempLocation, $contents);
        @chmod($tempLocation, 0600);

        return $tempLocation;

    }//end writeFile()

    /**
     * Removes temporary file.
     *
     * @param string $filename Filesystem path to remove.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-2
     */
    private function removeFile($filename): void
    {
        // Silenced — the temp-cert hygiene cleanup must not raise if the file
        // is already gone (e.g. removed by a previous sync-path or never
        // written when tempnam returned false). #1012(a).
        if (is_string($filename) === true && $filename !== '' && file_exists($filename) === true) {
            @unlink($filename);
        }

    }//end removeFile()

    /**
     * Writes the certificate and ssl keys to disk, returns the filenames.
     *
     * @param array $config The configuration as stored in the source.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-2
     */
    public function getCertificate(array &$config)
    {
        if (isset($config['cert']) === true) {
            if (is_array($config['cert']) === true) {
                $config['cert'][0] = $this->writeFile(baseFileName: 'certificate', contents: $config['cert'][0]);
            }

            if (is_array($config['cert']) === false && is_string($config['cert']) === true) {
                $config['cert'] = $this->writeFile(baseFileName: 'certificate', contents: $config['cert']);
            }
        }

        if (isset($config['ssl_key']) === true) {
            if (is_array($config['ssl_key']) === true) {
                $config['ssl_key'][0] = $this->writeFile(baseFileName: 'privateKey', contents: $config['ssl_key'][0]);
            }

            if (is_array($config['ssl_key']) === false && is_string($config['ssl_key']) === true) {
                $config['ssl_key'] = $this->writeFile(baseFileName: 'privateKey', contents: $config['ssl_key']);
            }
        }

        if (isset($config['verify']) === true && is_string($config['verify']) === true) {
            $config['verify'] = $this->writeFile(baseFileName: 'verify', contents: $config['verify']);
        }

    }//end getCertificate()

    /**
     * Removes certificates and private keys from disk if they are not necessary anymore.
     *
     * @param array $config The configuration with filenames.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-2
     */
    public function removeFiles(array $config): void
    {
        if (isset($config['cert']) === true) {
            if (is_array($config['cert']) === true) {
                $filename = $config['cert'][0];
            } else {
                $filename = $config['cert'];
            }

            $this->removeFile(filename: $filename);
        }

        if (isset($config['ssl_key']) === true) {
            if (is_array($config['ssl_key']) === true) {
                $filename = $config['ssl_key'][0];
            } else {
                $filename = $config['ssl_key'];
            }

            $this->removeFile(filename: $filename);
        }

        if (isset($config['verify']) === true && is_string($config['verify']) === true) {
            $this->removeFile(filename: $config['verify']);
        }

    }//end removeFiles()

    /**
     * Formats a nullable DateTime as an ISO-8601 string, or returns null.
     *
     * Extracted from the repeated inline pattern inside call() to avoid duplication.
     *
     * @param \DateTime|null $expires The expiry date/time, or null for indefinite retention.
     *
     * @return string|null ISO-8601 formatted string, or null.
     */
    private function formatExpires(?\DateTime $expires): ?string
    {
        if ($expires !== null) {
            return $expires->format('c');
        }

        return null;

    }//end formatExpires()

    /**
     * Builds and persists an early-exit CallLog (before the HTTP request is made).
     *
     * Used when the source is disabled, has no location, or the rate limit is exceeded.
     *
     * @param ObjectEntity   $source        The source ObjectEntity.
     * @param integer        $statusCode    HTTP-like status code to record (e.g. 409, 429).
     * @param string         $statusMessage Human-readable status message.
     * @param \DateTime|null $expires       Expiry for this log entry.
     *
     * @return ObjectEntity The persisted CallLog ObjectEntity.
     *
     * @throws \OCP\DB\Exception On persistence failure.
     */
    private function saveEarlyErrorLog(
        ObjectEntity $source,
        int $statusCode,
        string $statusMessage,
        ?\DateTime $expires,
    ): ObjectEntity {
        return $this->objectService->saveObject(
            object: [
                'source'        => $source->getUuid(),
                'statusCode'    => $statusCode,
                'statusMessage' => $statusMessage,
                'created'       => (new \DateTime())->format('c'),
                'expires'       => $this->formatExpires(expires: $expires),
            ],
            extend: [],
            register: 'openconnector',
            schema: 'call_log'
        );

    }//end saveEarlyErrorLog()

    /**
     * Computes error and success expiry DateTimes from source retention settings.
     *
     * @param array $sourceData The raw source data array.
     *
     * @return array{errorExpires: \DateTime|null, successExpires: \DateTime|null}
     *
     * @throws \DateMalformedStringException On invalid datetime string composition.
     */
    private function buildExpiryValues(array $sourceData): array
    {
        $errorRetention = (int) ($sourceData['errorRetention'] ?? 0);
        $logRetention   = (int) ($sourceData['logRetention'] ?? 0);

        return [
            'errorExpires'   => $this->calculateExpires(...[($errorRetention * 1000), $this->errorRetention]),
            'successExpires' => $this->calculateExpires(...[($logRetention * 1000), $this->successRetention]),
        ];

    }//end buildExpiryValues()

    /**
     * Resets the source rate-limit counters when the reset window has expired.
     *
     * If rateLimitReset is set and is in the past, clears rateLimitReset and
     * rateLimitRemaining on the source and persists the updated source object.
     *
     * @param ObjectEntity $source     The source ObjectEntity.
     * @param array        $sourceData The mutable source data array (modified in place).
     *
     * @return void
     *
     * @throws \OCP\DB\Exception On persistence failure.
     */
    private function checkAndResetRateLimit(ObjectEntity $source, array &$sourceData): void
    {
        $rateLimitReset     = ($sourceData['rateLimitReset'] ?? null);
        $rateLimitRemaining = ($sourceData['rateLimitRemaining'] ?? null);

        if ($rateLimitReset !== null
            && $rateLimitRemaining !== null
            && $rateLimitReset <= time()
        ) {
            $sourceData['rateLimitReset']     = null;
            $sourceData['rateLimitRemaining'] = null;

            $this->objectService->saveObject(
                object: $sourceData,
                extend: [],
                register: 'openconnector',
                schema: 'source',
                uuid: $source->getUuid()
            );
        }

    }//end checkAndResetRateLimit()

    /**
     * Merges the source-level configuration into the call-level configuration.
     *
     * When the source has a non-empty configuration array, it is applied via
     * applyConfigDot() and merged (recursively) with the call config.
     *
     * @param array $config     The call-level configuration.
     * @param array $sourceData The raw source data array.
     *
     * @return array The merged configuration.
     */
    private function mergeSourceConfiguration(array $config, array $sourceData): array
    {
        if (empty($sourceData['configuration'] ?? []) === false) {
            $config = array_merge_recursive($config, $this->applyConfigDot(config: $sourceData['configuration']));
        }

        return $config;

    }//end mergeSourceConfiguration()

    /**
     * Handles preRequest / postRequest hooks in the config.
     *
     * Fires the preRequest sub-call synchronously (unless already in a support request),
     * strips both keys from config, and returns the captured postRequest data (if any).
     *
     * @param ObjectEntity $source                The source ObjectEntity.
     * @param array        $config                The call configuration (modified in place — preRequest/postRequest
     *                                            removed).
     * @param boolean      $runningSupportRequest Whether we are already inside a support request.
     *
     * @return array|null The postRequest descriptor array, or null if none was set.
     *
     * @throws GuzzleException   On HTTP transport failure during the preRequest call.
     * @throws LoaderError       On Twig loader error.
     * @throws SyntaxError       On Twig syntax error.
     * @throws \OCP\DB\Exception On persistence failure.
     */
    private function extractAndFirePreRequest(
        ObjectEntity $source,
        array &$config,
        bool $runningSupportRequest,
    ): ?array {
        if (isset($config['preRequest']) === true && $runningSupportRequest === false) {
            $this->call(
                source: $source,
                endpoint: $config['preRequest']['endpoint'],
                config: $config['preRequest']['config'],
                runningSupportRequest: true
            );
            unset($config['preRequest']);
        }

        $postRequest = null;
        if (isset($config['postRequest']) === true) {
            $postRequest = $config['postRequest'];
            unset($config['postRequest']);
        }

        return $postRequest;

    }//end extractAndFirePreRequest()

    /**
     * Normalises request headers, pagination, renders Twig placeholders, filters
     * authentication keys, writes TLS certificates to disk, and extracts the logBody flag.
     *
     * Returns the cleaned config array and a boolean indicating whether the response
     * body should be included in the CallLog even for non-error responses.
     *
     * @param array $config     The call configuration to normalise (modified in place).
     * @param array $sourceData The raw source data used for Twig rendering.
     *
     * @return array{config: array, logBody: boolean} Normalised config and logBody flag.
     *
     * @throws LoaderError If there is an error loading a Twig template.
     * @throws SyntaxError If there is a syntax error in a Twig template.
     */

    /**
     * Returns true when the source location points at a loopback interface
     * (localhost, 127.x.x.x, ::1 — including http://, https://, with or
     * without trailing path/port). Used by the verify:false guard (#1011)
     * to exempt local-dev configurations from the TLS-verification policy.
     *
     * @param string $location The raw source location string.
     *
     * @return boolean True when the host portion is loopback.
     */
    private function isLoopbackLocation(string $location): bool
    {
        if ($location === '') {
            return false;
        }

        $host = (string) (parse_url($location, PHP_URL_HOST) ?? '');
        if ($host === '') {
            // Some sources carry just `localhost:8080` with no scheme.
            $location   = preg_replace('/^https?:\/\//i', '', $location);
            $hostNoPath = strstr((string) $location, '/', true);
            if ($hostNoPath !== false) {
                $host = (string) $hostNoPath;
            } else {
                $host = (string) $location;
            }

            $hostNoPort = strstr($host, ':', true);
            if ($hostNoPort !== false) {
                $host = (string) $hostNoPort;
            }
        }

        $host = strtolower(trim($host, "[]"));
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        if (str_starts_with($host, '127.') === true) {
            return true;
        }

        return false;

    }//end isLoopbackLocation()

    /**
     * Validates and clamps incoming X-RateLimit-* header values to bounded,
     * non-hostile ranges before they are persisted on the source. A
     * misconfigured or malicious upstream that returned an `X-RateLimit-Reset`
     * decades in the future could otherwise wedge OpenConnector into a
     * permanent 429 against that source (#1012c).
     *
     * @param mixed  $rawValue The raw value pulled from the response header.
     * @param string $kind     One of: 'reset', 'limit', 'remaining', 'window'.
     *
     * @return integer|null The clamped integer value, or null if the value cannot be sanely cast.
     */
    private function clampRateLimitHeader(mixed $rawValue, string $kind): ?int
    {
        if (is_array($rawValue) === true) {
            $rawValue = reset($rawValue);
        }

        if (is_scalar($rawValue) === false) {
            return null;
        }

        if (is_numeric($rawValue) === false) {
            return null;
        }

        $value = (int) $rawValue;

        // Refuse negative values across the board.
        if ($value < 0) {
            return null;
        }

        switch ($kind) {
            case 'reset':
                // Reset is a Unix timestamp; clamp to "now + 24h" max — anything
                // beyond that is treated as adversarial. Anything in the past is
                // accepted unchanged (already-expired window resets immediately).
                $maxFuture = (time() + 86400);
                if ($value > $maxFuture) {
                    return $maxFuture;
                }
                return $value;

            case 'limit':
            case 'remaining':
            case 'window':
                // Sane upper bound — a million calls/window is generous.
                $maxCounter = 1000000;
                if ($value > $maxCounter) {
                    return $maxCounter;
                }
                return $value;

            default:
                return $value;
        }//end switch

    }//end clampRateLimitHeader()

    /**
     * Normalise Guzzle request config with TLS and secret-redaction guards.
     *
     * @param array $config     Raw Guzzle config array to normalise.
     * @param array $sourceData Source configuration data.
     *
     * @return array The normalised config with TLS and secret guards applied.
     *
     * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md
     */
    private function normaliseRequestConfig(array $config, array $sourceData): array
    {
        // #1011: a source can disable TLS certificate verification by setting
        // `verify: false` in its configuration. Without a guardrail, Guzzle
        // honours it silently and the connection is exposed to MITM — which
        // also leaks any credentials carried in the request. We refuse
        // `verify: false` unless either:
        // - the source location is loopback (localhost / 127.x / [::1]), or
        // - the admin has explicitly opted-in via the NC app config flag
        // `openconnector.allow_insecure_tls = true`.
        if (array_key_exists('verify', $config) === true && $config['verify'] === false) {
            $location            = (string) ($sourceData['location'] ?? '');
            $isLocalhost         = $this->isLoopbackLocation(location: $location);
            $allowInsecureTlsRaw = $this->appConfig->getValueString(
                'openconnector',
                'allow_insecure_tls',
                'false'
            );
            $allowInsecureTls    = filter_var($allowInsecureTlsRaw, FILTER_VALIDATE_BOOLEAN);

            if ($isLocalhost === false && $allowInsecureTls === false) {
                // Force TLS verification back on, log the override.
                $config['verify'] = true;
                $this->logger->warning(
                    'CallService: refusing verify:false on non-localhost source — re-enabled TLS '
                        .'certificate verification. Set IAppConfig openconnector.allow_insecure_tls=true to '
                        .'opt out (NOT recommended in production). #1011',
                    ['location' => $location]
                );
            }
        }//end if

        // Check if the config has a Content-Type header and overwrite it if it does.
        if (isset($config['headers']['Content-Type']) === true) {
            $overwriteContentType = $config['headers']['Content-Type'];
        }

        // Decapitalized fall back for content-type.
        if (isset($config['headers']['content-type']) === true) {
            $overwriteContentType = $config['headers']['content-type'];
        }

        // Make sure we do not have an array of accept headers but just one value.
        if (isset($config['headers']['accept']) === true && is_array($config['headers']['accept']) === true) {
            $config['headers']['accept'] = $config['headers']['accept'][0];
        }

        // Check if the config has a headers array and create it if it doesn't.
        if (isset($config['headers']) === false) {
            $config['headers'] = [];
        }

        if (isset($config['pagination']) === true) {
            $config['query'][$config['pagination']['paginationQuery']] = $config['pagination']['page'];
            unset($config['pagination']);
        }

        $config = $this->renderConfiguration(configuration: $config, sourceData: $sourceData);

        // Set authentication if needed.
        $this->getCertificate(config: $config);

        // Make sure to filter out all the authentication variables / secrets.
        $config = array_filter(
          $config,
          function ($key) {
            return str_contains(strtolower($key), 'authentication') === false;
          },
          ARRAY_FILTER_USE_KEY
          );

        $logBody = (isset($config['logBody']) === true && (bool) $config['logBody']);
        unset($config['logBody']);

        // We want to surpress guzzle exceptions and return the response instead.
        $config['http_errors'] = false;

        return ['config' => $config, 'logBody' => $logBody];

    }//end normaliseRequestConfig()

    /**
     * Dispatches the HTTP request (SOAP or Guzzle) and returns the response.
     *
     * For asynchronous calls this method returns the Guzzle Promise directly
     * (same behaviour as the original inline code — the caller returns it immediately).
     * Removes TLS certificate files from disk after the request completes or fails.
     *
     * @param ObjectEntity $source       The source ObjectEntity.
     * @param string       $method       The HTTP method to use.
     * @param string       $url          The full URL to request (used for Guzzle; SOAP uses $endpoint as the action).
     * @param string       $endpoint     The raw endpoint path (used as the SOAPAction for SOAP sources).
     * @param array        $config       The Guzzle request configuration (passed by reference so cert files can be cleaned up).
     * @param boolean      $asynchronous Whether to dispatch asynchronously.
     *
     * @return mixed A Guzzle Response (sync), a Guzzle Promise (async), or a Response from SOAPService.
     *
     * @throws GuzzleException On HTTP transport failure.
     */
    private function dispatchRequest(
        ObjectEntity $source,
        string $method,
        string $url,
        string $endpoint,
        array &$config,
        bool $asynchronous,
    ): mixed {
        $sourceData = $source->getObject();
        $sourceType = ($sourceData['type'] ?? null);

        if ($sourceType === 'soap') {
            // If the source type is SOAP, use the soap service.
            // Warning: This functionality requires ext-soap and ext-xsd.
            $soapService = new SOAPService($this->cookieJar);
            $response    = $soapService->callSoapSource(source: $source, soapAction: $endpoint, config: $config);
        }

        if ($sourceType !== 'soap') {
            try {
                if ($asynchronous === false) {
                    $response = $this->client->request($method, $url, $config);
                }

                if ($asynchronous === true) {
                    // #1012(b): the async branch previously returned the Promise
                    // immediately and skipped removeFiles + CallLog + rate-limit
                    // handling — temp cert/key files leaked on disk and async
                    // calls were invisible in the log. Attach cleanup to the
                    // promise's then/otherwise so the same hygiene applies
                    // regardless of dispatch mode. The promise contract for the
                    // outer caller remains unchanged: same Promise object,
                    // same eventual response or rejection.
                    // We snapshot the certificate paths NOW because the caller
                    // mutates $config after we return.
                    $certPaths = $this->snapshotCertPaths(config: $config);
                    $promise   = $this->client->requestAsync($method, $url, $config);
                    $promise->then(
                        function ($asyncResponse) use ($certPaths) {
                            $this->removeFiles(config: $certPaths);

                            return $asyncResponse;
                        },
                        function ($asyncReason) use ($certPaths) {
                            $this->removeFiles(config: $certPaths);

                            return $asyncReason;
                        }
                    );

                    return $promise;
                }//end if
            } catch (BadResponseException $e) {
                $this->removeFiles(config: $config);
                $response = $e->getResponse();
            } catch (ConnectException $exception) {
                $this->removeFiles(config: $config);
                $response = new Response(status: 503, body: $exception->getMessage());
            }//end try
        }//end if

        $this->removeFiles(config: $config);

        return $response;

    }//end dispatchRequest()

    /**
     * Snapshot the cert/key/verify file paths from a Guzzle config so a later
     * cleanup callback can find them even after the caller has mutated $config.
     * Used by the async path (#1012b) where the request returns a Promise and
     * the cleanup runs at promise settle time.
     *
     * @param array $config The Guzzle request config.
     *
     * @return array A minimal config array carrying only the paths removeFiles knows about.
     */
    private function snapshotCertPaths(array $config): array
    {
        $snapshot = [];
        if (isset($config['cert']) === true) {
            $snapshot['cert'] = $config['cert'];
        }

        if (isset($config['ssl_key']) === true) {
            $snapshot['ssl_key'] = $config['ssl_key'];
        }

        if (isset($config['verify']) === true && is_string($config['verify']) === true) {
            $snapshot['verify'] = $config['verify'];
        }

        return $snapshot;

    }//end snapshotCertPaths()

    /**
     * Decodes the response body, determines encoding, resolves remote IP, and
     * assembles the structured $data array that contains both request and response info.
     *
     * @param \Psr\Http\Message\ResponseInterface $response  The HTTP response.
     * @param string                              $url       The URL that was called.
     * @param string                              $method    The HTTP method used.
     * @param array                               $config    The Guzzle request config that was sent.
     * @param float                               $timeStart Microtime at request start.
     * @param float                               $timeEnd   Microtime after response received.
     *
     * @return array Structured array with 'request' and 'response' sub-arrays.
     */
    private function buildResponseData(
        \Psr\Http\Message\ResponseInterface $response,
        string $url,
        string $method,
        array $config,
        float $timeStart,
        float $timeEnd,
    ): array {
        $body = $response->getBody()->getContents();

        $isUtf8 = (mb_check_encoding(value: $body, encoding: 'UTF-8') !== false);
        if ($isUtf8 === true) {
            $bodyForLog   = $body;
            $bodyEncoding = 'UTF-8';
        } else {
            $bodyForLog   = base64_encode($body);
            $bodyEncoding = 'base64';
        }

        $remoteIp = $response->getHeaderLine('X-Real-IP');
        if ($remoteIp === '') {
            $remoteIp = $response->getHeaderLine('X-Forwarded-For');
        }

        if ($remoteIp === '') {
            $remoteIp = null;
        }

        // Security: never persist live secrets to the CallLog. Redact secret-bearing
        // locations from the config copy that is written into 'request', from the URL
        // (which may carry query-string secrets), and from the response body (which can
        // echo the request URL with its query secrets). The actual outbound call has
        // already been dispatched with the REAL secrets before this method runs.
        $secretValues   = $this->collectSecretValues(config: $config, url: $url);
        $redactedConfig = $this->redactSecretsFromConfig(config: $config);
        $redactedUrl    = $this->redactSecretsFromUrl(url: $url);
        $bodyForLog     = $this->redactSecretValuesFromString(body: $bodyForLog, secretValues: $secretValues);

        return [
            'request'  => [
                'url'    => $redactedUrl,
                'method' => $method,
                ...$redactedConfig,
            ],
            'response' => [
                'statusCode'    => $response->getStatusCode(),
                'statusMessage' => $response->getReasonPhrase(),
                'responseTime'  => (($timeEnd - $timeStart) * 1000),
                'size'          => $response->getBody()->getSize(),
                'remoteIp'      => $remoteIp,
                'headers'       => $response->getHeaders(),
                'body'          => $bodyForLog,
                'encoding'      => $bodyEncoding,
            ],
        ];

    }//end buildResponseData()

    /**
     * Redacts secret-bearing locations from a Guzzle request config before it is
     * persisted to a CallLog. Operates on a COPY — the caller's config (used for the
     * real outbound request) is never modified.
     *
     * Redacts:
     *  - headers.Authorization / Proxy-Authorization / Cookie / Set-Cookie (case-insensitive name match)
     *  - any header whose value pattern-matches a secret token
     *  - the `auth` basic-auth user/pass array
     *  - query / form_params keys matching the secret-name pattern
     *  - TLS `cert` / `ssl_key` path values
     *
     * @param array $config The Guzzle request config (passed by value).
     *
     * @return array The redacted config copy.
     */
    private function redactSecretsFromConfig(array $config): array
    {
        $placeholder = '***REDACTED***';

        // Header names that always carry credentials (matched case-insensitively).
        $secretHeaderNames = [
            'authorization',
            'proxy-authorization',
            'cookie',
            'set-cookie',
        ];

        if (isset($config['headers']) === true && is_array($config['headers']) === true) {
            foreach ($config['headers'] as $headerName => $headerValue) {
                if (in_array(strtolower((string) $headerName), $secretHeaderNames, true) === true) {
                    $config['headers'][$headerName] = $placeholder;
                    continue;
                }

                // Also redact any header whose name matches the secret-key pattern
                // (covers X-Api-Key, X-Auth-Token, Api-Key, etc.).
                if ($this->isSecretKeyName(name: (string) $headerName) === true) {
                    $config['headers'][$headerName] = $placeholder;
                }
            }
        }

        // Basic-auth array: [user, pass] or [user, pass, type].
        if (isset($config['auth']) === true) {
            $config['auth'] = $placeholder;
        }

        // Query and form parameters with secret-looking keys.
        foreach (['query', 'form_params'] as $bag) {
            if (isset($config[$bag]) === true && is_array($config[$bag]) === true) {
                foreach ($config[$bag] as $paramName => $paramValue) {
                    if ($this->isSecretKeyName(name: (string) $paramName) === true) {
                        $config[$bag][$paramName] = $placeholder;
                    }
                }
            }
        }

        // TLS certificate / private-key paths.
        foreach (['cert', 'ssl_key'] as $tlsKey) {
            if (isset($config[$tlsKey]) === true) {
                $config[$tlsKey] = $placeholder;
            }
        }

        return $config;

    }//end redactSecretsFromConfig()

    /**
     * Returns true when a header/query/form key name looks like it carries a secret.
     *
     * @param string $name The key name to test.
     *
     * @return boolean Whether the name matches the secret pattern.
     */
    private function isSecretKeyName(string $name): bool
    {
        $pattern = '/(token|key|secret|password|passwd|apikey|api[-_]?key|access[-_]?token'
            .'|bearer|auth|signature|assertion|private[-_]?key|x[-_]?api[-_]?token|client[-_]?secret)/i';
        return (preg_match($pattern, $name) === 1);

    }//end isSecretKeyName()

    /**
     * Redacts secret query-string parameters from a URL before persistence.
     *
     * @param string $url The full URL (may contain a query string).
     *
     * @return string The URL with secret query values replaced.
     */
    private function redactSecretsFromUrl(string $url): string
    {
        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $base  = substr($url, 0, $queryStart);
        $query = substr($url, ($queryStart + 1));

        parse_str($query, $params);
        if (empty($params) === true) {
            return $url;
        }

        $changed = false;
        foreach ($params as $paramName => $paramValue) {
            if ($this->isSecretKeyName(name: (string) $paramName) === true) {
                $params[$paramName] = '***REDACTED***';
                $changed            = true;
            }
        }

        if ($changed === false) {
            return $url;
        }

        return ($base.'?'.http_build_query($params));

    }//end redactSecretsFromUrl()

    /**
     * Collects the set of live secret values from the request config and URL so they
     * can be scrubbed from a response body that may echo them back (e.g. an API that
     * reflects the request, or an error page that includes credentials).
     *
     * @param array  $config The Guzzle request config sent for the call.
     * @param string $url    The full request URL (may contain query-string secrets).
     *
     * @return array<int, string> A list of secret string values to redact.
     */
    private function collectSecretValues(array $config, string $url): array
    {
        $values = [];

        // Secret-bearing headers.
        if (isset($config['headers']) === true && is_array($config['headers']) === true) {
            $secretHeaderNames = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];
            foreach ($config['headers'] as $headerName => $headerValue) {
                $lower = strtolower((string) $headerName);
                if (in_array($lower, $secretHeaderNames, true) === true || $this->isSecretKeyName(name: (string) $headerName) === true) {
                    $values = array_merge($values, $this->flattenSecretValue(value: $headerValue));
                }
            }
        }

        // Basic-auth credentials.
        if (isset($config['auth']) === true) {
            $values = array_merge($values, $this->flattenSecretValue(value: $config['auth']));
        }

        // Secret query / form parameters from the config.
        foreach (['query', 'form_params'] as $bag) {
            if (isset($config[$bag]) === true && is_array($config[$bag]) === true) {
                foreach ($config[$bag] as $paramName => $paramValue) {
                    if ($this->isSecretKeyName(name: (string) $paramName) === true) {
                        $values = array_merge($values, $this->flattenSecretValue(value: $paramValue));
                    }
                }
            }
        }

        // Secret query parameters baked into the URL itself.
        $queryStart = strpos($url, '?');
        if ($queryStart !== false) {
            parse_str(substr($url, ($queryStart + 1)), $urlParams);
            foreach ($urlParams as $paramName => $paramValue) {
                if ($this->isSecretKeyName(name: (string) $paramName) === true) {
                    $values = array_merge($values, $this->flattenSecretValue(value: $paramValue));
                }
            }
        }

        // Only keep non-trivial unique string values worth scrubbing.
        $values = array_filter(
            array_unique($values),
            function ($value) {
                return (is_string($value) === true && strlen($value) >= 4);
            }
        );

        return array_values($values);

    }//end collectSecretValues()

    /**
     * Flattens a header/auth/param value (string or array) into a list of strings.
     *
     * @param mixed $value The value to flatten.
     *
     * @return array<int, string> The flattened string values.
     */
    private function flattenSecretValue($value): array
    {
        if (is_array($value) === true) {
            $out = [];
            array_walk_recursive(
                $value,
                function ($item) use (&$out) {
                    if (is_scalar($item) === true) {
                        $out[] = (string) $item;
                    }
                }
            );

            return $out;
        }

        if (is_scalar($value) === true) {
            return [(string) $value];
        }

        return [];

    }//end flattenSecretValue()

    /**
     * Replaces every verbatim occurrence of the supplied secret values in a string
     * (typically a response body for logging) with a placeholder. Also redacts the
     * bare credential after a "Bearer "/"Basic " prefix so reflected Authorization
     * header values are scrubbed even when echoed without the scheme prefix.
     *
     * @param string             $body         The string to scrub.
     * @param array<int, string> $secretValues The secret values to redact.
     *
     * @return string The scrubbed string.
     */
    private function redactSecretValuesFromString(string $body, array $secretValues): string
    {
        if ($body === '' || empty($secretValues) === true) {
            return $body;
        }

        foreach ($secretValues as $secret) {
            if ($secret === '') {
                continue;
            }

            $body = str_replace($secret, '***REDACTED***', $body);

            // Also scrub the credential portion of "Bearer x"/"Basic x" header values.
            foreach (['Bearer ', 'Basic ', 'bearer ', 'basic '] as $scheme) {
                if (str_starts_with($secret, $scheme) === true) {
                    $token = substr($secret, strlen($scheme));
                    if ($token !== '') {
                        $body = str_replace($token, '***REDACTED***', $body);
                    }
                }
            }
        }

        return $body;

    }//end redactSecretValuesFromString()

    /**
     * Applies rate-limit header updates, builds the CallLog payload, persists it, and
     * stitches the full response body back onto the returned ObjectEntity.
     *
     * @param ObjectEntity   $source         The source ObjectEntity.
     * @param array          $sourceData     The mutable source data array.
     * @param array          $data           The structured request/response data from buildResponseData().
     * @param boolean        $logBody        Whether to include the body in the log for non-error responses.
     * @param \DateTime|null $successExpires Expiry for successful log entries.
     * @param \DateTime|null $errorExpires   Expiry for error log entries.
     *
     * @return ObjectEntity The persisted CallLog ObjectEntity with full response body set.
     *
     * @throws \OCP\DB\Exception On persistence failure.
     */
    private function buildAndPersistCallLog(
        ObjectEntity $source,
        array $sourceData,
        array $data,
        bool $logBody,
        ?\DateTime $successExpires,
        ?\DateTime $errorExpires,
    ): ObjectEntity {
        // Update Rate Limit info for the source with the rate limit headers if present or if configured in the source.
        $data['response']['headers'] = $this->sourceRateLimit(source: $source, sourceData: $sourceData, headers: $data['response']['headers']);

        $statusCode   = $data['response']['statusCode'];
        $responseData = $data['response'];

        // Only persist response body for 4xx/5xx errors.
        if ($statusCode < 400 || $statusCode >= 600) {
            if ($logBody !== true) {
                unset($responseData['body']);
            }
        }

        if ($statusCode < 400) {
            $expiresChosen = $successExpires;
        } else {
            $expiresChosen = $errorExpires;
        }

        $callLogData = [
            'source'        => $source->getUuid(),
            'statusCode'    => $statusCode,
            'statusMessage' => $data['response']['statusMessage'],
            'request'       => $data['request'],
            'response'      => $responseData,
            'created'       => (new \DateTime())->format('c'),
            'expires'       => $this->formatExpires(expires: $expiresChosen),
        ];

        $callLog = $this->objectService->saveObject(
            object: $callLogData,
            extend: [],
            register: 'openconnector',
            schema: 'call_log'
        );

        // Set full response (with body) on the returned entity for processing.
        $callLogFullData = $callLog->getObject();
        $callLogFullData['response'] = $data['response'];
        $callLog->setObject($callLogFullData);

        return $callLog;

    }//end buildAndPersistCallLog()

    /**
     * Calls a source according to given configuration.
     *
     * @param ObjectEntity $source                The source ObjectEntity to call.
     * @param string       $endpoint              The endpoint on the source to call.
     * @param string       $method                The method on which to call the source.
     * @param array        $config                The additional configuration to call the source.
     * @param boolean      $asynchronous          Whether to call the source asynchronously.
     * @param boolean      $createCertificates    Whether to create certificates for this source.
     * @param boolean      $overruleAuth          Whether to overrule the source authentication.
     * @param boolean      $read                  Whether this is a singular read (vs list) call.
     * @param boolean      $runningSupportRequest Internal flag set when invoked from preRequest/postRequest hooks.
     *
     * @return ObjectEntity
     *
     * @throws GuzzleException   On HTTP transport failure.
     * @throws LoaderError       On Twig loader error.
     * @throws SyntaxError       On Twig syntax error.
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-1
     */
    public function call(
        ObjectEntity $source,
        string $endpoint='',
        string $method='GET',
        array $config=[],
        bool $asynchronous=false,
        bool $createCertificates=true,
        bool $overruleAuth=false,
        bool $read=false,
        bool $runningSupportRequest=false,
    ): ObjectEntity {
        $sourceData = $source->getObject();

        // Phase 1: Compute expiry values for log retention.
        $expiries       = $this->buildExpiryValues(sourceData: $sourceData);
        $errorExpires   = $expiries['errorExpires'];
        $successExpires = $expiries['successExpires'];

        // Phase 2: Resolve HTTP method; strip method-override keys from config.
        $method = $this->decideMethod(default: $method, configuration: $config, read: $read);
        unset($config['createMethod'], $config['updateMethod'], $config['destroyMethod'], $config['listMethod'], $config['readMethod']);

        // Phase 3: Guard — source must be enabled.
        if (($sourceData['isEnabled'] ?? null) === null || ($sourceData['isEnabled'] ?? false) === false) {
            return $this->saveEarlyErrorLog(
                source: $source,
                statusCode: 409,
                statusMessage: 'This source is not enabled',
                expires: $errorExpires,
            );
        }

        // Phase 4: Guard — source must have a location.
        if (empty($sourceData['location'] ?? '') === true) {
            return $this->saveEarlyErrorLog(
                source: $source,
                statusCode: 409,
                statusMessage: 'This source has no location',
                expires: $errorExpires,
            );
        }

        // Phase 5: Check if Source has a RateLimit and if we need to reset RateLimit-Reset and RateLimit-Remaining.
        $this->checkAndResetRateLimit(source: $source, sourceData: $sourceData);

        // Phase 6: Guard — rate-limit remaining must not be exhausted.
        $rateLimitRemaining = ($sourceData['rateLimitRemaining'] ?? null);
        if ($rateLimitRemaining !== null && $rateLimitRemaining <= 0) {
            return $this->saveEarlyErrorLog(
                source: $source,
                statusCode: 429,
                statusMessage: 'The rate limit for this source has been exceeded. Try again later.',
                expires: $errorExpires,
            );
        }

        // Phase 7: Merge source-level configuration.
        $config = $this->mergeSourceConfiguration(config: $config, sourceData: $sourceData);

        // Phase 8: Handle preRequest hook; capture postRequest descriptor.
        $postRequest = $this->extractAndFirePreRequest(
            source: $source,
            config: $config,
            runningSupportRequest: $runningSupportRequest,
        );

        // Phase 9: Normalise headers, pagination, render Twig, filter auth keys, write certs, extract logBody.
        $normalised = $this->normaliseRequestConfig(config: $config, sourceData: $sourceData);
        $config     = $normalised['config'];
        $logBody    = $normalised['logBody'];

        // Set the URL to call and add an endpoint if needed.
        $url = (($sourceData['location'] ?? '').$endpoint);

        // Let's log the call.
        $sourceData['lastCall'] = (new \DateTime())->format('c');
        // @todo: save the source.
        // Phase 10: Dispatch the HTTP request.
        $timeStart = microtime(true);
        $response  = $this->dispatchRequest(
            source: $source,
            method: $method,
            url: $url,
            endpoint: $endpoint,
            config: $config,
            asynchronous: $asynchronous,
        );

        // Async path returns the Promise directly (same as original behaviour).
        if ($asynchronous === true) {
            return $response;
        }

        $timeEnd = microtime(true);

        // Phase 11: Decode response body and build the structured data array.
        $data = $this->buildResponseData(
            response: $response,
            url: $url,
            method: $method,
            config: $config,
            timeStart: $timeStart,
            timeEnd: $timeEnd,
        );

        // Phase 12: Persist CallLog and get back the entity.
        $callLog = $this->buildAndPersistCallLog(
            source: $source,
            sourceData: $sourceData,
            data: $data,
            logBody: $logBody,
            successExpires: $successExpires,
            errorExpires: $errorExpires,
        );

        // Phase 13: Fire postRequest hook if present.
        if (isset($postRequest) === true && $runningSupportRequest === false) {
            $this->call(source: $source, endpoint: $postRequest['endpoint'], config: $postRequest['config'], runningSupportRequest: true);
        }

        return $callLog;

    }//end call()

    /**
     * Update the source with rate limit info if any of the rate limit headers are found.
     *
     * Else checks if config on the source has been set for Rate Limit. And updates
     * the response headers with this Rate Limit info.
     *
     * @param ObjectEntity $source     The source ObjectEntity to update.
     * @param array        $sourceData The mutable source data array.
     * @param array        $headers    The response headers to check for Rate Limit headers.
     *
     * @return array The updated response headers.
     *
     * @throws \OCP\DB\Exception On persistence failure.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-3
     */
    private function sourceRateLimit(ObjectEntity $source, array $sourceData, array $headers): array
    {
        $changed = false;

        // #1012(c): clamp/validate the rate-limit response headers BEFORE
        // persisting them. A malicious upstream that returned
        // `X-RateLimit-Reset: 99999999999` could otherwise wedge this source
        // into a permanent 429 (the engine's checkRateLimit guard would refuse
        // every subsequent call until the year 5138).
        if (isset($headers['X-RateLimit-Reset']) === true) {
            $clampedReset = $this->clampRateLimitHeader(rawValue: $headers['X-RateLimit-Reset'], kind: 'reset');
            if ($clampedReset !== null) {
                $sourceData['rateLimitReset'] = $clampedReset;
                $changed = true;
            }
        }

        $rateLimitReset     = ($sourceData['rateLimitReset'] ?? null);
        $rateLimitWindow    = ($sourceData['rateLimitWindow'] ?? null);
        $rateLimitLimit     = ($sourceData['rateLimitLimit'] ?? null);
        $rateLimitRemaining = ($sourceData['rateLimitRemaining'] ?? null);

        // If RateLimit-Reset not in headers and source->RateLimit-Reset === null. But source->RateLimit-Window is set.
        if (isset($headers['X-RateLimit-Reset']) === false
            && $rateLimitReset === null
            && $rateLimitWindow !== null
        ) {
            // Set new RateLimit-Reset time on the source.
            $sourceData['rateLimitReset'] = (time() + $rateLimitWindow);
            $rateLimitReset = $sourceData['rateLimitReset'];
            $changed        = true;
        }

        // Check if RateLimit-Limit is present in response headers. If so, save it in the source.
        if (isset($headers['X-RateLimit-Limit']) === true) {
            $clampedLimit = $this->clampRateLimitHeader(rawValue: $headers['X-RateLimit-Limit'], kind: 'limit');
            if ($clampedLimit !== null) {
                $sourceData['rateLimitLimit'] = $clampedLimit;
                $rateLimitLimit = $clampedLimit;
                $changed        = true;
            }
        }

        // Check if RateLimit-Remaining is present in response headers. If so, save it in the source.
        if (isset($headers['X-RateLimit-Remaining']) === true) {
            $clampedRemaining = $this->clampRateLimitHeader(rawValue: $headers['X-RateLimit-Remaining'], kind: 'remaining');
            if ($clampedRemaining !== null) {
                $sourceData['rateLimitRemaining'] = $clampedRemaining;
                $rateLimitRemaining = $clampedRemaining;
                $changed            = true;
            }
        }

        // If RateLimit-Remaining not in headers and source->RateLimit-Limit is set, update source->RateLimit-Remaining.
        if (isset($headers['X-RateLimit-Remaining']) === false && $rateLimitLimit !== null) {
            if ($rateLimitRemaining === null) {
                // Re-set the RateLimit-Remaining on the source.
                $rateLimitRemaining = $rateLimitLimit;
            }

            $sourceData['rateLimitRemaining'] = ($rateLimitRemaining - 1);
            $rateLimitRemaining = $sourceData['rateLimitRemaining'];
            $changed            = true;
        }

        if ($changed === true) {
            $this->objectService->saveObject(
                object: $sourceData,
                extend: [],
                register: 'openconnector',
                schema: 'source',
                uuid: $source->getUuid()
            );
        }

        if ($rateLimitLimit !== null || $rateLimitWindow !== null) {
            $headers = array_merge(
            $headers,
            [
                'X-RateLimit-Limit'     => [(string) $rateLimitLimit],
                'X-RateLimit-Remaining' => [(string) $rateLimitRemaining],
                'X-RateLimit-Reset'     => [(string) $rateLimitReset],
                'X-RateLimit-Used'      => ["1"],
                'X-RateLimit-Window'    => [(string) $rateLimitWindow],
            ]
            );
            ksort($headers);
        }

        return $headers;

    }//end sourceRateLimit()

    /**
     * Uses Adbar Dot to place the values of keys with a dot in it in the $config array
     * to the correct position in the then updated multidimensional $config array.
     *
     * @param array $config The config array.
     *
     * @return array The updated config array.
     *
     * @spec openspec/changes/retrofit-2026-05-24-http-call-engine/tasks.md#task-1
     */
    public function applyConfigDot(array $config): array
    {
        $dotConfig = new Dot($config);
        $unsetKeys = [];

        // Check if there are keys containing a dot we want to map to a different position in the $config array.
        foreach ($config as $key => $value) {
            if (str_contains($key, '.') === true) {
                $dotConfig->set($key, $value);
                $unsetKeys[] = $key;
            }
        }

        // Remove the old keys containing a dot that we mapped to a different position in the $config array.
        $config = $dotConfig->all();
        foreach ($unsetKeys as $key) {
            unset($config[$key]);
        }

        return $config;

    }//end applyConfigDot()
}//end class
