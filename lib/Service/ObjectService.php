<?php

/**
 * ObjectService — deprecated alias for SourceMappingService.
 *
 * @deprecated Use SourceMappingService instead. Removed in the next minor release.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7.1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCA\OpenConnector\Db\EndpointMapper;
use OCA\OpenConnector\Db\EventSubscriptionMapper;
use OCA\OpenConnector\Db\JobMapper;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\RuleMapper;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Db\SynchronizationMapper;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * Deprecated alias kept for one minor version per ADR-022 deprecation policy.
 *
 * @deprecated since 2026-05 — use SourceMappingService.
 */
class ObjectService extends SourceMappingService
{
    public function __construct(
        IAppManager $appManager,
        ContainerInterface $container,
        EndpointMapper $endpointMapper,
        EventSubscriptionMapper $eventSubscriptionMapper,
        JobMapper $jobMapper,
        MappingMapper $mappingMapper,
        RuleMapper $ruleMapper,
        SourceMapper $sourceMapper,
        SynchronizationMapper $synchronizationMapper,
    ) {
        trigger_error(
            'OCA\\OpenConnector\\Service\\ObjectService is deprecated; use SourceMappingService instead.',
            E_USER_DEPRECATED
        );
        parent::__construct(
            appManager: $appManager,
            container: $container,
            endpointMapper: $endpointMapper,
            eventSubscriptionMapper: $eventSubscriptionMapper,
            jobMapper: $jobMapper,
            mappingMapper: $mappingMapper,
            ruleMapper: $ruleMapper,
            sourceMapper: $sourceMapper,
            synchronizationMapper: $synchronizationMapper,
        );
    }
}
