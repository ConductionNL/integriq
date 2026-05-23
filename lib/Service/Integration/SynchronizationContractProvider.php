<?php
/**
 * Synchronization contract integration provider.
 *
 * Exposes openconnector SyncContract objects as "leaves" on the OR objects they
 * synchronise. Registered with OR's IntegrationRegistry at app boot (see
 * {@see \OCA\OpenConnector\AppInfo\Application::boot()}). When a user opens any
 * OR object (in opencatalogi, decidesk, openconnector itself, or any fleet
 * app), the object's sidebar shows a "Synced from" leaf with the SyncContract
 * summary — making the cross-app sync origin visible everywhere.
 *
 * Storage strategy: 'query-time'. No parallel link table is needed —
 * SyncContract objects already live in OR storage (chain B/C cutover);
 * we query them at request time filtering on `targetId = $objectId`.
 *
 * Read-only: contracts are managed by the sync engine (SynchronizationService),
 * not by end users. get/create/update/delete inherit the
 * AbstractIntegrationProvider default which throws NotImplementedException.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
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

/**
 * Exposes openconnector SyncContract objects as integration leaves on OR objects.
 */
class SynchronizationContractProvider extends AbstractIntegrationProvider
{

    /**
     * Register slug under which openconnector schemas live in OR.
     *
     * @var string
     */
    private const REGISTER_SLUG = 'openconnector';

    /**
     * Schema slug for the synchronization contract objects.
     *
     * @var string
     */
    private const SCHEMA_SLUG = 'synchronization_contract';

    /**
     * Constructor.
     *
     * @param ObjectService $objectService OR object service used to query sync contracts.
     * @param IAppConfig    $appConfig     App config used to check the chain-C cutover flag.
     * @param IL10N         $l10n          Translator for user-facing labels and messages.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppConfig $appConfig,
        private readonly IL10N $l10n
    ) {

    }//end __construct()

    /**
     * Get the provider id.
     *
     * @return string The provider identifier.
     */
    public function getId(): string
    {
        return 'sync-contract';

    }//end getId()

    /**
     * Get the user-facing label.
     *
     * @return string The translated label.
     */
    public function getLabel(): string
    {
        return $this->l10n->t('Synced from');

    }//end getLabel()

    /**
     * Get the icon identifier used by the OR sidebar.
     *
     * @return string The icon identifier.
     */
    public function getIcon(): string
    {
        return 'SyncOutline';

    }//end getIcon()

    /**
     * Get the optional group identifier.
     *
     * @return string|null The group identifier.
     */
    public function getGroup(): ?string
    {
        return 'workflow';

    }//end getGroup()

    /**
     * Get the app id this provider requires.
     *
     * @return string|null The required app id.
     */
    public function getRequiredApp(): ?string
    {
        return 'openconnector';

    }//end getRequiredApp()

    /**
     * Get the storage strategy for this provider.
     *
     * @return string The storage strategy identifier.
     */
    public function getStorageStrategy(): string
    {
        return 'query-time';

    }//end getStorageStrategy()

    /**
     * List SyncContract leaves for an OR object.
     *
     * Finds every SyncContract whose `targetId === $objectId` and
     * returns a lightweight summary for sidebar display.
     *
     * @param string $register The OR register slug.
     * @param string $schema   The OR schema slug.
     * @param string $objectId The OR object id being viewed.
     * @param array  $filters  Optional filters with `_limit` and `_page` for pagination.
     *
     * @return array The list of contract summaries.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        if ($this->isEnabled() === false) {
            return [];
        }

        $limit = 50;
        if (isset($filters['_limit']) === true) {
            $limit = (int) $filters['_limit'];
        }

        $offset = 0;
        if (isset($filters['_page']) === true) {
            $offset = (((int) $filters['_page']) - 1) * 50;
        }

        $matches = $this->objectService->findAll(
            config: [
                'filters' => [
                    'register' => self::REGISTER_SLUG,
                    'schema'   => self::SCHEMA_SLUG,
                    'targetId' => $objectId,
                ],
                'limit'   => $limit,
                'offset'  => $offset,
            ]
        );

        $rows = $matches['results'] ?? $matches;
        if (is_array($rows) === false) {
            return [];
        }

        return array_map(
            function ($contract): array {
                if (is_object($contract) === true && method_exists($contract, 'getObject') === true) {
                    $body = $contract->getObject();
                } else {
                    $body = (array) ($contract['object'] ?? $contract);
                }

                if (is_object($contract) === true && method_exists($contract, 'getUuid') === true) {
                    $uuid = $contract->getUuid();
                } else {
                    $uuid = ($contract['uuid'] ?? '');
                }

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
     * Health descriptor.
     *
     * The provider needs the chain-B/C OR-cutover to have completed for
     * SyncContract objects to exist in OR storage.
     *
     * @return array The health descriptor.
     */
    public function health(): array
    {
        if ($this->isEnabled() === false) {
            return [
                'status'     => 'unavailable',
                'authStatus' => 'configured',
                'message'    => $this->l10n->t(
                    'OpenConnector storage migration has not yet run on this instance.'
                    .' Sync contract leaves will appear after `occ upgrade` runs the chain-C cutover.'
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
     * Whether the provider is enabled on this instance.
     *
     * The provider is available once the openconnector chain-C cutover has
     * materialised SyncContract objects in OR storage. That happens when
     * {@see \OCA\OpenConnector\Migration\Version2Date20260520000001} flips
     * `openconnector.storage_migrated` to `'true'`.
     *
     * @return bool True when the storage migration has run, false otherwise.
     */
    public function isEnabled(): bool
    {
        return $this->appConfig->getAppValueString('storage_migrated', 'false') === 'true';

    }//end isEnabled()
}//end class
