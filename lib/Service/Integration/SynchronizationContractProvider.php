<?php

/**
 * SynchronizationContractProvider — exposes openconnector SyncContract
 * objects as "leaves" on the OR objects they synchronise.
 *
 * Registered with OR's IntegrationRegistry at app boot (see
 * {@see \OCA\OpenConnector\AppInfo\Application::boot()}). When a user
 * opens any OR object (in opencatalogi, decidesk, openconnector itself,
 * or any fleet app), the object's sidebar shows a "Synced from" leaf
 * with the SyncContract summary — making the cross-app sync origin
 * visible everywhere.
 *
 * Storage strategy: 'query-time'. No parallel link table is needed —
 * SyncContract objects already live in OR storage (chain B/C cutover);
 * we query them at request time filtering on `targetId = $objectId`.
 *
 * Read-only: contracts are managed by the sync engine
 * (SynchronizationService), not by end users. get/create/update/delete
 * inherit the AbstractIntegrationProvider default which throws
 * NotImplementedException.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec GH issue #824
 * @ref  Local ADR-005 (Source/Sync/Contract triad)
 * @ref  Local ADR-015 (Configuration export/import — slug translation)
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Integration;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;

class SynchronizationContractProvider extends AbstractIntegrationProvider
{

    private const REGISTER_SLUG = 'openconnector';

    private const SCHEMA_SLUG = 'synchronization_contract';

    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppConfig $appConfig,
        private readonly IL10N $l10n
    ) {
    }//end __construct()

    public function getId(): string
    {
        return 'sync-contract';
    }//end getId()

    public function getLabel(): string
    {
        return $this->l10n->t('Synced from');
    }//end getLabel()

    public function getIcon(): string
    {
        return 'SyncOutline';
    }//end getIcon()

    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        return 'openconnector';
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        return 'query-time';
    }//end getStorageStrategy()

    /**
     * List SyncContract leaves for an OR object.
     *
     * Finds every SyncContract whose `targetId === $objectId` and
     * returns a lightweight summary for sidebar display.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        $matches = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => self::REGISTER_SLUG,
                        'schema'   => self::SCHEMA_SLUG,
                        'targetId' => $objectId,
                    ],
                    'limit'   => isset($filters['_limit']) ? (int) $filters['_limit'] : 50,
                    'offset'  => isset($filters['_page']) ? ((int) $filters['_page'] - 1) * 50 : 0,
                ]
                );

        $rows = $matches['results'] ?? $matches;
        if (is_array($rows) === false) {
            return [];
        }

        return array_map(
            function ($contract): array {
                $body = is_object($contract) && method_exists($contract, 'getObject') ? $contract->getObject() : (array) ($contract['object'] ?? $contract);
                $uuid = is_object($contract) && method_exists($contract, 'getUuid') ? $contract->getUuid() : ($contract['uuid'] ?? '');

                return [
                    'id'                => $uuid,
                    'synchronizationId' => $body['synchronizationId'] ?? null,
                    'originId'          => $body['originId'] ?? null,
                    'originHash'        => $body['originHash'] ?? null,
                    'targetLastAction'  => $body['targetLastAction'] ?? null,
                    'targetLastSynced'  => $body['targetLastSynced'] ?? null,
                    'sourceLastChecked' => $body['sourceLastChecked'] ?? null,
                ];
            },
            $rows
        );
    }//end list()

    /**
     * Health descriptor. The provider needs the chain-B/C OR-cutover to
     * have completed for SyncContract objects to exist in OR storage.
     */
    public function health(): array
    {
        if ($this->isEnabled() === false) {
            return [
                'status'     => 'unavailable',
                'authStatus' => 'configured',
                'message'    => $this->l10n->t(
                    'OpenConnector storage migration has not yet run on this instance. Sync contract leaves will appear after `occ upgrade` runs the chain-C cutover.'
                ),
            ];
        }

        return [
            'status'     => 'ok',
            'authStatus' => 'configured',
            'message'    => null,
        ];
    }//end health()

    /**
     * The provider is available once the openconnector chain-C cutover
     * has materialised SyncContract objects in OR storage. That happens
     * when {@see \OCA\OpenConnector\Migration\Version2Date20260520000001}
     * flips `openconnector.storage_migrated` to `'true'`.
     */
    public function isEnabled(): bool
    {
        return $this->appConfig->getAppValueString('storage_migrated', 'false') === 'true';
    }//end isEnabled()
}//end class
