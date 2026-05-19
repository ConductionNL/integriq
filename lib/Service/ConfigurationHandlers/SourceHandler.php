<?php

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCA\OpenConnector\Db\Source;
use OCA\OpenConnector\Db\SourceMapper;
use OCP\AppFramework\Db\Entity;

/**
 * Class SourceHandler
 *
 * Handler for exporting and importing source configurations.
 *
 * @package OCA\OpenConnector\Service\ConfigurationHandlers
 * @category Service
 * @author OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/OpenConnector/openconnector
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class SourceHandler implements ConfigurationHandlerInterface
{
    /**
     * @param SourceMapper $sourceMapper The source mapper
     */
    public function __construct(
        private readonly SourceMapper $sourceMapper
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function export(Entity $entity, array $mappings, array &$mappingIds = []): array
    {
        if (!$entity instanceof Source) {
            throw new \InvalidArgumentException('Entity must be an instance of Source');
        }

        $sourceArray = $entity->jsonSerialize();
        
        // Ensure slug is set
        if (empty($sourceArray['slug'])) {
            $sourceArray['slug'] = $entity->getSlug();
        }
        
        // Remove sensitive data
        unset(
            $sourceArray['id'],
            $sourceArray['uuid'],
            $sourceArray['authorizationHeader'],
            $sourceArray['auth'],
            $sourceArray['authenticationConfig'],
            $sourceArray['authorizationPassthroughMethod'],
            $sourceArray['jwt'],
            $sourceArray['jwtId'],
            $sourceArray['secret'],
            $sourceArray['username'],
            $sourceArray['password'],
            $sourceArray['apikey']
        );

        // Sanitize configuration to remove sensitive headers
        if (isset($sourceArray['configuration']) && is_array($sourceArray['configuration'])) {
            foreach ($sourceArray['configuration'] as $key => $value) {
                if (str_starts_with($key, 'headers.') &&
                    (str_contains(strtolower($key), 'authorization') ||
                     str_contains(strtolower($key), 'token') ||
                     str_contains(strtolower($key), 'key') ||
                     str_contains(strtolower($key), 'secret'))) {
                    unset($sourceArray['configuration'][$key]);
                }
            }
        }

        return $sourceArray;
    }

    /**
     * {@inheritDoc}
     */
    public function import(array $data, array $mappings): Entity
    {
        // Check if source with this slug already exists.
        if (isset($data['slug']) && isset($mappings['source']['slugToId'][$data['slug']])) {
            // Update existing source
            return $this->sourceMapper->updateFromArray($mappings['source']['slugToId'][$data['slug']], $data);
        }

        // Create new source.
        return $this->sourceMapper->createFromArray($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'source';
    }
}
