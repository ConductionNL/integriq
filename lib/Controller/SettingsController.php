<?php
/**
 * OpenConnector Settings Controller
 *
 * Post-chain-C shrunk version. The only kept method is the openconnector-specific
 * `rebase` action (recompute log-retention deletion timestamps after retention
 * settings change). The deleted `stats`, `getSettings`, `updateSettings` methods
 * are superseded by OR's `/api/settings/*` endpoints + declarative manifest
 * dashboard widgets.
 *
 * Cross-ref: openspec/changes/openconnector-services-direct-or-usage/proposal.md § 2a
 * Postgres portability of `rebase`: tracked at GH #822.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings(PHPMD.ShortVariable)
 */
class SettingsController extends Controller
{

    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
        private readonly IL10N $l
    ) {
        parent::__construct($appName, $request);
    }


    /**
     * Rebase all logs with current retention settings.
     * Recomputes deletion timestamps for CallLog/JobLog/SynchronizationLog rows
     * based on the current retention windows. This is the openconnector-specific
     * action that has no OR equivalent (OR's archival workflow uses ISO durations
     * + per-object retention; the connector mixes service-level constants with
     * per-source overrides — see local ADR-004).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function rebase(): JSONResponse
    {
        try {
            $this->logger->info('Rebase endpoint called', ['endpoint' => '/api/settings/rebase']);
            $result = $this->settingsService->rebase();
            $this->logger->info('Rebase operation completed', [
                'success'  => $result['success'] ?? false,
                'duration' => $result['duration'] ?? 'unknown',
                'errors'   => count($result['errors'] ?? []),
            ]);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Failed to perform rebase operation', [
                'exception' => $e->getMessage(),
            ]);
            return new JSONResponse([
                'error'   => $this->l->t('Failed to perform rebase operation'),
                'message' => $e->getMessage(),
            ], 500);
        }
    }


}
