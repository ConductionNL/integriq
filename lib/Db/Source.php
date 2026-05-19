<?php

namespace OCA\OpenConnector\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use RuntimeException;

/**
 * Class Source
 *
 * Represents a source configuration entity that defines how to connect to and interact with external data sources.
 *
 * @package OCA\OpenConnector\Db
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Source extends Entity implements JsonSerializable
{
	protected ?string $uuid = null;
	protected ?string $name = null;
	protected ?string $description = null;
	protected ?string $reference = null;
	protected ?string $version = '0.0.0';
	protected ?string $location = null;
	protected ?bool $isEnabled = null;
	protected ?string $type = null;
	protected ?string $authorizationHeader = null;
	protected ?string $auth = null;
	protected ?array $authenticationConfig = [];
	protected ?string $authorizationPassthroughMethod = null;
	protected ?string $locale = null;
	protected ?string $accept = null;
	protected ?string $jwt = null;
	protected ?string $jwtId = null;
	protected ?string $secret = null;
	protected ?string $username = null;
	protected ?string $password = null;
	protected ?string $apikey = null;
	protected ?string $documentation = null;
	protected ?array $loggingConfig = [];
	protected ?string $oas = null;
	protected ?array $paths = [];
	protected ?array $headers = [];
	protected ?array $translationConfig = [];
	protected ?array $configuration = [];
	protected ?array $endpointsConfig = [];
	protected ?string $status = null;
	protected ?int $logRetention = 3600; // seconds to save all logs
	protected ?int $errorRetention = 86400; // seconds to save error logs
	protected ?int $objectCount = null;
	protected ?bool $test = null;
	protected ?int $rateLimitLimit = null; // Indicates the total number of allowed requests within a specific time period.
	protected ?int $rateLimitRemaining = null; // Specifies how many requests are still allowed within the current limit.
	protected ?int $rateLimitReset = null; // A Unix Time Stamp that indicates when the rate limit will be reset.
	protected ?int $rateLimitWindow = null; // Indicates how many seconds the client must wait before making new requests.
	protected ?DateTime $lastCall = null;
	protected ?DateTime $lastSync = null;
	protected ?DateTime $dateCreated = null;
	protected ?DateTime $dateModified = null;
	protected ?array $configurations = []; // Array of configuration IDs that this source belongs to
	protected ?string $slug = null; // URL-friendly identifier for the source

	/**
	 * Get the authentication configuration
	 *
	 * @return array The authentication configuration or empty array if null
	 */
	public function getAuthenticationConfig(): array
	{
		return $this->authenticationConfig ?? [];
	}

	/**
	 * Get the logging configuration
	 *
	 * @return array The logging configuration or empty array if null
	 */
	public function getLoggingConfig(): array
	{
		return $this->loggingConfig ?? [];
	}

	/**
	 * Get the paths array
	 *
	 * @return array The paths or empty array if null
	 */
	public function getPaths(): array
	{
		return $this->paths ?? [];
	}

	/**
	 * Get the headers array
	 *
	 * @return array The headers or empty array if null
	 */
	public function getHeaders(): array
	{
		return $this->headers ?? [];
	}

	/**
	 * Get the translation configuration
	 *
	 * @return array The translation configuration or empty array if null
	 */
	public function getTranslationConfig(): array
	{
		return $this->translationConfig ?? [];
	}

	/**
	 * Get the general configuration
	 *
	 * @return array The configuration or empty array if null
	 */
	public function getConfiguration(): array
	{
		return $this->configuration ?? [];
	}

	/**
	 * Get the endpoints configuration
	 *
	 * @return array The endpoints configuration or empty array if null
	 */
	public function getEndpointsConfig(): array
	{
		return $this->endpointsConfig ?? [];
	}

	public function __construct() {
		$this->addType('uuid', 'string');
		$this->addType('name', 'string');
		$this->addType('description', 'string');
		$this->addType('reference', 'string');
		$this->addType('version', 'string');
		$this->addType('location', 'string');
		$this->addType('isEnabled', 'boolean');
		$this->addType('type', 'string');
		$this->addType('authorizationHeader', 'string');
		$this->addType('auth', 'string');
		$this->addType('authenticationConfig', 'json');
		$this->addType('authorizationPassthroughMethod', 'string');
		$this->addType('locale', 'string');
		$this->addType('accept', 'string');
		$this->addType('jwt', 'string');
		$this->addType('jwtId', 'string');
		$this->addType('secret', 'string');
		$this->addType('username', 'string');
		$this->addType('password', 'string');
		$this->addType('apikey', 'string');
		$this->addType('documentation', 'string');
		$this->addType('loggingConfig', 'json');
		$this->addType('oas', 'string');
		$this->addType('paths', 'json');
		$this->addType('headers', 'json');
		$this->addType('translationConfig', 'json');
		$this->addType('configuration', 'json');
		$this->addType('endpointsConfig', 'json');
		$this->addType('status', 'string');
		$this->addType('logRetention', 'integer');
		$this->addType('errorRetention', 'integer');
		$this->addType('objectCount', 'integer');
		$this->addType('test', 'boolean');
		$this->addType('rateLimitLimit', 'integer');
		$this->addType('rateLimitRemaining', 'integer');
		$this->addType('rateLimitReset', 'integer');
		$this->addType('rateLimitWindow', 'integer');
		$this->addType('lastCall', 'datetime');
		$this->addType('lastSync', 'datetime');
		$this->addType('dateCreated', 'datetime');
		$this->addType('dateModified', 'datetime');
		$this->addType('configurations', 'json');
		$this->addType('slug', 'string');
	}

	public function getJsonFields(): array
	{
		return array_keys(
			array_filter($this->getFieldTypes(), function ($field) {
				return $field === 'json';
			})
		);
	}


	/**
	 * Get the slug for the endpoint.
	 * If the slug is not set, generate one from the name.
	 *
	 * @return string The slug for the endpoint
	 * @phpstan-return non-empty-string
	 * @psalm-return non-empty-string
	 */
	public function getSlug(): string
	{
		// Check if the slug is already set
		if (!empty($this->slug)) {
			return $this->slug;
		}

		// Generate a slug from the name if not set
		// Convert the name to lowercase, replace spaces with hyphens, and remove non-alphanumeric characters
		$generatedSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($this->name)));

		// Ensure the generated slug is not empty
		if (empty($generatedSlug)) {
			throw new RuntimeException('Unable to generate a valid slug from the name.');
		}

		return $generatedSlug;
	}

	public function hydrate(array $object): self
	{
		$jsonFields = $this->getJsonFields();

		foreach ($object as $key => $value) {
			if (in_array($key, $jsonFields) === true && $value === []) {
				$value = [];
			}

			$method = 'set'.ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Error handling can be added here
			}
		}

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'name' => $this->name,
			'description' => $this->description,
			'version' => $this->version,
			'reference' => $this->reference,
			'location' => $this->location,
			'isEnabled' => $this->isEnabled,
			'type' => $this->type,
			'authorizationHeader' => $this->authorizationHeader,
			'auth' => $this->auth,
			'authenticationConfig' => $this->authenticationConfig,
			'authorizationPassthroughMethod' => $this->authorizationPassthroughMethod,
			'locale' => $this->locale,
			'accept' => $this->accept,
			'jwt' => $this->jwt,
			'jwtId' => $this->jwtId,
			'secret' => $this->secret,
			'username' => $this->username,
			'password' => $this->password,
			'apikey' => $this->apikey,
			'documentation' => $this->documentation,
			'loggingConfig' => $this->loggingConfig,
			'oas' => $this->oas,
			'paths' => $this->paths,
			'headers' => $this->headers,
			'translationConfig' => $this->translationConfig,
			'configuration' => $this->configuration,
			'endpointsConfig' => $this->endpointsConfig,
			'status' => $this->status,
			'logRetention' => $this->logRetention,
			'errorRetention' => $this->errorRetention,
			'objectCount' => $this->objectCount,
			'test' => $this->test,
			'rateLimitLimit' => $this->rateLimitLimit,
			'rateLimitRemaining' => $this->rateLimitRemaining,
			'rateLimitReset' => $this->rateLimitReset,
			'rateLimitWindow' => $this->rateLimitWindow,
			'lastCall' => isset($this->lastCall) ? $this->lastCall->format('c') : null,
			'lastSync' => isset($this->lastSync) ? $this->lastSync->format('c') : null,
			'dateCreated' => isset($this->dateCreated) ? $this->dateCreated->format('c') : null,
			'dateModified' => isset($this->dateModified) ? $this->dateModified->format('c') : null,
			'configurations' => $this->configurations,
			'slug' => $this->getSlug(),
		];
	}
}
