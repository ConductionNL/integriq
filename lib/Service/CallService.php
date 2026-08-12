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
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use OCA\OpenConnector\Exception\BrokeredCallConfigurationException;
use OCA\OpenConnector\Flow\FlowConfigGuard;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
use OCA\OpenConnector\Twig\AuthenticationExtension;
use OCA\OpenConnector\Twig\AuthenticationRuntimeLoader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;
use UnexpectedValueException;

/**
 * Executes outbound API calls against configured Sources and persists CallLog entries.
 *
 * @spec openspec/specs/http-call-engine/spec.md
 * @spec openspec/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
 */
class CallService {

	private const BASE_FILENAME_LOCATION = '%s-%s';

	// Retention defaults moved to job_log / call_log x-openregister-archival annotations (adr-004).

	/**
	 * Built-in default RetryPolicy applied when neither the Source nor the
	 * caller configure one. `maxAttempts: 1` reproduces today's single-attempt
	 * dispatch exactly — retries are strictly opt-in per Source/Synchronization.
	 *
	 * @var array<string, mixed>
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007
	 */
	private const RETRY_DEFAULT_POLICY = [
		'maxAttempts' => 1,
		'backoffStrategy' => 'fixed',
		'baseDelayMs' => 500,
		'maxDelayMs' => 30000,
		'jitter' => false,
		'retryableStatusCodes' => [429, 502, 503, 504],
		'retryOnTimeout' => false,
	];

	/**
	 * Default consecutive-failure threshold before the per-Source circuit
	 * breaker opens, when the Source has not configured its own
	 * `circuitBreakerThreshold`. Mirrors {@see \OCA\OpenConnector\Connectors\PdokConnector::BREAKER_THRESHOLD}.
	 *
	 * @var integer
	 */
	private const BREAKER_DEFAULT_THRESHOLD = 5;

	/**
	 * Default cooldown (seconds) an open breaker stays open before the next
	 * dispatch is treated as a half-open probe, when the Source has not
	 * configured its own `circuitBreakerCooldownSeconds`. Mirrors
	 * {@see \OCA\OpenConnector\Connectors\PdokConnector::BREAKER_OPEN_SECONDS}.
	 *
	 * @var integer
	 */
	private const BREAKER_DEFAULT_COOLDOWN_SECONDS = 30;

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
	 * Twig environment used to render the Source-relative upstream PATH of a
	 * `targetType: api` endpoint (ocon#1069).
	 *
	 * Deliberately separate from {@see $twig}: strict on undefined variables,
	 * without the authentication runtime, and without autoescape (values are
	 * percent-encoded for URL-path context instead). See
	 * {@see renderEndpointPath()} for why each of those differs.
	 *
	 * @var Environment
	 */
	private Environment $pathTwig;

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
	 * @param ORObjectService $objectService Object service used to persist CallLog rows.
	 * @param ArrayLoader $loader Twig loader used to render templated config strings.
	 * @param AuthenticationService $authenticationService Authentication service exposed to Twig templates.
	 * @param IAppConfig $appConfig App config used to read global retention overrides.
	 * @param LoggerInterface $logger Nextcloud logger used for security-policy warnings (#1011).
	 * @param BrokeredCallService $brokeredCallService Brokered (credentialRef) dispatch through the OpenRegister credential broker.
	 * @param SensitiveFieldRegistry $sensitiveFieldRegistry Shared secret-name detection registry used for CallLog redaction (secret-hygiene).
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		ArrayLoader $loader,
		AuthenticationService $authenticationService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly BrokeredCallService $brokeredCallService,
		private readonly SensitiveFieldRegistry $sensitiveFieldRegistry,
	) {
		$this->client = new Client([]);
		$this->twig = new Environment($loader);

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

		// The upstream PATH renderer (ocon#1069). Same engine, same sandbox
		// shape, but strict on undefined variables (an unsatisfied placeholder
		// must fail, not silently produce an empty path segment), without the
		// authentication runtime (a URL is logged verbatim, so a credential
		// must not be reachable from a path template) and without autoescape
		// (renderEndpointPath() percent-encodes for URL-path context, which
		// HTML escaping would neither achieve nor be a no-op alongside).
		$this->pathTwig = new Environment(
			$loader,
			[
				'autoescape' => false,
				'strict_variables' => true,
			]
		);
		$this->pathTwig->addExtension(
			new SandboxExtension(
				policy: new SecurityPolicy(
					allowedTags: ['if', 'for', 'set'],
					allowedFilters: ['upper', 'lower', 'trim', 'default', 'replace'],
					allowedFunctions: [],
				),
				sandboxed: true
			)
		);

		$this->cookieJar = new CookieJar();

		$this->errorRetention = 2592000000;
		$this->successRetention = 3600000;
		if ($this->appConfig->hasKey(app: 'openconnector', key: 'retention') === true) {
			$retentionPayload = json_decode(
				$this->appConfig->getValueString(app: 'openconnector', key: 'retention'),
				true
			);
			$this->errorRetention = ($retentionPayload['callLogRetention'] ?? 2592000000);
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
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function calculateExpires(...$retentions): ?\DateTime {
		if (in_array(0, $retentions, true) === true) {
			return null;
		}

		return new DateTime('now +' . max($retentions) . 'milliseconds');
	}//end calculateExpires()

	/**
	 * Renders a value using Twig templating if the value contains template syntax.
	 *
	 * If the value is an array, recursively renders each element.
	 *
	 * @param array|string $value The value to render, which can be a string or an array.
	 * @param array $sourceData The source data array used as context for rendering templates.
	 *
	 * @return array|string The rendered value, either as a processed string or an array.
	 *
	 * @throws LoaderError If there is an error loading a Twig template.
	 * @throws SyntaxError If there is a syntax error in a Twig template.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function renderValue(array|string $value, array $sourceData): array|string {
		if (is_array($value) === false
			&& str_contains(haystack: $value, needle: '{{') === true
			&& str_contains(haystack: $value, needle: '}}') === true
		) {
			return $this->twig->createTemplate(template: $value, name: 'sourceConfig')->render(context: ['source' => $sourceData]);
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
	 * @param array $sourceData The source data array used as context for rendering templates.
	 *
	 * @return array The rendered configuration array.
	 *
	 * @throws LoaderError If there is an error loading a Twig template.
	 * @throws SyntaxError If there is a syntax error in a Twig template.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function renderConfiguration(array $configuration, array $sourceData): array {
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
	 * Render a Source-relative upstream path from an inbound request context,
	 * then refuse it if the RESULT escapes the Source (ocon#1069).
	 *
	 * THE DEFECT
	 * ----------
	 * `EndpointService::handleSourceRequest()` hands the endpoint's authored
	 * `endpoint` string straight to {@see call()}, which concatenates it onto
	 * `source.location` with no substitution at all — Twig runs over
	 * `configuration` (see {@see renderConfiguration()}) and never over the
	 * path. An endpoint whose path is `/repos/{owner}/{repo}/issues` therefore
	 * sent the braces literally upstream, so a `targetType: api` endpoint could
	 * not parameterise its upstream path from the inbound request at all.
	 *
	 * WHY A SEPARATE TWIG ENVIRONMENT
	 * -------------------------------
	 * The engine is the same; two properties deliberately are not.
	 *
	 *  - `strict_variables` is ON. A placeholder the inbound request does not
	 *    satisfy is a failure, not an empty string: silently collapsing
	 *    `/repos/{{owner}}/issues` to `/repos//issues` would dispatch a
	 *    DIFFERENT upstream request and report success.
	 *  - The authentication functions (`oauthToken`, `jwtToken`, …) and the
	 *    `source` context are NOT exposed. `configuration` may carry a secret
	 *    because it is redacted before it reaches a CallLog; a URL is persisted
	 *    to `call_log.request.url` verbatim, so a secret that reaches the path
	 *    is a secret written to the log.
	 *
	 * SSRF CONTAINMENT
	 * ----------------
	 * Two independent layers, because templating means the effective path is
	 * only knowable at execute time:
	 *
	 *  1. Every substituted value is `rawurlencode()`d BEFORE rendering, so an
	 *     inbound value cannot contribute a `/`, `?`, `#` or a control
	 *     character to the path — only the authored literal can.
	 *  2. The FINAL rendered value is put through
	 *     {@see FlowConfigGuard::endpointEscapeCode()} — the app's single
	 *     containment predicate, the same rules `SourceCallNode` applies to a
	 *     rendered flow endpoint — plus an empty-segment check. An absolute
	 *     URL, a scheme-relative `//host`, a `../` traversal or a control
	 *     character is refused before any request is made.
	 *
	 * @param string $endpoint The authored, Source-relative path template.
	 * @param array $context The template context (inbound path/query/body values).
	 *
	 * @return string The rendered, containment-checked path.
	 *
	 * @throws UnexpectedValueException When a placeholder is unsatisfied, when the template
	 *                                  is unparseable, or when the rendered path escapes the
	 *                                  Source location.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function renderEndpointPath(string $endpoint, array $context): string {
		if ($endpoint === '') {
			return '';
		}

		$template = $this->normalisePathPlaceholders(endpoint: $endpoint);

		// Nothing to render, and therefore nothing new to check: an endpoint
		// without placeholders keeps its pre-ocon#1069 behaviour byte for byte.
		if (str_contains(haystack: $template, needle: '{{') === false) {
			return $endpoint;
		}

		try {
			$rendered = $this->pathTwig->createTemplate(template: $template, name: 'endpointPath')
				->render(context: $this->escapeForPathContext(value: $context));
		} catch (\Throwable $exception) {
			throw new UnexpectedValueException(
				'The endpoint path "' . $endpoint . '" could not be rendered from the request: '
				. $exception->getMessage()
			);
		}

		$escapeCode = FlowConfigGuard::endpointEscapeCode(endpoint: $rendered);
		if ($escapeCode !== null) {
			throw new UnexpectedValueException(
				'The rendered endpoint path "' . $rendered . '" is refused before any request is made: '
				. 'it must stay relative to the source location (' . $escapeCode . ').'
			);
		}

		// An empty path segment is what an unsatisfied placeholder used to look
		// like, and `//` is a host separator one normalisation away. Refused
		// rather than normalised, so a broken template is visible.
		if (str_contains(haystack: $rendered, needle: '//') === true) {
			throw new UnexpectedValueException(
				'The rendered endpoint path "' . $rendered . '" is refused before any request is made: '
				. 'it contains an empty path segment.'
			);
		}

		return $rendered;
	}//end renderEndpointPath()

	/**
	 * Rewrite whole-segment OpenAPI-style `{name}` placeholders into Twig.
	 *
	 * Anchored to a COMPLETE `/`-delimited segment holding nothing but a bare
	 * identifier, so a Twig segment (`{{ name }}`, which starts `{{`) can never
	 * match and no partial or expression-bearing text is touched. Both spellings
	 * are accepted because the app already uses `{{ }}` in `endpointArray`,
	 * while `{name}` is the spelling an OpenAPI-shaped upstream path is
	 * written in.
	 *
	 * @param string $endpoint The authored path template.
	 *
	 * @return string The template with single-brace placeholders normalised.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function normalisePathPlaceholders(string $endpoint): string {
		return implode(
			'/',
			array_map(
				static function (string $segment): string {
					$matches = [];
					if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segment, $matches) === 1) {
						return '{{ ' . $matches[1] . ' }}';
					}

					return $segment;
				},
				explode('/', $endpoint)
			)
		);

	}//end normalisePathPlaceholders()

	/**
	 * Percent-encode every scalar in the path template's context.
	 *
	 * Applied BEFORE rendering rather than as a Twig filter, so containment
	 * does not depend on the endpoint author remembering to write one. A value
	 * that has been `rawurlencode()`d cannot contribute a path separator, a
	 * query/fragment delimiter or a control character to the rendered path —
	 * only the authored literal can.
	 *
	 * @param mixed $value The context value.
	 *
	 * @return mixed The value with every scalar percent-encoded.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function escapeForPathContext(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(
				function ($item) {
					return $this->escapeForPathContext(value: $item);
				},
				$value
			);
		}

		if (is_bool($value) === true) {
			if ($value === true) {
				return 'true';
			}

			return 'false';
		}

		if (is_scalar($value) === false) {
			// Null and objects have no defensible path spelling; rendering one
			// would produce an empty segment, which is refused downstream.
			return '';
		}

		return rawurlencode((string)$value);
	}//end escapeForPathContext()

	/**
	 * Decides method based on configuration and returns that configuration.
	 *
	 * @param string $default The default method, used if no override is set.
	 * @param array $configuration The configuration to find overrides in.
	 * @param boolean $read For GET as default: decides if we are in a list or read (singular) endpoint.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function decideMethod(string $default, array $configuration, bool $read = false): string {
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
	 * @param string $contents File contents to write.
	 *
	 * @return string File location on disk.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function writeFile(string $baseFileName, string $contents): string {
		// #1012(a): private keys and certs were previously written to
		// /var/tmp/<prefix>-<microtime><pid> with default umask perms
		// (world-readable on shared hosts) and a predictable name. Use
		// tempnam() in the system temp dir for unpredictable names + chmod 0600
		// immediately so the bytes are never readable to other local users.
		$prefix = 'oc_' . $baseFileName . '_';
		$tempDir = sys_get_temp_dir();
		$tempLocation = tempnam($tempDir, $prefix);
		if ($tempLocation === false) {
			// Fall back to legacy path; still chmod 0600 so we don't expand the
			// exposure window if tempnam genuinely fails.
			$stamp = (microtime() . getmypid());
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
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function removeFile($filename): void {
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
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function getCertificate(array &$config) {
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
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function removeFiles(array $config): void {
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
	private function formatExpires(?\DateTime $expires): ?string {
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
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param integer $statusCode HTTP-like status code to record (e.g. 409, 429).
	 * @param string $statusMessage Human-readable status message.
	 * @param \DateTime|null $expires Expiry for this log entry.
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
				'source' => $source->getUuid(),
				'statusCode' => $statusCode,
				'statusMessage' => $statusMessage,
				'created' => (new DateTime())->format('c'),
				'expires' => $this->formatExpires(expires: $expires),
			],
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
	private function buildExpiryValues(array $sourceData): array {
		$errorRetention = (int)($sourceData['errorRetention'] ?? 0);
		$logRetention = (int)($sourceData['logRetention'] ?? 0);

		return [
			'errorExpires' => $this->calculateExpires(...[($errorRetention * 1000), $this->errorRetention]),
			'successExpires' => $this->calculateExpires(...[($logRetention * 1000), $this->successRetention]),
		];

	}//end buildExpiryValues()

	/**
	 * Resets the source rate-limit counters when the reset window has expired.
	 *
	 * If rateLimitReset is set and is in the past, clears rateLimitReset and
	 * rateLimitRemaining on the source and persists the updated source object.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The mutable source data array (modified in place).
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 */
	private function checkAndResetRateLimit(ObjectEntity $source, array &$sourceData): void {
		$rateLimitReset = ($sourceData['rateLimitReset'] ?? null);
		$rateLimitRemaining = ($sourceData['rateLimitRemaining'] ?? null);

		if ($rateLimitReset !== null
			&& $rateLimitRemaining !== null
			&& $rateLimitReset <= time()
		) {
			$sourceData['rateLimitReset'] = null;
			$sourceData['rateLimitRemaining'] = null;

			// System context (ocon#147): rate-limit bookkeeping is the engine writing back
			// to its own admin-owned config. The `source` schema is admin-only now, so a
			// non-admin-triggered call would otherwise fail to record its own rate-limit
			// state.
			$this->objectService->saveObject(
				object: $sourceData,
				register: 'openconnector',
				schema: 'source',
				uuid: $source->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		}

	}//end checkAndResetRateLimit()

	/**
	 * Merges the source-level configuration into the call-level configuration.
	 *
	 * When the source has a non-empty configuration array, it is applied via
	 * applyConfigDot() and merged (recursively) with the call config.
	 *
	 * @param array $config The call-level configuration.
	 * @param array $sourceData The raw source data array.
	 *
	 * @return array The merged configuration.
	 */
	private function mergeSourceConfiguration(array $config, array $sourceData): array {
		if (empty($sourceData['configuration'] ?? []) === false) {
			$config = array_merge_recursive($config, $this->applyConfigDot(config: $sourceData['configuration']));
		}

		// The source's own `headers` column, which until now was write-only in
		// practice: the schema exposes it, the editor saves it, and nothing ever
		// read it. A source configured with an Authorization header there looked
		// correct on every screen while every call it made came back 401 —
		// measured against the Nextcloud notifications endpoint, where the same
		// header under `configuration.headers` worked immediately.
		//
		// Merged BENEATH what is already set, not over it: `configuration` and
		// the per-call config are the more specific statements, and a source
		// default must not overwrite the header a caller deliberately passed.
		$sourceHeaders = ($sourceData['headers'] ?? null);
		if (is_array($sourceHeaders) === true && $sourceHeaders !== []) {
			$config['headers'] = array_merge($sourceHeaders, ($config['headers'] ?? []));
		}

		return $config;
	}//end mergeSourceConfiguration()

	/**
	 * Handles preRequest / postRequest hooks in the config.
	 *
	 * Fires the preRequest sub-call synchronously (unless already in a support request),
	 * strips both keys from config, and returns the captured postRequest data (if any).
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $config The call configuration (modified in place — preRequest/postRequest
	 *                      removed).
	 * @param boolean $runningSupportRequest Whether we are already inside a support request.
	 *
	 * @return array|null The postRequest descriptor array, or null if none was set.
	 *
	 * @throws GuzzleException On HTTP transport failure during the preRequest call.
	 * @throws LoaderError On Twig loader error.
	 * @throws SyntaxError On Twig syntax error.
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
	 * @param array $config The call configuration to normalise (modified in place).
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
	private function isLoopbackLocation(string $location): bool {
		if ($location === '') {
			return false;
		}

		$host = (string)(parse_url($location, PHP_URL_HOST) ?? '');
		if ($host === '') {
			// Some sources carry just `localhost:8080` with no scheme.
			$location = preg_replace('/^https?:\/\//i', '', $location);
			$hostNoPath = strstr((string)$location, '/', true);
			if ($hostNoPath !== false) {
				$host = (string)$hostNoPath;
			} else {
				$host = (string)$location;
			}

			$hostNoPort = strstr($host, ':', true);
			if ($hostNoPort !== false) {
				$host = (string)$hostNoPort;
			}
		}

		$host = strtolower(trim($host, '[]'));
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
	 * @param mixed $rawValue The raw value pulled from the response header.
	 * @param string $kind One of: 'reset', 'limit', 'remaining', 'window'.
	 *
	 * @return integer|null The clamped integer value, or null if the value cannot be sanely cast.
	 */
	private function clampRateLimitHeader(mixed $rawValue, string $kind): ?int {
		if (is_array($rawValue) === true) {
			$rawValue = reset($rawValue);
		}

		if (is_scalar($rawValue) === false) {
			return null;
		}

		if (is_numeric($rawValue) === false) {
			return null;
		}

		$value = (int)$rawValue;

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
	 * @param array $config Raw Guzzle config array to normalise.
	 * @param array $sourceData Source configuration data.
	 *
	 * @return array The normalised config with TLS and secret guards applied.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-synchronization-engine/tasks.md
	 */
	private function normaliseRequestConfig(array $config, array $sourceData): array {
		// #1011: a source can disable TLS certificate verification by setting
		// `verify: false` in its configuration. Without a guardrail, Guzzle
		// honours it silently and the connection is exposed to MITM — which
		// also leaks any credentials carried in the request. We refuse
		// `verify: false` unless either:
		// - the source location is loopback (localhost / 127.x / [::1]), or
		// - the admin has explicitly opted-in via the NC app config flag
		// `openconnector.allow_insecure_tls = true`.
		if (array_key_exists('verify', $config) === true && $config['verify'] === false) {
			$location = (string)($sourceData['location'] ?? '');
			$isLocalhost = $this->isLoopbackLocation(location: $location);
			$allowInsecureTlsRaw = $this->appConfig->getValueString(
				'openconnector',
				'allow_insecure_tls',
				'false'
			);
			$allowInsecureTls = filter_var($allowInsecureTlsRaw, FILTER_VALIDATE_BOOLEAN);

			if ($isLocalhost === false && $allowInsecureTls === false) {
				// Force TLS verification back on, log the override.
				$config['verify'] = true;
				$this->logger->warning(
					'CallService: refusing verify:false on non-localhost source — re-enabled TLS '
						. 'certificate verification. Set IAppConfig openconnector.allow_insecure_tls=true to '
						. 'opt out (NOT recommended in production). #1011',
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
			// Body-based pagination (oc#94): POST-body sources like TED's v3
			// search take page/query as a static JSON body rather than query
			// parameters, so this substitutes the page value into the body at
			// the configured dot-path instead. Defaults to the pre-existing
			// query-string substitution when `paginationIn` is absent/`query`
			// — byte-for-byte unchanged for every source that doesn't opt in.
			if (($config['pagination']['paginationIn'] ?? 'query') === 'body') {
				$config = $this->applyBodyPagination(config: $config);
			} else {
				$config['query'][$config['pagination']['paginationQuery']] = $config['pagination']['page'];
			}

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

		$logBody = (isset($config['logBody']) === true && (bool)$config['logBody']);
		unset($config['logBody']);

		// We want to surpress guzzle exceptions and return the response instead.
		$config['http_errors'] = false;

		return ['config' => $config, 'logBody' => $logBody];
	}//end normaliseRequestConfig()

	/**
	 * Substitutes the current pagination page value into the JSON request
	 * body at the configured dot-path (oc#94), instead of the query string.
	 *
	 * `$config['body']` at this point is the fresh per-call render of the
	 * source's static `configuration.body` template (re-merged every call by
	 * `mergeSourceConfiguration()` — never accumulated across pages), so
	 * decoding, bumping one path, and re-encoding here cannot compound across
	 * pages. When the body is missing or not valid JSON, substitution starts
	 * from an empty object rather than silently dropping the page value.
	 *
	 * @param array $config The call configuration, carrying `pagination`
	 *                      (`paginationQuery` dot-path + `page` value) and,
	 *                      usually, a `body` JSON string template.
	 *
	 * @return array The config with `body` rewritten to carry the new page value.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-post-body-sources-and-body-based-pagination-req-010
	 */
	private function applyBodyPagination(array $config): array {
		$bodyPath = ($config['pagination']['paginationQuery'] ?? 'page');
		$page = $config['pagination']['page'];

		$decodedBody = json_decode((string)($config['body'] ?? ''), true);
		if (is_array($decodedBody) === false) {
			$decodedBody = [];
		}

		$dot = new Dot($decodedBody);
		$dot->set($bodyPath, $page);
		$config['body'] = json_encode($dot->all());

		return $config;
	}//end applyBodyPagination()

	/**
	 * Dispatches the HTTP request (SOAP or Guzzle) and returns the response.
	 *
	 * For asynchronous calls this method returns the Guzzle Promise directly
	 * (same behaviour as the original inline code — the caller returns it immediately).
	 * Removes TLS certificate files from disk after the request completes or fails.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param string $method The HTTP method to use.
	 * @param string $url The full URL to request (used for Guzzle; SOAP uses $endpoint as the action).
	 * @param string $endpoint The raw endpoint path (used as the SOAPAction for SOAP sources).
	 * @param array $config The Guzzle request configuration (passed by reference so cert files can be cleaned up).
	 * @param boolean $asynchronous Whether to dispatch asynchronously.
	 * @param array|null $brokeredCredential Resolved brokered identity ({credentialId, actingUserId}) from
	 *                                       BrokeredCallService::prepare(), or null for the legacy Guzzle/SOAP path.
	 * @param mixed $sink Optional stream resource. When given, it is passed to Guzzle as the
	 *                    `sink` request option so the response body streams into it instead of
	 *                    being buffered. Kept OUT of $config so it is never logged/persisted
	 *                    (a resource is not JSON-persistable). Guzzle HTTP path only; ignored
	 *                    by the SOAP and brokered branches. Null = unchanged behaviour.
	 * @param callable|null $onHeaders Optional callback invoked with the PSR-7 response once its headers have
	 *                                 arrived and before the body is downloaded (Guzzle `on_headers` option).
	 *                                 Kept OUT of $config for the same reason as $sink — a closure is not
	 *                                 JSON-persistable and must not reach Twig rendering or redaction. Used by
	 *                                 the concurrent file fetcher to gate admission on in-flight
	 *                                 `Content-Length` (ocon#111). Guzzle HTTP path only.
	 *
	 * @return mixed A Guzzle Response (sync), a Guzzle Promise (async), or a Response from SOAPService.
	 *
	 * @throws GuzzleException On HTTP transport failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
	 * @spec openspec/changes/stream-file-content/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering
	 */
	private function dispatchRequest(
		ObjectEntity $source,
		string $method,
		string $url,
		string $endpoint,
		array &$config,
		bool $asynchronous,
		?array $brokeredCredential = null,
		mixed $sink = null,
		?callable $onHeaders = null,
	): mixed {
		// Brokered branch (REQ-SBC-002): a credentialRef source dispatches
		// IN-PROCESS through the OpenRegister credential broker — the internal
		// Guzzle client is NOT invoked. The broker return is adapted to a
		// PSR-7 response so buildResponseData() / buildAndPersistCallLog() /
		// sourceRateLimit() run unchanged (one write path, CallLog parity).
		if ($brokeredCredential !== null) {
			return $this->brokeredCallService->dispatch(
				credentialId: (string)$brokeredCredential['credentialId'],
				actingUserId: $brokeredCredential['actingUserId'],
				method: $method,
				url: $url,
				config: $config,
			);
		}

		$sourceData = $source->getObject();
		$sourceType = ($sourceData['type'] ?? null);

		if ($sourceType === 'soap') {
			// If the source type is SOAP, use the soap service.
			// Warning: This functionality requires ext-soap and ext-xsd.
			$soapService = new SOAPService($this->cookieJar);
			$response = $soapService->callSoapSource(source: $source, soapAction: $endpoint, config: $config);
		}

		if ($sourceType !== 'soap') {
			// Stream the response body straight into the caller's sink resource when
			// one is supplied (stream-file-content #110). The sink is added only to the
			// options handed to Guzzle — never to $config, which is logged/redacted/
			// persisted and cannot carry a resource.
			$requestOptions = $this->buildRequestOptions(config: $config, sink: $sink, onHeaders: $onHeaders);

			try {
				if ($asynchronous === false) {
					$response = $this->client->request($method, $url, $requestOptions);
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
					$promise = $this->client->requestAsync($method, $url, $requestOptions);
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
	 * Assemble the options handed to Guzzle from the persisted request config
	 * plus the two transport-only extras.
	 *
	 * `sink` and `on_headers` are added HERE and never merged into `$config`.
	 * `$config` is Twig-rendered, secret-redacted and persisted into
	 * `call_log.request`, and neither a stream/path sink nor a closure belongs in
	 * a JSON payload — a closure cannot even be encoded. Keeping the split in one
	 * small helper also keeps {@see dispatchRequest()} under its complexity
	 * budget as transport-only options accumulate.
	 *
	 * @param array $config The Guzzle request configuration (logged and persisted).
	 * @param mixed $sink Optional sink (a temp-file PATH for the streaming download path), or null.
	 * @param callable|null $onHeaders Optional response-headers callback, or null.
	 *
	 * @return array The request options to hand to the Guzzle client.
	 *
	 * @spec openspec/changes/stream-file-content/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable
	 */
	private function buildRequestOptions(array $config, mixed $sink, ?callable $onHeaders): array {
		if ($sink !== null) {
			$config['sink'] = $sink;
		}

		// Guzzle invokes this once the response headers have arrived and before
		// the body is downloaded — which is what lets the concurrent file fetcher
		// gate admission on in-flight `Content-Length` (ocon#111).
		if ($onHeaders !== null) {
			$config['on_headers'] = $onHeaders;
		}

		return $config;
	}//end buildRequestOptions()

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
	private function snapshotCertPaths(array $config): array {
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
	 * @param \Psr\Http\Message\ResponseInterface $response The HTTP response.
	 * @param string $url The URL that was called.
	 * @param string $method The HTTP method used.
	 * @param array $config The Guzzle request config that was sent.
	 * @param float $timeStart Microtime at request start.
	 * @param float $timeEnd Microtime after response received.
	 *
	 * @return array Structured array with 'request' and 'response' sub-arrays.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
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
			$bodyForLog = $body;
			$bodyEncoding = 'UTF-8';
		} else {
			$bodyForLog = base64_encode($body);
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
		$secretValues = $this->collectSecretValues(config: $config, url: $url);
		$redactedConfig = $this->redactSecretsFromConfig(config: $config);
		$redactedUrl = $this->redactSecretsFromUrl(url: $url);
		$bodyForLog = $this->redactSecretValuesFromString(body: $bodyForLog, secretValues: $secretValues);

		return [
			'request' => [
				'url' => $redactedUrl,
				'method' => $method,
				...$redactedConfig,
			],
			'response' => [
				'statusCode' => $response->getStatusCode(),
				'statusMessage' => $response->getReasonPhrase(),
				'responseTime' => (($timeEnd - $timeStart) * 1000),
				'size' => $response->getBody()->getSize(),
				'remoteIp' => $remoteIp,
				'headers' => $response->getHeaders(),
				'body' => $bodyForLog,
				'encoding' => $bodyEncoding,
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
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	private function redactSecretsFromConfig(array $config): array {
		$placeholder = '***REDACTED***';

		// Header names that always carry credentials (matched case-insensitively).
		$secretHeaderNames = [
			'authorization',
			'proxy-authorization',
			'cookie',
			'set-cookie',
		];

		if (isset($config['headers']) === true && is_array($config['headers']) === true) {
			foreach (array_keys($config['headers']) as $headerName) {
				if (in_array(strtolower((string)$headerName), $secretHeaderNames, true) === true) {
					$config['headers'][$headerName] = $placeholder;
					continue;
				}

				// Also redact any header whose name matches the secret-key pattern
				// (covers X-Api-Key, X-Auth-Token, Api-Key, etc.).
				if ($this->isSecretKeyName(name: (string)$headerName) === true) {
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
				foreach (array_keys($config[$bag]) as $paramName) {
					if ($this->isSecretKeyName(name: (string)$paramName) === true) {
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
	 * Delegates to the shared {@see SensitiveFieldRegistry} (secret-hygiene) — a pure
	 * extraction, behaviour-preserving: same regex, same header list, same result.
	 *
	 * @param string $name The key name to test.
	 *
	 * @return boolean Whether the name matches the secret pattern.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	private function isSecretKeyName(string $name): bool {
		return $this->sensitiveFieldRegistry->isSensitiveName(name: $name);
	}//end isSecretKeyName()

	/**
	 * Redacts secret query-string parameters from a URL before persistence.
	 *
	 * @param string $url The full URL (may contain a query string).
	 *
	 * @return string The URL with secret query values replaced.
	 */
	private function redactSecretsFromUrl(string $url): string {
		$queryStart = strpos($url, '?');
		if ($queryStart === false) {
			return $url;
		}

		$base = substr($url, 0, $queryStart);
		$query = substr($url, ($queryStart + 1));

		parse_str($query, $params);
		if (empty($params) === true) {
			return $url;
		}

		$changed = false;
		foreach (array_keys($params) as $paramName) {
			if ($this->isSecretKeyName(name: (string)$paramName) === true) {
				$params[$paramName] = '***REDACTED***';
				$changed = true;
			}
		}

		if ($changed === false) {
			return $url;
		}

		return ($base . '?' . http_build_query($params));
	}//end redactSecretsFromUrl()

	/**
	 * Collects the set of live secret values from the request config and URL so they
	 * can be scrubbed from a response body that may echo them back (e.g. an API that
	 * reflects the request, or an error page that includes credentials).
	 *
	 * @param array $config The Guzzle request config sent for the call.
	 * @param string $url The full request URL (may contain query-string secrets).
	 *
	 * @return array<int, string> A list of secret string values to redact.
	 */
	private function collectSecretValues(array $config, string $url): array {
		$values = [];

		// Secret-bearing headers.
		if (isset($config['headers']) === true && is_array($config['headers']) === true) {
			$secretHeaderNames = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];
			foreach ($config['headers'] as $headerName => $headerValue) {
				$lower = strtolower((string)$headerName);
				if (in_array($lower, $secretHeaderNames, true) === true || $this->isSecretKeyName(name: (string)$headerName) === true) {
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
					if ($this->isSecretKeyName(name: (string)$paramName) === true) {
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
				if ($this->isSecretKeyName(name: (string)$paramName) === true) {
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
	private function flattenSecretValue($value): array {
		if (is_array($value) === true) {
			$out = [];
			array_walk_recursive(
				$value,
				function ($item) use (&$out) {
					if (is_scalar($item) === true) {
						$out[] = (string)$item;
					}
				}
			);

			return $out;
		}

		if (is_scalar($value) === true) {
			return [(string)$value];
		}

		return [];
	}//end flattenSecretValue()

	/**
	 * Replaces every verbatim occurrence of the supplied secret values in a string
	 * (typically a response body for logging) with a placeholder. Also redacts the
	 * bare credential after a "Bearer "/"Basic " prefix so reflected Authorization
	 * header values are scrubbed even when echoed without the scheme prefix.
	 *
	 * @param string $body The string to scrub.
	 * @param array<int, string> $secretValues The secret values to redact.
	 *
	 * @return string The scrubbed string.
	 */
	private function redactSecretValuesFromString(string $body, array $secretValues): string {
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
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The mutable source data array.
	 * @param array $data The structured request/response data from buildResponseData().
	 * @param boolean $logBody Whether to include the body in the log for non-error responses.
	 * @param \DateTime|null $successExpires Expiry for successful log entries.
	 * @param \DateTime|null $errorExpires Expiry for error log entries.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, when present.
	 * @param boolean $persistLog When false, return a transient (unsaved) CallLog and mutate nothing.
	 *
	 * @return ObjectEntity The persisted CallLog ObjectEntity with full response body set.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call-log-sessionid-req-011
	 */
	private function buildAndPersistCallLog(
		ObjectEntity $source,
		array $sourceData,
		array $data,
		bool $logBody,
		?\DateTime $successExpires,
		?\DateTime $errorExpires,
		?ExecutionTraceContext $trace = null,
		bool $persistLog = true,
	): ObjectEntity {
		// Interactive "Test connection" path (persistLog=false): return the response
		// WITHOUT the expensive CallLog write. Persisting a CallLog runs a full
		// OpenRegister object save (thousands of queries on an install with many magic
		// tables) and mutates the source's rate-limit state — neither is wanted for a
		// one-off UI verification, and doing them is what made the test endpoint slow
		// enough to time out. The engine's real call paths (sync/endpoint/job) keep
		// persistLog=true and are unchanged.
		if ($persistLog === false) {
			return $this->buildTransientCallLog(source: $source, data: $data, trace: $trace);
		}

		// Update Rate Limit info for the source with the rate limit headers if present or if configured in the source.
		$data['response']['headers'] = $this->sourceRateLimit(source: $source, sourceData: $sourceData, headers: $data['response']['headers']);

		$statusCode = $data['response']['statusCode'];
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
			'source' => $source->getUuid(),
			'statusCode' => $statusCode,
			'statusMessage' => $data['response']['statusMessage'],
			'request' => $data['request'],
			'response' => $responseData,
			'created' => (new DateTime())->format('c'),
			'expires' => $this->formatExpires(expires: $expiresChosen),
		];

		// Execution-trace REQ-011: repurpose the previously-dead
		// call_log.sessionId field to carry the active trace's traceId, so
		// any existing call_log consumer can join on sessionId = traceId
		// without a migration (design.md Decision 5). Left unset — exactly
		// as today — when no trace context is active.
		if ($trace !== null) {
			$callLogData['sessionId'] = $trace->getTraceId();
		}

		$callLog = $this->objectService->saveObject(
			object: $callLogData,
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
	 * Builds a transient (unsaved) CallLog for the interactive test path.
	 *
	 * Same `{source, statusCode, statusMessage, request, response, created}` shape as a
	 * persisted CallLog — so every caller that reads `getObject()['response']` works
	 * unchanged — but it is NEVER written to OpenRegister and never mutates the source's
	 * rate-limit state. This keeps a UI "Test connection" fast and side-effect-free: no
	 * heavy object save, no test-noise CallLog rows, no source mutation.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $data The structured request/response data from buildResponseData().
	 * @param ExecutionTraceContext|null $trace The active execution trace context, when present.
	 *
	 * @return ObjectEntity An in-memory CallLog carrying the full request/response.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-req-006-calllog-request-response-redaction-before-persistence
	 */
	private function buildTransientCallLog(
		ObjectEntity $source,
		array $data,
		?ExecutionTraceContext $trace = null,
	): ObjectEntity {
		$callLogData = [
			'source' => $source->getUuid(),
			'statusCode' => $data['response']['statusCode'],
			'statusMessage' => $data['response']['statusMessage'],
			'request' => $data['request'],
			'response' => $data['response'],
			'created' => (new DateTime())->format('c'),
		];

		if ($trace !== null) {
			$callLogData['sessionId'] = $trace->getTraceId();
		}

		$callLog = new ObjectEntity();
		$callLog->setObject($callLogData);

		return $callLog;
	}//end buildTransientCallLog()

	/**
	 * Phases 3-6 helper: source-precondition guards before any dispatch work.
	 *
	 * Verbatim extraction of the pre-existing guards from call(): the source
	 * must be enabled (409), must have a location (409), rate-limit windows
	 * are reset when expired, and an exhausted rate limit short-circuits with
	 * a 429. Returns the persisted early-error CallLog, or null when the call
	 * may proceed.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The mutable source data array (rate-limit reset mutates it).
	 * @param \DateTime|null $errorExpires Expiry for error log entries.
	 *
	 * @return ObjectEntity|null The early-error CallLog, or null to proceed.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function guardCallPreconditions(
		ObjectEntity $source,
		array &$sourceData,
		?\DateTime $errorExpires,
	): ?ObjectEntity {
		// Phase 3: Guard — source must be enabled.
		$isEnabled = ($sourceData['isEnabled'] ?? null);
		if ($isEnabled === null || $isEnabled === false) {
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

		return null;
	}//end guardCallPreconditions()

	/**
	 * Circuit breaker precondition guard (REQ-008): evaluated after the
	 * enabled/location/rate-limit guards, before any dispatch attempt (sync
	 * or async). When the breaker is fully open (within its cooldown
	 * window), short-circuits with a synthetic 503 CallLog and consumes no
	 * retry attempt. When the cooldown has elapsed, the breaker is treated
	 * as half-open for exactly the next dispatch — `circuitBreakerLastProbeAt`
	 * is stamped (best-effort probe guard, not a distributed lock) and the
	 * call proceeds.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The mutable source data array (probe timestamp mutates it).
	 * @param \DateTime|null $errorExpires Expiry for the synthetic error log entry.
	 *
	 * @return ObjectEntity|null The synthetic 503 CallLog, or null to proceed.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 */
	private function guardCircuitBreaker(
		ObjectEntity $source,
		array &$sourceData,
		?\DateTime $errorExpires,
	): ?ObjectEntity {
		$state = (string)($sourceData['circuitBreakerState'] ?? 'closed');
		if ($state !== 'open') {
			return null;
		}

		$cooldown = (int)($sourceData['circuitBreakerCooldownSeconds'] ?? self::BREAKER_DEFAULT_COOLDOWN_SECONDS);
		$openedAt = (int)($sourceData['circuitBreakerOpenedAt'] ?? 0);

		if ((time() - $openedAt) < $cooldown) {
			// Fully open — no HTTP request is dispatched, no attempt is consumed.
			return $this->saveEarlyErrorLog(
				source: $source,
				statusCode: 503,
				statusMessage: 'Circuit breaker is open for this source',
				expires: $errorExpires,
			);
		}

		// Cooldown elapsed — half-open. Stamp the probe timestamp (best-effort
		// guard against concurrent probes, not a distributed lock — see
		// design.md Trade-offs) and let exactly this dispatch through.
		$sourceData['circuitBreakerLastProbeAt'] = time();
		$this->persistSourceState(source: $source, sourceData: $sourceData);

		return null;
	}//end guardCircuitBreaker()

	/**
	 * Resolves the effective RetryPolicy for a dispatch by merging, in order
	 * (later layers override earlier ones on a per-key basis): the built-in
	 * default, the dispatching Source's `retryPolicy` field, and the
	 * caller-supplied override (populated by SynchronizationService from
	 * `Synchronization.retryPolicyOverride`).
	 *
	 * @param array $sourceData The raw source data array.
	 * @param array|null $override The caller-supplied `$config['retryPolicy']` override, or null.
	 *
	 * @return array<string, mixed> The resolved effective RetryPolicy.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007
	 */
	private function resolveEffectiveRetryPolicy(array $sourceData, ?array $override): array {
		$policy = self::RETRY_DEFAULT_POLICY;

		$sourcePolicy = ($sourceData['retryPolicy'] ?? []);
		if (is_array($sourcePolicy) === true) {
			foreach ($sourcePolicy as $key => $value) {
				if (array_key_exists($key, $policy) === true) {
					$policy[$key] = $value;
				}
			}
		}

		if (is_array($override) === true) {
			foreach ($override as $key => $value) {
				if (array_key_exists($key, $policy) === true) {
					$policy[$key] = $value;
				}
			}
		}

		$policy['maxAttempts'] = max(1, (int)$policy['maxAttempts']);
		if (is_array($policy['retryableStatusCodes']) === false) {
			$policy['retryableStatusCodes'] = self::RETRY_DEFAULT_POLICY['retryableStatusCodes'];
		}

		return $policy;
	}//end resolveEffectiveRetryPolicy()

	/**
	 * Sleeps for the backoff delay computed from the resolved RetryPolicy for
	 * the given (1-based) attempt number that just failed.
	 *
	 * `fixed`: delayMs = baseDelayMs. `exponential`: delayMs = min(baseDelayMs
	 * * 2^(attempt-1), maxDelayMs). `jitter: true` adjusts the result by
	 * +/-10% using a uniform random offset (mirrors
	 * {@see \OCA\OpenConnector\Connectors\PdokConnector::sleepBackoff()}).
	 *
	 * @param array $policy The resolved effective RetryPolicy.
	 * @param integer $attempt The 1-based attempt number that just failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007
	 */
	private function sleepRetryBackoff(array $policy, int $attempt): void {
		$base = (int)$policy['baseDelayMs'];

		if (($policy['backoffStrategy'] ?? 'fixed') === 'exponential') {
			$delay = (int)min(($base * (2 ** ($attempt - 1))), (int)$policy['maxDelayMs']);
		} else {
			$delay = $base;
		}

		if (($policy['jitter'] ?? false) === true) {
			$jitterRange = (int)round($delay * 0.1);
			if ($jitterRange > 0) {
				$delay += random_int(-$jitterRange, $jitterRange);
			}
		}

		if ($delay > 0) {
			usleep((max(0, $delay) * 1000));
		}

	}//end sleepRetryBackoff()

	/**
	 * Phase 10 helper: dispatches the synchronous HTTP request, honouring
	 * the resolved RetryPolicy (REQ-007) and recording circuit breaker
	 * outcomes (REQ-008) around each attempt. Extracted out of {@see call()}
	 * to keep that method's own cyclomatic complexity in check — this is
	 * the entire bounded retry loop, unchanged in behaviour from its
	 * previous inline form.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param string $method The HTTP method to use.
	 * @param string $url The full URL to request.
	 * @param string $endpoint The raw endpoint path (SOAPAction for SOAP sources).
	 * @param array $config The Guzzle request configuration (passed by reference).
	 * @param array|null $brokeredCredential Resolved brokered identity, or null for the legacy path.
	 * @param array $sourceData The mutable source data array (breaker bookkeeping mutates it).
	 * @param array|null $retryPolicyOverride The caller-supplied `$config['retryPolicy']` override, or null.
	 * @param mixed $sink Optional stream resource passed through to dispatchRequest() as the
	 *                    Guzzle `sink` option (stream-file-content #110); null = unchanged.
	 *
	 * @return array{response: mixed, timeStart: float, timeEnd: float} The final response plus dispatch timing.
	 *
	 * @throws GuzzleException On HTTP transport failure (final attempt only; see loop body).
	 * @throws \OCP\DB\Exception On persistence failure of breaker bookkeeping.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 */
	private function dispatchWithRetry(
		ObjectEntity $source,
		string $method,
		string $url,
		string $endpoint,
		array &$config,
		?array $brokeredCredential,
		array $sourceData,
		?array $retryPolicyOverride,
		mixed $sink = null,
	): array {
		$retryPolicy = $this->resolveEffectiveRetryPolicy(sourceData: $sourceData, override: $retryPolicyOverride);

		$timeStart = microtime(true);
		$attempt = 0;
		// Pre-declared so static analysis can prove the post-loop read is
		// always non-null: every loop iteration either assigns $response
		// before falling through to `break`, or `continue`s/`throw`s without
		// reaching the return below — the defensive guard after the loop is
		// therefore unreachable at runtime, but makes that guarantee explicit.
		$response = null;
		do {
			$attempt++;

			try {
				$response = $this->dispatchRequest(
					source: $source,
					method: $method,
					url: $url,
					endpoint: $endpoint,
					config: $config,
					asynchronous: false,
					brokeredCredential: $brokeredCredential,
					sink: $sink,
				);
			} catch (\Throwable $exception) {
				// Note: dispatchRequest() already converts a Guzzle
				// BadResponseException/ConnectException into a Response
				// internally for the plain HTTP path; a \Throwable escaping
				// here is a transport-level failure from the SOAP/brokered
				// branches (or an otherwise-uncaught Guzzle exception type).
				// Zero behaviour change when retryOnTimeout is unset/false or
				// this was the last attempt — rethrow exactly as before.
				if ($retryPolicy['retryOnTimeout'] !== true || $attempt >= $retryPolicy['maxAttempts']) {
					throw $exception;
				}

				$this->recordBreakerFailure(source: $source, sourceData: $sourceData);
				$this->sleepRetryBackoff(policy: $retryPolicy, attempt: $attempt);
				continue;
			}//end try

			$statusCode = $response->getStatusCode();
			if (in_array($statusCode, $retryPolicy['retryableStatusCodes'], true) === true) {
				$this->recordBreakerFailure(source: $source, sourceData: $sourceData);

				if ($attempt >= $retryPolicy['maxAttempts']) {
					// Attempts exhausted — keep the last response, exactly as
					// the pre-existing single-attempt behaviour would have.
					break;
				}

				$this->sleepRetryBackoff(policy: $retryPolicy, attempt: $attempt);
				continue;
			}

			if ($statusCode < 400) {
				$this->recordBreakerSuccess(source: $source, sourceData: $sourceData);
			}

			break;
		} while (true);

		if ($response === null) {
			// Unreachable in practice (see the pre-loop comment) — a hard
			// failure here is safer than silently returning a malformed
			// dispatch result.
			throw new RuntimeException('dispatchWithRetry() exited its retry loop without a response.');
		}

		$timeEnd = microtime(true);

		return ['response' => $response, 'timeStart' => $timeStart, 'timeEnd' => $timeEnd];
	}//end dispatchWithRetry()

	/**
	 * Records a retryable dispatch failure against the per-Source circuit
	 * breaker: increments `circuitBreakerFailureCount` and, once it reaches
	 * `circuitBreakerThreshold` (default {@see self::BREAKER_DEFAULT_THRESHOLD}),
	 * opens the breaker with a fresh `circuitBreakerOpenedAt`. Persisted via
	 * the same `saveObject` pattern {@see sourceRateLimit()} already uses for
	 * rate-limit bookkeeping.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The mutable source data array.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 */
	private function recordBreakerFailure(ObjectEntity $source, array &$sourceData): void {
		$failureCount = ((int)($sourceData['circuitBreakerFailureCount'] ?? 0) + 1);
		$threshold = (int)($sourceData['circuitBreakerThreshold'] ?? self::BREAKER_DEFAULT_THRESHOLD);

		$sourceData['circuitBreakerFailureCount'] = $failureCount;

		if ($failureCount >= $threshold) {
			$sourceData['circuitBreakerState'] = 'open';
			$sourceData['circuitBreakerOpenedAt'] = time();
		}

		$this->persistSourceState(source: $source, sourceData: $sourceData);

	}//end recordBreakerFailure()

	/**
	 * Records a successful dispatch against the per-Source circuit breaker:
	 * resets `circuitBreakerState` to `closed` and `circuitBreakerFailureCount`
	 * to `0`. A no-op (no persistence) when the breaker was already closed
	 * with a zero failure count, to avoid a redundant write on every healthy
	 * call.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The mutable source data array.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 */
	private function recordBreakerSuccess(ObjectEntity $source, array &$sourceData): void {
		$wasOpenOrHadFailures = (($sourceData['circuitBreakerState'] ?? 'closed') !== 'closed')
			|| ((int)($sourceData['circuitBreakerFailureCount'] ?? 0) !== 0);

		if ($wasOpenOrHadFailures === false) {
			return;
		}

		$sourceData['circuitBreakerState'] = 'closed';
		$sourceData['circuitBreakerFailureCount'] = 0;
		$sourceData['circuitBreakerOpenedAt'] = null;

		$this->persistSourceState(source: $source, sourceData: $sourceData);

	}//end recordBreakerSuccess()

	/**
	 * Persists the source data array back to OpenRegister. Shared by the
	 * circuit breaker bookkeeping helpers; mirrors the system-context
	 * `saveObject` call already used by {@see checkAndResetRateLimit()} and
	 * {@see sourceRateLimit()} (ocon#147 — the engine writing back to its own
	 * admin-owned config on behalf of any caller).
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The source data array to persist.
	 *
	 * @return ObjectEntity The saved source ObjectEntity.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 */
	private function persistSourceState(ObjectEntity $source, array $sourceData): ObjectEntity {
		return $this->objectService->saveObject(
			object: $sourceData,
			register: 'openconnector',
			schema: 'source',
			uuid: $source->getUuid(),
			_rbac: false,
			_multitenancy: false
		);

	}//end persistSourceState()

	/**
	 * Manually trips the circuit breaker for a Source, regardless of its
	 * prior state or failure count (REQ-009). Sets `circuitBreakerState =
	 * 'open'`, `circuitBreakerOpenedAt = now()`, and
	 * `circuitBreakerFailureCount = circuitBreakerThreshold`, so the breaker
	 * reads as open through the normal {@see guardCircuitBreaker()} path on
	 * the very next dispatch.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to trip.
	 *
	 * @return ObjectEntity The saved source ObjectEntity.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
	 */
	public function tripCircuitBreaker(ObjectEntity $source): ObjectEntity {
		$sourceData = $source->getObject();
		$threshold = (int)($sourceData['circuitBreakerThreshold'] ?? self::BREAKER_DEFAULT_THRESHOLD);

		$sourceData['circuitBreakerState'] = 'open';
		$sourceData['circuitBreakerOpenedAt'] = time();
		$sourceData['circuitBreakerFailureCount'] = $threshold;

		return $this->persistSourceState(source: $source, sourceData: $sourceData);
	}//end tripCircuitBreaker()

	/**
	 * Manually resets the circuit breaker for a Source (REQ-009). Sets
	 * `circuitBreakerState = 'closed'`, `circuitBreakerFailureCount = 0`,
	 * `circuitBreakerOpenedAt = null` — the next dispatch proceeds normally.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to reset.
	 *
	 * @return ObjectEntity The saved source ObjectEntity.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009
	 */
	public function resetCircuitBreaker(ObjectEntity $source): ObjectEntity {
		$sourceData = $source->getObject();

		$sourceData['circuitBreakerState'] = 'closed';
		$sourceData['circuitBreakerFailureCount'] = 0;
		$sourceData['circuitBreakerOpenedAt'] = null;

		return $this->persistSourceState(source: $source, sourceData: $sourceData);
	}//end resetCircuitBreaker()

	/**
	 * Phase 7b helper: detect + validate a brokered (credentialRef) source call.
	 *
	 * Returns null when the merged configuration carries no credentialRef
	 * (legacy path), the resolved dispatch identity array when the brokered
	 * call may proceed, or a persisted synthetic 409 config-error CallLog
	 * ObjectEntity when any brokered config guard fails — the caller must
	 * return that entity immediately (no outbound request, no fallback to
	 * embedded secrets, REQ-SBC-004).
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $config The merged call configuration (Phase 7 output).
	 * @param array $sourceData The raw source data array.
	 * @param boolean $asynchronous Whether asynchronous dispatch was requested.
	 * @param \DateTime|null $errorExpires Expiry for error log entries.
	 *
	 * @return ObjectEntity|array{credentialId: string, actingUserId: string|null}|null
	 *
	 * @throws \OCP\DB\Exception On persistence failure of the synthetic CallLog.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	private function resolveBrokeredDispatch(
		ObjectEntity $source,
		array $config,
		array $sourceData,
		bool $asynchronous,
		?\DateTime $errorExpires,
	): ObjectEntity|array|null {
		if ($this->brokeredCallService->hasCredentialRef(config: $config) === false) {
			return null;
		}

		try {
			return $this->brokeredCallService->prepare(
				config: $config,
				sourceData: $sourceData,
				asynchronous: $asynchronous,
			);
		} catch (BrokeredCallConfigurationException $exception) {
			return $this->saveEarlyErrorLog(
				source: $source,
				statusCode: 409,
				statusMessage: $exception->getMessage(),
				expires: $errorExpires,
			);
		}

	}//end resolveBrokeredDispatch()

	/**
	 * Resolves app-injected credential placeholders in the source authentication config.
	 *
	 * When the source carries no injectable placeholder the source data is returned
	 * unchanged (a no-op that never touches the broker). Otherwise every
	 * `{credentialRef: {...}}` placeholder under `configuration.authentication` is resolved
	 * from Doriath through the broker and substituted in place, so Phase 9's Twig auth
	 * render injects a vault-resolved secret. A resolution failure is turned into a
	 * synthetic 409 config-error CallLog (returned as an ObjectEntity for the caller to
	 * short-circuit on), exactly like the proxy path — there is NO embedded-secret fallback.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The raw source data array.
	 * @param \DateTime|null $errorExpires Expiry for error log entries.
	 *
	 * @return ObjectEntity|array The hydrated source data, or an ObjectEntity CallLog on a hard config error.
	 *
	 * @throws \OCP\DB\Exception On persistence failure of the synthetic CallLog.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	private function hydrateInjectedCredentials(
		ObjectEntity $source,
		array $sourceData,
		?\DateTime $errorExpires,
	): ObjectEntity|array {
		if ($this->brokeredCallService->hasInjectableCredentials(sourceData: $sourceData) === false) {
			return $sourceData;
		}

		try {
			return $this->brokeredCallService->hydrateInjectableCredentials(sourceData: $sourceData);
		} catch (BrokeredCallConfigurationException $exception) {
			return $this->saveEarlyErrorLog(
				source: $source,
				statusCode: 409,
				statusMessage: $exception->getMessage(),
				expires: $errorExpires,
			);
		}

	}//end hydrateInjectedCredentials()

	/**
	 * Phase 7b+7c combined: resolves brokered/injected credentials for one
	 * call, or produces the synthetic config-error CallLog the caller must
	 * return immediately.
	 *
	 * Consolidates {@see resolveBrokeredDispatch()} (proxy `credentialRef`)
	 * and {@see hydrateInjectedCredentials()} (app-side placeholder
	 * injection) behind a single call so `call()` carries only ONE
	 * short-circuit check for both phases instead of three nested branches —
	 * keeps `call()`'s cyclomatic/NPath complexity within budget without
	 * changing any behaviour (REQ-SBC-001/002/003/004).
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $config The merged call configuration (Phase 7 output).
	 * @param array $sourceData The raw source data array.
	 * @param boolean $asynchronous Whether asynchronous dispatch was requested.
	 * @param \DateTime|null $errorExpires Expiry for error log entries.
	 *
	 * @return array{shortCircuit: ObjectEntity|null, brokeredCredential: array|null, sourceData: array}
	 *
	 * @throws \OCP\DB\Exception On persistence failure of a synthetic CallLog.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	private function resolveCallCredentials(
		ObjectEntity $source,
		array $config,
		array $sourceData,
		bool $asynchronous,
		?\DateTime $errorExpires,
	): array {
		$brokeredCredential = $this->resolveBrokeredDispatch(
			source: $source,
			config: $config,
			sourceData: $sourceData,
			asynchronous: $asynchronous,
			errorExpires: $errorExpires,
		);
		if ($brokeredCredential instanceof ObjectEntity) {
			// A synthetic 409 config-error CallLog was persisted — hard stop.
			return [
				'shortCircuit' => $brokeredCredential,
				'brokeredCredential' => null,
				'sourceData' => $sourceData,
			];
		}

		if ($brokeredCredential === null) {
			$injected = $this->hydrateInjectedCredentials(
				source: $source,
				sourceData: $sourceData,
				errorExpires: $errorExpires,
			);
			if ($injected instanceof ObjectEntity) {
				return [
					'shortCircuit' => $injected,
					'brokeredCredential' => null,
					'sourceData' => $sourceData,
				];
			}

			$sourceData = $injected;
		}

		return [
			'shortCircuit' => null,
			'brokeredCredential' => $brokeredCredential,
			'sourceData' => $sourceData,
		];

	}//end resolveCallCredentials()

	/**
	 * Re-resolve the dispatch source RAW from storage so the engine's
	 * credentials are its OWN property, never the caller's read (ocon#215).
	 *
	 * THE DEFECT CLASS (ocon#215): since ocon#147 the `source` schema's
	 * plaintext credential fields (`apikey`/`secret`/`password`/`jwt`/
	 * `authenticationConfig`) are `writeOnly: true`. OpenRegister's render
	 * boundary strips every writeOnly property on ANY rendered read —
	 * openregister#389 computes `$doWriteOnly = $schema->hasWriteOnlyProperties()`,
	 * so the strip is SCHEMA-gated, NOT `_rbac`-gated: an admin read, an
	 * `_rbac: false` read and a SystemOperationContext read all lose the
	 * secret (openregister#389/#429). So ANY of the ~18 callers that resolved a
	 * Source with rendering ON and handed it to {@see call()} was handing the
	 * engine a credential-free Source — and the outbound call went out
	 * UNAUTHENTICATED. Two sites were patched per-caller in ocon#212/#226;
	 * this closes the class STRUCTURALLY instead: `call()` re-reads the source
	 * itself before it touches auth, so a rendered Source can never cause an
	 * unauthenticated dispatch regardless of how the caller read it.
	 *
	 * `_render: false` IS THE LOAD-BEARING ARGUMENT — the same lesson ocon#212
	 * learned the hard way when its first fix used `_rbac: false` and webhooks
	 * still went out unsigned until ocon#226. `_render: false` returns the raw
	 * entity BEFORE renderEntity() is ever reached, keeping the writeOnly
	 * secret AND the `configuration.authentication.credentialRef` (the ref
	 * lives in `configuration`, which is not writeOnly, so it already survives
	 * — re-reading raw simply keeps the whole source consistent).
	 *
	 * Fallbacks (never throw, never dispatch an empty source):
	 *  - `$overruleAuth === true`: the caller is DELIBERATELY supplying auth on
	 *    the passed entity (the param's documented meaning — "overrule the
	 *    source authentication"). Re-reading would clobber that intent, so the
	 *    entity is honoured exactly as given.
	 *  - no uuid: an unpersisted / in-memory source (a source built in a test,
	 *    a PingAction probe, a freshly constructed entity) has nothing to
	 *    re-read; return it as passed.
	 *  - raw read misses or errors: fall back to the passed entity — this is
	 *    the pre-ocon#215 status quo for that source, never a regression, and
	 *    the engine still dispatches rather than silently dropping the call.
	 *
	 * @param ObjectEntity $source The source ObjectEntity as passed by the caller.
	 * @param boolean $overruleAuth Whether the caller is deliberately supplying auth (skip re-resolve).
	 *
	 * @return ObjectEntity The raw source entity (secrets intact), or the passed entity on any fallback.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	private function resolveSourceForDispatch(ObjectEntity $source, bool $overruleAuth): ObjectEntity {
		// The caller deliberately overrides auth on the passed entity —
		// re-reading from storage would clobber it.
		if ($overruleAuth === true) {
			return $source;
		}

		$uuid = $source->getUuid();

		// Unpersisted / in-memory source: nothing to re-read.
		if (empty($uuid) === true) {
			return $source;
		}

		try {
			$raw = $this->objectService->find(
				id: $uuid,
				register: 'openconnector',
				schema: 'source',
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (\Throwable $exception) {
			// A raw re-read failure must never break an outbound call that
			// worked before this engine-side hardening — fall back to the
			// passed entity (pre-ocon#215 behaviour for this source).
			$this->logger->warning(
				'CallService could not re-resolve source raw for dispatch; using the passed entity. ' . $exception->getMessage(),
				['exception' => $exception, 'sourceUuid' => $uuid]
			);
			return $source;
		}

		if ($raw === null) {
			return $source;
		}

		return $raw;
	}//end resolveSourceForDispatch()

	/**
	 * Phases 1-9: everything {@see call()} does BEFORE the HTTP request leaves
	 * the process, extracted so the synchronous and asynchronous dispatch
	 * siblings share ONE implementation (ocon#111 Task 0).
	 *
	 * This is the load-bearing half of the sibling-methods decision: auth
	 * (ocon#215 raw re-resolve, brokered/injected credentials), the enabled /
	 * location / rate-limit guards, the circuit-breaker guard, the source-config
	 * merge, method resolution, Twig rendering, certificate writing and the
	 * preRequest hook must be IDENTICAL whichever way the request is dispatched.
	 * If {@see callAsync()} reimplemented any of it, the async path would quietly
	 * grow its own auth and logging semantics — the exact divergence
	 * `parallel-file-fetch`'s design rejects.
	 *
	 * A non-null `shortCircuit` means a synthetic CallLog was already persisted
	 * (source disabled, loopback-blocked, rate-limited, breaker open, or a
	 * credential configuration error) and NO request may be dispatched. Both
	 * siblings must return it as-is rather than dispatching.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to call.
	 * @param string $endpoint The endpoint on the source to call.
	 * @param string $method The method on which to call the source.
	 * @param array $config The additional configuration to call the source.
	 * @param boolean $asynchronous Whether the caller intends asynchronous dispatch (affects the
	 *                              brokered-credential guard only; no HTTP happens here).
	 * @param boolean $overruleAuth Whether to overrule the source authentication.
	 * @param boolean $read Whether this is a singular read (vs list) call.
	 * @param boolean $runningSupportRequest Internal flag set when invoked from preRequest/postRequest hooks.
	 *
	 * @return array{shortCircuit: ObjectEntity|null, source: ObjectEntity, sourceData: array, config: array,
	 *               method: string, url: string, endpoint: string, logBody: boolean, brokeredCredential: array|null,
	 *               postRequest: array|null, retryPolicyOverride: array|null, successExpires: \DateTime|null,
	 *               errorExpires: \DateTime|null}
	 *
	 * @throws LoaderError On Twig loader error.
	 * @throws SyntaxError On Twig syntax error.
	 * @throws \OCP\DB\Exception On persistence failure of a synthetic CallLog.
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	private function prepareCall(
		ObjectEntity $source,
		string $endpoint,
		string $method,
		array $config,
		bool $asynchronous,
		bool $overruleAuth,
		bool $read,
		bool $runningSupportRequest,
	): array {
		// Ocon#215: re-resolve the Source RAW from storage BEFORE any auth is
		// read, so the engine's credentials are its OWN property and can never
		// be silenced by a rendered (writeOnly-stripped) read upstream. This
		// reassigns $source to the raw entity so EVERY downstream use — the
		// sourceData below, the SOAP path in dispatchRequest(), persisted
		// source-state writes and the CallLog — operates on secrets-intact
		// data. See {@see resolveSourceForDispatch()} for the full rationale
		// and the `_render: false` / overruleAuth / unpersisted fallbacks.
		$source = $this->resolveSourceForDispatch(source: $source, overruleAuth: $overruleAuth);
		$sourceData = $source->getObject();

		// Phase 1: Compute expiry values for log retention.
		$expiries = $this->buildExpiryValues(sourceData: $sourceData);
		$errorExpires = $expiries['errorExpires'];
		$successExpires = $expiries['successExpires'];

		$prepared = [
			'shortCircuit' => null,
			'source' => $source,
			'sourceData' => $sourceData,
			'config' => $config,
			'method' => $method,
			'url' => '',
			'endpoint' => $endpoint,
			'logBody' => false,
			'brokeredCredential' => null,
			'postRequest' => null,
			'retryPolicyOverride' => null,
			'successExpires' => $successExpires,
			'errorExpires' => $errorExpires,
		];

		// Phase 1b: extract the caller-supplied RetryPolicy override (REQ-007)
		// before it can leak into the persisted request config — mirrors the
		// same pull-out-then-unset treatment preRequest/postRequest/pagination
		// already get further down.
		$retryPolicyOverride = ($config['retryPolicy'] ?? null);
		if (is_array($retryPolicyOverride) === false) {
			$retryPolicyOverride = null;
		}

		$prepared['retryPolicyOverride'] = $retryPolicyOverride;

		unset($config['retryPolicy']);

		// Phases 3-6: source-precondition guards (enabled, location, rate limit).
		$earlyError = $this->guardCallPreconditions(source: $source, sourceData: $sourceData, errorExpires: $errorExpires);
		if ($earlyError !== null) {
			$prepared['shortCircuit'] = $earlyError;

			return $prepared;
		}

		// Phase 6b: circuit breaker precondition guard (REQ-008) — evaluated
		// after the enabled/location/rate-limit guards, before any dispatch
		// attempt (sync or async).
		$breakerShortCircuit = $this->guardCircuitBreaker(source: $source, sourceData: $sourceData, errorExpires: $errorExpires);
		if ($breakerShortCircuit !== null) {
			$prepared['shortCircuit'] = $breakerShortCircuit;

			return $prepared;
		}

		// Phase 7: Merge source-level configuration.
		$config = $this->mergeSourceConfiguration(config: $config, sourceData: $sourceData);

		// Phase 7a: Resolve HTTP method; strip method-override keys from config.
		//
		// oc#94 fix: this used to run as "Phase 2", BEFORE Phase 7's source
		// merge — so a Source's OWN `configuration.listMethod` (the documented
		// way to make a normally-GET list/fetch call dispatch as POST, e.g. a
		// POST-body search endpoint) was invisible to decideMethod() and never
		// took effect; only an explicit call-time `config['listMethod']`
		// override worked. Running this on the MERGED config makes a
		// Source-level `createMethod`/`updateMethod`/`destroyMethod`/
		// `listMethod`/`readMethod` override take effect exactly as documented,
		// while a call-time override keeps working identically (it is merged
		// in ahead of this point either way). The unset must also run on the
		// merged config now, for the same reason — previously an override key
		// declared only in source configuration was never stripped and could
		// leak into the persisted `call_log.request` payload.
		$method = $this->decideMethod(default: $method, configuration: $config, read: $read);
		unset($config['createMethod'], $config['updateMethod'], $config['destroyMethod'], $config['listMethod'], $config['readMethod']);

		$prepared['method'] = $method;

		// Phase 7b+7c: Brokered-credential guards/resolution (REQ-SBC-001/002/003)
		// and app-side credential injection (generic/self-hosted sources),
		// consolidated into one helper so this method keeps a single
		// short-circuit check for both phases. Selection happens HERE, on the
		// merged configuration, because Phase 9 strips every `authentication`
		// key before dispatch. Any config error is a hard synthetic 409
		// CallLog — embedded secrets are never merged, rendered, or
		// dispatched for a credentialRef source, and there is NO fallback
		// path (REQ-SBC-004).
		$credentials = $this->resolveCallCredentials(
			source: $source,
			config: $config,
			sourceData: $sourceData,
			asynchronous: $asynchronous,
			errorExpires: $errorExpires,
		);
		if ($credentials['shortCircuit'] !== null) {
			// A synthetic 409 config-error CallLog was persisted — hard stop.
			$prepared['shortCircuit'] = $credentials['shortCircuit'];

			return $prepared;
		}

		$prepared['brokeredCredential'] = $credentials['brokeredCredential'];
		$sourceData = $credentials['sourceData'];

		// Phase 8: Handle preRequest hook; capture postRequest descriptor.
		$prepared['postRequest'] = $this->extractAndFirePreRequest(
			source: $source,
			config: $config,
			runningSupportRequest: $runningSupportRequest,
		);

		// Phase 9: Normalise headers, pagination, render Twig, filter auth keys, write certs, extract logBody.
		$normalised = $this->normaliseRequestConfig(config: $config, sourceData: $sourceData);
		$prepared['config'] = $normalised['config'];
		$prepared['logBody'] = $normalised['logBody'];

		// Set the URL to call and add an endpoint if needed.
		$prepared['url'] = (($sourceData['location'] ?? '') . $endpoint);

		// Let's log the call.
		$sourceData['lastCall'] = (new DateTime())->format('c');
		// @todo: save the source.
		$prepared['sourceData'] = $sourceData;

		return $prepared;
	}//end prepareCall()

	/**
	 * Phases 11-13: everything {@see call()} does AFTER a response has been
	 * received, extracted so the synchronous and asynchronous dispatch siblings
	 * share ONE implementation (ocon#111 Task 0).
	 *
	 * ADR-003 is the reason this is shared rather than duplicated: `CallLog` is
	 * the canonical observability record for every outbound HTTP request, so an
	 * asynchronous dispatch MUST produce the same log row a synchronous one
	 * does. Response decoding, secret redaction, rate-limit header handling,
	 * trace-step appending and the postRequest hook all live here for the same
	 * reason.
	 *
	 * @param array $prepared The {@see prepareCall()} result for this request.
	 * @param \Psr\Http\Message\ResponseInterface $response The received HTTP response.
	 * @param float $timeStart Microtime captured at dispatch.
	 * @param float $timeEnd Microtime captured once the response arrived.
	 * @param boolean $runningSupportRequest Whether this call IS a support request (suppresses
	 *                                       the postRequest hook to avoid recursion).
	 * @param ExecutionTraceContext|null $trace The active execution trace context, when any.
	 * @param boolean $persistLog When false (interactive "Test connection"), a transient
	 *                            unsaved CallLog is returned and nothing is mutated.
	 *
	 * @return ObjectEntity The persisted CallLog entity.
	 *
	 * @throws GuzzleException On HTTP transport failure in the postRequest hook.
	 * @throws LoaderError On Twig loader error.
	 * @throws SyntaxError On Twig syntax error.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call-log-sessionid-req-011
	 */
	private function finalizeCall(
		array $prepared,
		\Psr\Http\Message\ResponseInterface $response,
		float $timeStart,
		float $timeEnd,
		bool $runningSupportRequest,
		?ExecutionTraceContext $trace,
		bool $persistLog = true,
	): ObjectEntity {
		$source = $prepared['source'];
		$sourceData = $prepared['sourceData'];

		// Phase 11: Decode response body and build the structured data array.
		$data = $this->buildResponseData(
			response: $response,
			url: $prepared['url'],
			method: $prepared['method'],
			config: $prepared['config'],
			timeStart: $timeStart,
			timeEnd: $timeEnd,
		);

		// Phase 12: Persist CallLog and get back the entity.
		$callLog = $this->buildAndPersistCallLog(
			source: $source,
			sourceData: $sourceData,
			data: $data,
			logBody: $prepared['logBody'],
			successExpires: $prepared['successExpires'],
			errorExpires: $prepared['errorExpires'],
			trace: $trace,
			persistLog: $persistLog,
		);

		// Phase 12b: when this call was made from within a traced execution,
		// append a `call` step reusing the exact already-redacted request/
		// response array just persisted to `call_log` — never a second,
		// independent redaction pass (execution-trace REQ-003 / http-call-engine
		// REQ-011).
		if ($trace !== null) {
			$callStepStatus = 'success';
			if ($data['response']['statusCode'] >= 400) {
				$callStepStatus = 'error';
			}

			$trace->addStep(
				type: 'call',
				name: ($sourceData['name'] ?? $source->getUuid()),
				timing: null,
				status: $callStepStatus,
				input: $data['request'],
				output: $data['response'],
				startedAtMicrotime: $timeStart,
				finishedAtMicrotime: $timeEnd,
			);
		}

		// Phase 13: Fire postRequest hook if present.
		if ($prepared['postRequest'] !== null && $runningSupportRequest === false) {
			$this->call(
				source: $source,
				endpoint: $prepared['postRequest']['endpoint'],
				config: $prepared['postRequest']['config'],
				runningSupportRequest: true
			);
		}

		return $callLog;
	}//end finalizeCall()

	/**
	 * Calls a source according to given configuration.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to call.
	 * @param string $endpoint The endpoint on the source to call.
	 * @param string $method The method on which to call the source.
	 * @param array $config The additional configuration to call the source.
	 * @param boolean $asynchronous Whether to call the source asynchronously.
	 * @param boolean $createCertificates Whether to create certificates for this source.
	 * @param boolean $overruleAuth Whether to overrule the source authentication.
	 * @param boolean $read Whether this is a singular read (vs list) call.
	 * @param boolean $runningSupportRequest Internal flag set when invoked from preRequest/postRequest hooks.
	 * @param mixed $sink Optional stream resource. When given, the response body is streamed
	 *                    into it (Guzzle `sink` option) instead of buffered, and the CallLog
	 *                    records an empty body (stream-file-content #110). Guzzle HTTP path
	 *                    only. Default null = unchanged behaviour for every existing caller.
	 * @param ExecutionTraceContext|null $trace The active execution trace context, when this call is
	 *                                          made from within a traced execution (execution-trace
	 *                                          REQ-001). When present, `call_log.sessionId` is set to
	 *                                          `$trace->getTraceId()` and a `call` step is appended
	 *                                          to the trace using the already-redacted request/
	 *                                          response data — no second redaction pass.
	 * @param boolean $persistLog When false (interactive "Test connection"), the response
	 *                            is returned without writing a CallLog or mutating the
	 *                            source — no heavy object save, no test-noise log rows.
	 *
	 * @return ObjectEntity
	 *
	 * @throws GuzzleException On HTTP transport failure.
	 * @throws LoaderError On Twig loader error.
	 * @throws SyntaxError On Twig syntax error.
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-post-body-sources-and-body-based-pagination-req-010
	 * @spec openspec/changes/stream-file-content/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call-log-sessionid-req-011
	 */
	public function call(
		ObjectEntity $source,
		string $endpoint = '',
		string $method = 'GET',
		array $config = [],
		bool $asynchronous = false,
		bool $createCertificates = true,
		bool $overruleAuth = false,
		bool $read = false,
		bool $runningSupportRequest = false,
		mixed $sink = null,
		?ExecutionTraceContext $trace = null,
		bool $persistLog = true,
	): ObjectEntity {
		// Ocon#111 Task 0: this method previously carried an
		// `if ($asynchronous === true) { return $response; }` branch that returned a
		// Guzzle Promise. Since `call()` is declared `): ObjectEntity`, reaching that
		// branch was an unconditional TypeError — it could never have worked, and
		// nothing exercised it (the only `asynchronous: true` in lib/ was this
		// method's own hand-off to dispatchRequest). Rather than repair a dead
		// branch and widen this method's return to a union — `call()` is the app's
		// central HTTP surface with ~30 call sites, all relying on ObjectEntity —
		// asynchronous dispatch is exposed as {@see callAsync()}. Fail loudly here so
		// a caller passing the flag is told where to go instead of hitting a
		// return-type fatal.
		if ($asynchronous === true) {
			throw new InvalidArgumentException(
				'CallService::call() is synchronous and returns an ObjectEntity call log. '
				. 'For concurrent dispatch use CallService::callAsync(), which returns a '
				. 'GuzzleHttp promise; see openspec/changes/parallel-file-fetch/design.md '
				. '("Sibling async methods, not union returns").'
			);
		}

		// Phases 1-9: shared with callAsync() so the two dispatch modes cannot
		// fork auth, certificate, guard or hook behaviour.
		$prepared = $this->prepareCall(
			source: $source,
			endpoint: $endpoint,
			method: $method,
			config: $config,
			asynchronous: false,
			overruleAuth: $overruleAuth,
			read: $read,
			runningSupportRequest: $runningSupportRequest,
		);
		if ($prepared['shortCircuit'] !== null) {
			return $prepared['shortCircuit'];
		}

		$dispatchConfig = $prepared['config'];

		// Phase 10: Dispatch the HTTP request, with the bounded retry loop
		// (REQ-007) and circuit-breaker bookkeeping (REQ-008).
		$dispatched = $this->dispatchWithRetry(
			source: $prepared['source'],
			method: $prepared['method'],
			url: $prepared['url'],
			endpoint: $prepared['endpoint'],
			config: $dispatchConfig,
			brokeredCredential: $prepared['brokeredCredential'],
			sourceData: $prepared['sourceData'],
			retryPolicyOverride: $prepared['retryPolicyOverride'],
			sink: $sink,
		);

		// Phases 11-13: shared with callAsync() — response decoding, CallLog
		// persistence (ADR-003), trace step, postRequest hook.
		return $this->finalizeCall(
			prepared: $prepared,
			response: $dispatched['response'],
			timeStart: $dispatched['timeStart'],
			timeEnd: $dispatched['timeEnd'],
			runningSupportRequest: $runningSupportRequest,
			trace: $trace,
			persistLog: $persistLog,
		);

	}//end call()

	/**
	 * Asynchronous sibling of {@see call()}: dispatches one outbound request and
	 * returns a Guzzle promise that resolves to the same `CallLog` ObjectEntity
	 * the synchronous path returns (ocon#111 Task 0).
	 *
	 * Why a sibling rather than a widened `call()`: `call()` is the app's central
	 * HTTP surface with ~30 call sites (`SourceCallNode`, `PingAction`,
	 * `NotuBizConnectorService`, `IBabsConnectorService`, `EventService`,
	 * `PromotionService`, `SynchronizationService`, …) that all rely on the
	 * `ObjectEntity` return. An `ObjectEntity|PromiseInterface` union would ripple
	 * through static analysis at every one of them for no behavioural gain. See
	 * `openspec/changes/parallel-file-fetch/design.md` → "Sibling async methods,
	 * not union returns".
	 *
	 * ONE consumed shape. The promise always resolves to a persisted `CallLog`
	 * entity — including for a short-circuit (source disabled, rate-limited,
	 * breaker open, credential misconfiguration), where the synchronous path also
	 * returns a synthetic CallLog carrying the 409/429/503 status. Callers check
	 * the logged status code exactly as they already do for `call()`; a rejection
	 * means a genuine transport failure, never a guard outcome.
	 *
	 * TWO deliberate differences from `call()`, both consequences of not blocking:
	 *  - **No retry loop.** `dispatchWithRetry()` sleeps between attempts
	 *    ({@see sleepRetryBackoff()}), which would stall the shared curl-multi
	 *    event loop and serialize the very requests this method exists to overlap.
	 *    Asynchronous dispatch is therefore single-attempt; a caller needing
	 *    retries must re-dispatch. Circuit-breaker bookkeeping (REQ-008) is still
	 *    recorded from the promise callbacks, so breaker state does not diverge.
	 *  - **Persistence happens at settle time**, inside `then()`. The CallLog row
	 *    is written when the response arrives, not when this method returns.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to call.
	 * @param string $endpoint The endpoint on the source to call.
	 * @param string $method The method on which to call the source.
	 * @param array $config The additional configuration to call the source.
	 * @param boolean $overruleAuth Whether to overrule the source authentication.
	 * @param boolean $read Whether this is a singular read (vs list) call.
	 * @param boolean $runningSupportRequest Internal flag set when invoked from preRequest/postRequest hooks.
	 * @param mixed $sink Optional temp-file PATH. When given, the response body is
	 *                    streamed into that file (Guzzle `sink` option) instead of
	 *                    buffered, and the CallLog records an empty body. MUST be a
	 *                    path, never a stream resource — Guzzle wraps a resource sink
	 *                    in a PSR-7 Stream and closes it on destruct, handing the
	 *                    caller back a closed handle (stream-file-content #110).
	 * @param ExecutionTraceContext|null $trace The active execution trace context, when any.
	 * @param callable|null $onHeaders Optional callback invoked with the PSR-7 response once its
	 *                                 headers have arrived and before the body downloads. The
	 *                                 concurrent file fetcher uses it to read `Content-Length`
	 *                                 for its in-flight byte budget (ocon#111).
	 * @param boolean $persistLog When false, a transient unsaved CallLog is returned and
	 *                            nothing is mutated — mirrors call()'s flag so the two
	 *                            siblings cannot diverge on logging behaviour.
	 *
	 * @return PromiseInterface A promise resolving to the persisted CallLog ObjectEntity.
	 *
	 * @throws LoaderError On Twig loader error during preparation.
	 * @throws SyntaxError On Twig syntax error during preparation.
	 * @throws \OCP\DB\Exception On persistence failure of a synthetic CallLog during preparation.
	 *
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
	 * @spec openspec/changes/parallel-file-fetch/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object
	 */
	public function callAsync(
		ObjectEntity $source,
		string $endpoint = '',
		string $method = 'GET',
		array $config = [],
		bool $overruleAuth = false,
		bool $read = false,
		bool $runningSupportRequest = false,
		mixed $sink = null,
		?ExecutionTraceContext $trace = null,
		?callable $onHeaders = null,
		bool $persistLog = true,
	): PromiseInterface {
		// A resource sink is the exact defect stream-file-content shipped a fix
		// for, and it is strictly worse here: with requestAsync() the response
		// body stream is destructed at a point this caller does not control, so
		// the handle would close under N concurrent fetches with no deterministic
		// ordering. Reject it at the boundary rather than let it corrupt a save.
		if (is_resource($sink) === true) {
			throw new InvalidArgumentException(
				'CallService::callAsync() requires a temp-file PATH as its sink, not a stream resource. '
				. 'Guzzle closes a resource-typed sink when its PSR-7 wrapper is destructed, which under '
				. 'asynchronous dispatch happens outside the caller\'s control; see '
				. 'openspec/changes/parallel-file-fetch/design.md ("The sink is a PATH, never a handle").'
			);
		}

		// Phases 1-9: the SAME preparation the synchronous path runs.
		$prepared = $this->prepareCall(
			source: $source,
			endpoint: $endpoint,
			method: $method,
			config: $config,
			asynchronous: true,
			overruleAuth: $overruleAuth,
			read: $read,
			runningSupportRequest: $runningSupportRequest,
		);
		if ($prepared['shortCircuit'] !== null) {
			// The guard already persisted a synthetic CallLog. Resolve with it so
			// the caller consumes ONE shape whichever way it dispatched.
			return new FulfilledPromise($prepared['shortCircuit']);
		}

		$dispatchConfig = $prepared['config'];
		$sourceData = $prepared['sourceData'];
		$timeStart = microtime(true);

		// Resolved here rather than in the callbacks so the breaker classifies an
		// asynchronous outcome by the SAME retryable-status set the synchronous
		// retry loop uses — see recordBreakerOutcome().
		$retryPolicy = $this->resolveEffectiveRetryPolicy(
			sourceData: $sourceData,
			override: $prepared['retryPolicyOverride'],
		);

		// Phase 10: single-attempt asynchronous dispatch. dispatchRequest()'s
		// async branch already attaches certificate cleanup to the promise
		// (#1012b), so cert hygiene is identical to the synchronous path.
		$promise = $this->dispatchRequest(
			source: $prepared['source'],
			method: $prepared['method'],
			url: $prepared['url'],
			endpoint: $prepared['endpoint'],
			config: $dispatchConfig,
			asynchronous: true,
			brokeredCredential: $prepared['brokeredCredential'],
			sink: $sink,
			onHeaders: $onHeaders,
		);

		return $promise->then(
			function ($response) use ($prepared, $sourceData, $retryPolicy, $timeStart, $runningSupportRequest, $trace, $persistLog) {
				$this->recordBreakerOutcome(
					source: $prepared['source'],
					sourceData: $sourceData,
					statusCode: $response->getStatusCode(),
					retryPolicy: $retryPolicy,
				);

				// Phases 11-13: the SAME finalization the synchronous path runs,
				// so an asynchronous call produces the same CallLog row (ADR-003).
				return $this->finalizeCall(
					prepared: $prepared,
					response: $response,
					timeStart: $timeStart,
					timeEnd: microtime(true),
					runningSupportRequest: $runningSupportRequest,
					trace: $trace,
					persistLog: $persistLog,
				);
			},
			function ($reason) use ($prepared, $sourceData, $retryPolicy, $timeStart, $runningSupportRequest, $trace, $persistLog) {
				// Guzzle rejects with an exception where the synchronous
				// dispatchRequest() catches one and converts it to a Response.
				// Perform the same conversion so a 4xx/5xx or a refused
				// connection produces the same CallLog either way.
				$response = null;
				if ($reason instanceof BadResponseException === true) {
					$response = $reason->getResponse();
				}

				if ($reason instanceof ConnectException === true) {
					$response = new Response(status: 503, body: $reason->getMessage());
				}

				if ($response === null) {
					// Not a transport failure this layer can turn into a log row
					// (a cancellation, a sink that could not be written, an
					// unexpected exception type). Record it against the breaker —
					// the synchronous path's equivalent is the \Throwable escaping
					// dispatchRequest() — and hand the reason on so the caller's
					// otherwise() leg isolates this file and releases its temp
					// file.
					$this->recordBreakerFailure(source: $prepared['source'], sourceData: $sourceData);

					return new RejectedPromise($reason);
				}

				$this->recordBreakerOutcome(
					source: $prepared['source'],
					sourceData: $sourceData,
					statusCode: $response->getStatusCode(),
					retryPolicy: $retryPolicy,
				);

				return $this->finalizeCall(
					prepared: $prepared,
					response: $response,
					timeStart: $timeStart,
					timeEnd: microtime(true),
					runningSupportRequest: $runningSupportRequest,
					trace: $trace,
					persistLog: $persistLog,
				);
			}
		);

	}//end callAsync()

	/**
	 * Records ONE dispatch outcome against the per-Source circuit breaker
	 * (REQ-008), classifying it exactly as {@see dispatchWithRetry()} does.
	 *
	 * The classification matters and is easy to get subtly wrong: the
	 * synchronous retry loop records a FAILURE only when the status is in the
	 * policy's `retryableStatusCodes`, and a SUCCESS only when the status is
	 * below 400. A non-retryable error — a 404, a 422 — records NEITHER, because
	 * it is the source answering correctly about a bad request rather than
	 * evidence the source is unhealthy. Sharing this helper keeps
	 * {@see callAsync()} from inventing a second, stricter rule that would open
	 * breakers the synchronous path leaves closed.
	 *
	 * @param ObjectEntity $source The source ObjectEntity.
	 * @param array $sourceData The mutable source data array (breaker bookkeeping mutates it).
	 * @param integer $statusCode The HTTP status code that came back.
	 * @param array $retryPolicy The resolved retry policy, for its `retryableStatusCodes`.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception On persistence failure of breaker bookkeeping.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008
	 */
	private function recordBreakerOutcome(
		ObjectEntity $source,
		array &$sourceData,
		int $statusCode,
		array $retryPolicy,
	): void {
		if (in_array($statusCode, $retryPolicy['retryableStatusCodes'], true) === true) {
			$this->recordBreakerFailure(source: $source, sourceData: $sourceData);

			return;
		}

		if ($statusCode < 400) {
			$this->recordBreakerSuccess(source: $source, sourceData: $sourceData);
		}

	}//end recordBreakerOutcome()

	/**
	 * Update the source with rate limit info if any of the rate limit headers are found.
	 *
	 * Else checks if config on the source has been set for Rate Limit. And updates
	 * the response headers with this Rate Limit info.
	 *
	 * @param ObjectEntity $source The source ObjectEntity to update.
	 * @param array $sourceData The mutable source data array.
	 * @param array $headers The response headers to check for Rate Limit headers.
	 *
	 * @return array The updated response headers.
	 *
	 * @throws \OCP\DB\Exception On persistence failure.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	private function sourceRateLimit(ObjectEntity $source, array $sourceData, array $headers): array {
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

		$rateLimitReset = ($sourceData['rateLimitReset'] ?? null);
		$rateLimitWindow = ($sourceData['rateLimitWindow'] ?? null);
		$rateLimitLimit = ($sourceData['rateLimitLimit'] ?? null);
		$rateLimitRemaining = ($sourceData['rateLimitRemaining'] ?? null);

		// If RateLimit-Reset not in headers and source->RateLimit-Reset === null. But source->RateLimit-Window is set.
		if (isset($headers['X-RateLimit-Reset']) === false
			&& $rateLimitReset === null
			&& $rateLimitWindow !== null
		) {
			// Set new RateLimit-Reset time on the source.
			$sourceData['rateLimitReset'] = (time() + $rateLimitWindow);
			$rateLimitReset = $sourceData['rateLimitReset'];
			$changed = true;
		}

		// Check if RateLimit-Limit is present in response headers. If so, save it in the source.
		if (isset($headers['X-RateLimit-Limit']) === true) {
			$clampedLimit = $this->clampRateLimitHeader(rawValue: $headers['X-RateLimit-Limit'], kind: 'limit');
			if ($clampedLimit !== null) {
				$sourceData['rateLimitLimit'] = $clampedLimit;
				$rateLimitLimit = $clampedLimit;
				$changed = true;
			}
		}

		// Check if RateLimit-Remaining is present in response headers. If so, save it in the source.
		if (isset($headers['X-RateLimit-Remaining']) === true) {
			$clampedRemaining = $this->clampRateLimitHeader(rawValue: $headers['X-RateLimit-Remaining'], kind: 'remaining');
			if ($clampedRemaining !== null) {
				$sourceData['rateLimitRemaining'] = $clampedRemaining;
				$rateLimitRemaining = $clampedRemaining;
				$changed = true;
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
			$changed = true;
		}

		if ($changed === true) {
			// System context (ocon#147) — see checkAndResetRateLimit(): the engine writes
			// back to its own admin-owned source config on behalf of any caller.
			$this->objectService->saveObject(
				object: $sourceData,
				register: 'openconnector',
				schema: 'source',
				uuid: $source->getUuid(),
				_rbac: false,
				_multitenancy: false
			);
		}

		if ($rateLimitLimit !== null || $rateLimitWindow !== null) {
			$headers = array_merge(
				$headers,
				[
					'X-RateLimit-Limit' => [(string)$rateLimitLimit],
					'X-RateLimit-Remaining' => [(string)$rateLimitRemaining],
					'X-RateLimit-Reset' => [(string)$rateLimitReset],
					'X-RateLimit-Used' => ['1'],
					'X-RateLimit-Window' => [(string)$rateLimitWindow],
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
	 * @spec openspec/specs/http-call-engine/spec.md
	 */
	public function applyConfigDot(array $config): array {
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
