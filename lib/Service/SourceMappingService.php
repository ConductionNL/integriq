<?php
/**
 * OpenConnector Source Mapping Service.
 *
 * Thin wrapper over the MongoDB Data API used by OpenConnector to persist and
 * query freeform JSON objects when OpenRegister is not the storage backend.
 * Previously named ObjectService; renamed to SourceMappingService to avoid
 * cognitive collision with OR's generic ObjectService.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7
 */

namespace OCA\OpenConnector\Service;

use Adbar\Dot;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use OCP\App\IAppManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Uid\Uuid;

/**
 * MongoDB Data API client wrapper for OpenConnector's optional Mongo backend.
 *
 * Connector-specific source + mapping orchestrator.  NOT a generic object CRUD
 * service — that role belongs to OR's ObjectService.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class SourceMappingService
{

    /**
     * Default MongoDB action payload skeleton.
     *
     * @var array<string, string>
     */
    public const BASE_OBJECT = [
        'database'   => 'objects',
        'collection' => 'json',
    ];

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager Used to detect whether the OpenRegister app is installed.
     * @param ContainerInterface $container  PSR-11 container used to resolve OpenRegister services.
     *
     * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
    ) {

    }//end __construct()

    /**
     * Gets a guzzle client based upon given config.
     *
     * @param array $config The config to be used for the client.
     *
     * @return Client The configured Guzzle client.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function getClient(array $config): Client
    {
        $guzzleConf = $config;
        unset($guzzleConf['mongodbCluster']);

        return new Client($config);

    }//end getClient()

    /**
     * Save an object to MongoDB.
     *
     * @param array $data   The data to be saved.
     * @param array $config The configuration that should be used by the call.
     *
     * @return array The resulting object.
     *
     * @throws GuzzleException When the HTTP request fails.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function saveObject(array $data, array $config): array
    {
        $client = $this->getClient(config: $config);

        $object = self::BASE_OBJECT;
        $object['dataSource']     = $config['mongodbCluster'];
        $object['document']       = $data;
        $object['document']['id'] = $object['document']['_id'] = Uuid::v4();

        $result     = $client->post(
            uri: 'action/insertOne',
            options: ['json' => $object],
        );
        $resultData = json_decode(
            json: $result->getBody()->getContents(),
            associative: true
        );
        $id         = $resultData['insertedId'];

        return $this->findObject(filters: ['_id' => $id], config: $config);

    }//end saveObject()

    /**
     * Finds objects based upon a set of filters.
     *
     * @param array $filters The filters to compare the object to.
     * @param array $config  The configuration that should be used by the call.
     *
     * @return array The objects found for given filters.
     *
     * @throws GuzzleException When the HTTP request fails.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function findObjects(array $filters, array $config): array
    {
        $client = $this->getClient(config: $config);

        $object = self::BASE_OBJECT;
        $object['dataSource'] = $config['mongodbCluster'];
        $object['filter']     = $filters;

        // @todo Fix mongodb sort.
        // if (empty($sort) === false) {
        // $object['filter'][] = ['$sort' => $sort];
        // }.
        $returnData = $client->post(
            uri: 'action/find',
            options: ['json' => $object]
        );

        return json_decode(
            json: $returnData->getBody()->getContents(),
            associative: true
        );

    }//end findObjects()

    /**
     * Finds an object based upon a set of filters (usually the id).
     *
     * @param array $filters The filters to compare the objects to.
     * @param array $config  The config to be used by the call.
     *
     * @return array The resulting object.
     *
     * @throws GuzzleException When the HTTP request fails.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function findObject(array $filters, array $config): array
    {
        $client = $this->getClient(config: $config);

        $object           = self::BASE_OBJECT;
        $object['filter'] = $filters;
        $object['dataSource'] = $config['mongodbCluster'];

        $returnData = $client->post(
            uri: 'action/findOne',
            options: ['json' => $object]
        );

        $result = json_decode(
            json: $returnData->getBody()->getContents(),
            associative: true
        );

        return $result['document'];

    }//end findObject()

    /**
     * Updates an object in MongoDB.
     *
     * @param array $filters The filter to search the object with (id).
     * @param array $update  The fields that should be updated.
     * @param array $config  The configuration to be used by the call.
     *
     * @return array The updated object.
     *
     * @throws GuzzleException When the HTTP request fails.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function updateObject(array $filters, array $update, array $config): array
    {
        $client = $this->getClient(config: $config);

        $dotUpdate = new Dot($update);

        $object           = self::BASE_OBJECT;
        $object['filter'] = $filters;
        $object['update']['$set'] = $update;
        $object['upsert']         = true;
        $object['dataSource']     = $config['mongodbCluster'];

            $returnData = $client->post(
                uri: 'action/updateOne',
                options: ['json' => $object]
            );

        return $this->findObject(filters: $filters, config: $config);

    }//end updateObject()

    /**
     * Delete an object according to a filter (id specifically).
     *
     * @param array $filters The filters to use.
     * @param array $config  The config to be used by the call.
     *
     * @return array An empty array.
     *
     * @throws GuzzleException When the HTTP request fails.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function deleteObject(array $filters, array $config): array
    {
        $client = $this->getClient(config: $config);

        $object           = self::BASE_OBJECT;
        $object['filter'] = $filters;
        $object['dataSource'] = $config['mongodbCluster'];

        $returnData = $client->post(
            uri: 'action/deleteOne',
            options: ['json' => $object]
        );

        return [];

    }//end deleteObject()

    /**
     * Aggregates objects for search facets.
     *
     * @param array $filters  The filters apply to the search request.
     * @param array $pipeline The pipeline to use.
     * @param array $config   The configuration to use in the call.
     *
     * @return array The aggregation result.
     *
     * @throws GuzzleException When the HTTP request fails.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function aggregateObjects(array $filters, array $pipeline, array $config):array
    {
        $client = $this->getClient(config: $config);

        $object           = self::BASE_OBJECT;
        $object['filter'] = $filters;
        $object['pipeline']   = $pipeline;
        $object['dataSource'] = $config['mongodbCluster'];

        $returnData = $client->post(
            uri: 'action/aggregate',
            options: ['json' => $object]
        );

        return json_decode(
            json: $returnData->getBody()->getContents(),
            associative: true
        );

    }//end aggregateObjects()

    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The OpenRegister service if available, null otherwise.
     *
     * @throws ContainerExceptionInterface When the container fails.
     * @throws NotFoundExceptionInterface  When the service is not bound.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function getOpenRegisters(): ?\OCA\OpenRegister\Service\ObjectService
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getEnabledApps()) === true) {
            try {
                // Attempt to get the OpenRegister service from the container.
                return $this->container->get('OCA\OpenRegister\Service\ObjectService');
            } catch (Exception $e) {
                // If the service is not available, return null.
                return null;
            }
        }

        return null;

    }//end getOpenRegisters()

    /**
     * Gets the appropriate mapper based on the object type.
     *
     * Either resolves an OpenRegister mapper by register + schema, or throws when
     * an unknown object type is requested.
     *
     * @param string|null $objectType The objectType as string.
     * @param int|null    $schema     The openregister schema.
     * @param int|null    $register   The openregister register.
     *
     * @return mixed The appropriate mapper.
     *
     * @throws ContainerExceptionInterface When the container fails.
     * @throws NotFoundExceptionInterface  When the service is not bound.
     * @throws InvalidArgumentException    If an unknown object type is provided.
     *
     * @spec openspec/specs/object-service-shim/spec.md
     */
    public function getMapper(?string $objectType=null, ?int $schema=null, ?int $register=null): mixed
    {
        if ($register !== null && $schema !== null && $objectType === null) {
            return $this->getOpenRegisters()->getMapper(register: $register, schema: $schema);
        }

        throw new InvalidArgumentException("Unknown object type: $objectType");

    }//end getMapper()
}//end class
