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
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;

/**
 * Executes outbound API calls against configured Sources and persists CallLog entries.
 */
class CallService
{

    private const BASE_FILENAME_LOCATION = "%s-%s";

    private const DEFAULT_SUCCESS_LOG_RETENTION = 3600000;

    private const DEFAULT_ERROR_LOG_RETENTION = 2592000000;

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
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        ArrayLoader $loader,
        AuthenticationService $authenticationService,
        IAppConfig $appConfig,
    ) {
        $this->client = new Client([]);
        $this->twig   = new Environment($loader);
        $this->twig->addExtension(new AuthenticationExtension());
        $this->twig->addRuntimeLoader(new AuthenticationRuntimeLoader(authenticationService: $authenticationService));
        $this->cookieJar = new CookieJar();

        $this->errorRetention   = self::DEFAULT_ERROR_LOG_RETENTION;
        $this->successRetention = self::DEFAULT_SUCCESS_LOG_RETENTION;
        if ($appConfig->hasKey(app: 'openconnector', key: 'retention') === true) {
            $retentionPayload       = json_decode(
                $appConfig->getValueString(app: 'openconnector', key: 'retention'),
                true
            );
            $this->errorRetention   = ($retentionPayload['callLogRetention'] ?? self::DEFAULT_ERROR_LOG_RETENTION);
            $this->successRetention = ($retentionPayload['successLogRetention'] ?? self::DEFAULT_SUCCESS_LOG_RETENTION);
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
        $stamp = (microtime().getmypid());
        $baseFileNameLocation = sprintf($this::BASE_FILENAME_LOCATION, $baseFileName, $stamp);

        // Replace escaped new lines with actual new lines for certificates.
        $contents = str_replace('\n', "\n", $contents);

        file_put_contents($baseFileNameLocation, $contents);

        return $baseFileNameLocation;

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
        unlink($filename);

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

        $errorRetention = (int) ($sourceData['errorRetention'] ?? 0);
        $logRetention   = (int) ($sourceData['logRetention'] ?? 0);
        $errorExpires   = $this->calculateExpires(...[($errorRetention * 1000), $this->errorRetention]);
        $successExpires = $this->calculateExpires(...[($logRetention * 1000), $this->successRetention]);

        $method = $this->decideMethod(default: $method, configuration: $config, read: $read);
        unset($config['createMethod'], $config['updateMethod'], $config['destroyMethod'], $config['listMethod'], $config['readMethod']);

        if (($sourceData['isEnabled'] ?? null) === null || ($sourceData['isEnabled'] ?? false) === false) {
            if ($errorExpires !== null) {
                $expiresFormatted = $errorExpires->format('c');
            } else {
                $expiresFormatted = null;
            }

            return $this->objectService->saveObject(
                object: [
                    'source'        => $source->getUuid(),
                    'statusCode'    => 409,
                    'statusMessage' => 'This source is not enabled',
                    'created'       => (new \DateTime())->format('c'),
                    'expires'       => $expiresFormatted,
                ],
                register: 'openconnector',
                schema: 'call_log'
            );
        }

        if (empty($sourceData['location'] ?? '') === true) {
            if ($errorExpires !== null) {
                $expiresFormatted = $errorExpires->format('c');
            } else {
                $expiresFormatted = null;
            }

            return $this->objectService->saveObject(
                object: [
                    'source'        => $source->getUuid(),
                    'statusCode'    => 409,
                    'statusMessage' => 'This source has no location',
                    'created'       => (new \DateTime())->format('c'),
                    'expires'       => $expiresFormatted,
                ],
                register: 'openconnector',
                schema: 'call_log'
            );
        }

        // Check if Source has a RateLimit and if we need to reset RateLimit-Reset and RateLimit-Remaining.
        $rateLimitReset     = ($sourceData['rateLimitReset'] ?? null);
        $rateLimitRemaining = ($sourceData['rateLimitRemaining'] ?? null);
        $rateLimitWindow    = ($sourceData['rateLimitWindow'] ?? null);
        $rateLimitLimit     = ($sourceData['rateLimitLimit'] ?? null);

        if ($rateLimitReset !== null
            && $rateLimitRemaining !== null
            && $rateLimitReset <= time()
        ) {
            $sourceData['rateLimitReset']     = null;
            $sourceData['rateLimitRemaining'] = null;
            $rateLimitReset     = null;
            $rateLimitRemaining = null;

            $this->objectService->saveObject(
                object: $sourceData,
                register: 'openconnector',
                schema: 'source',
                uuid: $source->getUuid()
            );
        }

        // Check if RateLimit-Remaining is set on this source and if limit has been reached.
        if ($rateLimitRemaining !== null && $rateLimitRemaining <= 0) {
            if ($errorExpires !== null) {
                $expiresFormatted = $errorExpires->format('c');
            } else {
                $expiresFormatted = null;
            }

            return $this->objectService->saveObject(
                object: [
                    'source'        => $source->getUuid(),
                    'statusCode'    => 429,
                    'statusMessage' => 'The rate limit for this source has been exceeded. Try again later.',
                    'created'       => (new \DateTime())->format('c'),
                    'expires'       => $expiresFormatted,
                ],
                register: 'openconnector',
                schema: 'call_log'
            );
        }

        // Check if the source has a configuration and merge it with the given config.
        if (empty($sourceData['configuration'] ?? []) === false) {
            $config = array_merge_recursive($config, $this->applyConfigDot(config: $sourceData['configuration']));
        }

        if (isset($config['preRequest']) === true && $runningSupportRequest === false) {
            $this->call(
                source: $source,
                endpoint: $config['preRequest']['endpoint'],
                config: $config['preRequest']['config'],
                runningSupportRequest: true
            );
            unset($config['preRequest']);
        }

        if (isset($config['postRequest']) === true) {
            $postRequest = $config['postRequest'];
            unset($config['postRequest']);
        }

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

        // Set the URL to call and add an endpoint if needed.
        $url = (($sourceData['location'] ?? '').$endpoint);

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

        // Let's log the call.
        $sourceData['lastCall'] = (new \DateTime())->format('c');
        // @todo: save the source.
        // Let's make the call.
        $timeStart = microtime(true);

        $sourceType = ($sourceData['type'] ?? null);

        if ($sourceType === 'soap') {
            // If the source type is SOAP, use the soap service.
            // Warning: This functionality requires ext-soap and ext-xsd.
            $soapService = new SOAPService($this->cookieJar);

            $response = $soapService->callSoapSource(source: $source, soapAction: $endpoint, config: $config);
        }

        if ($sourceType !== 'soap') {
            try {
                if ($asynchronous === false) {
                    $response = $this->client->request($method, $url, $config);
                }

                if ($asynchronous === true) {
                    // @todo: we want to get rate limit headers from async calls as well.
                    return $this->client->requestAsync($method, $url, $config);
                }
            } catch (BadResponseException $e) {
                $this->removeFiles(config: $config);
                $response = $e->getResponse();
            } catch (ConnectException $exception) {
                $this->removeFiles(config: $config);
                $response = new Response(status: 503, body: $exception->getMessage());
            }
        }

        $this->removeFiles(config: $config);

        $timeEnd = microtime(true);

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

        // Let's create the data array.
        $data = [
            'request'  => [
                'url'    => $url,
                'method' => $method,
                ...$config,
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

        // Update Rate Limit info for the source with the rate limit headers if present or if configured in the source.
        $data['response']['headers'] = $this->sourceRateLimit(source: $source, sourceData: $sourceData, headers: $data['response']['headers']);

        // Build call log data.
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

        if ($expiresChosen !== null) {
            $expiresFormatted = $expiresChosen->format('c');
        } else {
            $expiresFormatted = null;
        }

        $callLogData = [
            'source'        => $source->getUuid(),
            'statusCode'    => $statusCode,
            'statusMessage' => $data['response']['statusMessage'],
            'request'       => $data['request'],
            'response'      => $responseData,
            'created'       => (new \DateTime())->format('c'),
            'expires'       => $expiresFormatted,
        ];

        $callLog = $this->objectService->saveObject(
            object: $callLogData,
            register: 'openconnector',
            schema: 'call_log'
        );

        // Set full response (with body) on the returned entity for processing.
        $callLogFullData = $callLog->getObject();
        $callLogFullData['response'] = $data['response'];
        $callLog->setObject($callLogFullData);

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

        // Check if RateLimit-Reset is present in response headers. If so, save it in the source.
        if (isset($headers['X-RateLimit-Reset']) === true) {
            $sourceData['rateLimitReset'] = $headers['X-RateLimit-Reset'];
            $changed = true;
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
            $sourceData['rateLimitLimit'] = $headers['X-RateLimit-Limit'];
            $rateLimitLimit = $sourceData['rateLimitLimit'];
            $changed        = true;
        }

        // Check if RateLimit-Remaining is present in response headers. If so, save it in the source.
        if (isset($headers['X-RateLimit-Remaining']) === true) {
            $sourceData['rateLimitRemaining'] = $headers['X-RateLimit-Remaining'];
            $rateLimitRemaining = $sourceData['rateLimitRemaining'];
            $changed            = true;
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
