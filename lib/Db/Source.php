<?php
/**
 * OpenConnector Source value object.
 *
 * Post OpenRegister-cutover, sources are persisted as OpenRegister objects
 * (register `openconnector`, schema `source`) and fetched via
 * `\OCA\OpenRegister\Service\ObjectService`. The legacy `openconnector_sources`
 * table + its QBMapper were removed in the cutover, but the engine
 * (`SynchronizationService`, `CallService`, ...) consumes a strongly typed
 * source (`->getLocation()`, `->getRateLimitLimit()`, ...).
 *
 * This class is therefore a thin, mapper-free hydratable value object:
 * `SourceMapper` (an OpenRegister-backed adapter) hydrates it from an
 * OpenRegister `ObjectEntity` at the boundary and serialises it back through
 * `ObjectService::saveObject()`. It is NOT registered as a QBMapper entity and
 * owns no database table.
 *
 * @category Db
 * @package  OCA\OpenConnector\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 */

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
 * @package                               OCA\OpenConnector\Db
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

    protected ?int $logRetention = 3600;

    protected ?int $errorRetention = 86400;

    protected ?int $objectCount = null;

    protected ?bool $test = null;

    protected ?int $rateLimitLimit = null;

    protected ?int $rateLimitRemaining = null;

    protected ?int $rateLimitReset = null;

    protected ?int $rateLimitWindow = null;

    protected ?DateTime $lastCall = null;

    protected ?DateTime $lastSync = null;

    protected ?DateTime $dateCreated = null;

    protected ?DateTime $dateModified = null;

    protected ?array $configurations = [];

    protected ?string $slug = null;

    /**
     * Get the authentication configuration.
     *
     * @return array The authentication configuration or empty array if null.
     */
    public function getAuthenticationConfig(): array
    {
        return $this->authenticationConfig ?? [];
    }//end getAuthenticationConfig()

    /**
     * Get the logging configuration.
     *
     * @return array The logging configuration or empty array if null.
     */
    public function getLoggingConfig(): array
    {
        return $this->loggingConfig ?? [];
    }//end getLoggingConfig()

    /**
     * Get the paths array.
     *
     * @return array The paths or empty array if null.
     */
    public function getPaths(): array
    {
        return $this->paths ?? [];
    }//end getPaths()

    /**
     * Get the headers array.
     *
     * @return array The headers or empty array if null.
     */
    public function getHeaders(): array
    {
        return $this->headers ?? [];
    }//end getHeaders()

    /**
     * Get the translation configuration.
     *
     * @return array The translation configuration or empty array if null.
     */
    public function getTranslationConfig(): array
    {
        return $this->translationConfig ?? [];
    }//end getTranslationConfig()

    /**
     * Get the general configuration.
     *
     * @return array The configuration or empty array if null.
     */
    public function getConfiguration(): array
    {
        return $this->configuration ?? [];
    }//end getConfiguration()

    /**
     * Get the endpoints configuration.
     *
     * @return array The endpoints configuration or empty array if null.
     */
    public function getEndpointsConfig(): array
    {
        return $this->endpointsConfig ?? [];
    }//end getEndpointsConfig()

    /**
     * Constructor: declare field types so getJsonFields()/hydrate() behave.
     */
    public function __construct()
    {
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
    }//end __construct()

    /**
     * Get fields that should be JSON encoded.
     *
     * @return array<string> List of field names that are JSON type.
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                function ($field) {
                    return $field === 'json';
                }
            )
        );
    }//end getJsonFields()

    /**
     * Get the slug for the source. If unset, generate one from the name.
     *
     * @return         string The slug for the source.
     * @phpstan-return non-empty-string
     * @psalm-return   non-empty-string
     */
    public function getSlug(): string
    {
        // Check if the slug is already set.
        if (empty($this->slug) === false) {
            return $this->slug;
        }

        // Generate a slug from the name if not set.
        $generatedSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim((string) $this->name)));

        // Ensure the generated slug is not empty.
        if (empty($generatedSlug) === true) {
            throw new RuntimeException('Unable to generate a valid slug from the name.');
        }

        return $generatedSlug;
    }//end getSlug()

    /**
     * Hydrate the value object from an associative array.
     *
     * Tolerates the OpenRegister object shape: the OpenRegister id/uuid is mapped
     * onto both `id` and `uuid`, and unknown keys are ignored.
     *
     * @param array $object The data to hydrate from.
     *
     * @return self
     */
    public function hydrate(array $object): self
    {
        $jsonFields = $this->getJsonFields();

        // OpenRegister exposes the canonical identifier under `id` (a UUID string)
        // and may repeat it under `uuid`; keep both populated for the engine.
        if (isset($object['id']) === true && isset($object['uuid']) === false) {
            $object['uuid'] = $object['id'];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields, true) === true && $value === null) {
                $value = [];
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Unknown / non-settable keys from the OpenRegister object are ignored.
                continue;
            }
        }

        return $this;
    }//end hydrate()

    /**
     * Serialise the source to its array form.
     *
     * @return array The serialised source.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                             => $this->id,
            'uuid'                           => $this->uuid,
            'name'                           => $this->name,
            'description'                    => $this->description,
            'version'                        => $this->version,
            'reference'                      => $this->reference,
            'location'                       => $this->location,
            'isEnabled'                      => $this->isEnabled,
            'type'                           => $this->type,
            'authorizationHeader'            => $this->authorizationHeader,
            'auth'                           => $this->auth,
            'authenticationConfig'           => $this->authenticationConfig,
            'authorizationPassthroughMethod' => $this->authorizationPassthroughMethod,
            'locale'                         => $this->locale,
            'accept'                         => $this->accept,
            'jwt'                            => $this->jwt,
            'jwtId'                          => $this->jwtId,
            'secret'                         => $this->secret,
            'username'                       => $this->username,
            'password'                       => $this->password,
            'apikey'                         => $this->apikey,
            'documentation'                  => $this->documentation,
            'loggingConfig'                  => $this->loggingConfig,
            'oas'                            => $this->oas,
            'paths'                          => $this->paths,
            'headers'                        => $this->headers,
            'translationConfig'              => $this->translationConfig,
            'configuration'                  => $this->configuration,
            'endpointsConfig'                => $this->endpointsConfig,
            'status'                         => $this->status,
            'logRetention'                   => $this->logRetention,
            'errorRetention'                 => $this->errorRetention,
            'objectCount'                    => $this->objectCount,
            'test'                           => $this->test,
            'rateLimitLimit'                 => $this->rateLimitLimit,
            'rateLimitRemaining'             => $this->rateLimitRemaining,
            'rateLimitReset'                 => $this->rateLimitReset,
            'rateLimitWindow'                => $this->rateLimitWindow,
            'lastCall'                       => $this->formatDate(date: $this->lastCall),
            'lastSync'                       => $this->formatDate(date: $this->lastSync),
            'dateCreated'                    => $this->formatDate(date: $this->dateCreated),
            'dateModified'                   => $this->formatDate(date: $this->dateModified),
            'configurations'                 => $this->configurations,
            'slug'                           => $this->getSlug(),
        ];
    }//end jsonSerialize()

    /**
     * Format a nullable DateTime as an ISO-8601 string.
     *
     * @param DateTime|null $date The date to format.
     *
     * @return string|null The ISO-8601 string, or null when the date is unset.
     */
    private function formatDate(?DateTime $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->format('c');
    }//end formatDate()
}//end class
