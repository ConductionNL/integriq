<?php
/**
 * Test stub for OCA\OpenRegister\AppHost\Repair\GenericInitializeActions.
 *
 * The real class lives in the peer OpenRegister app (not in vendor). Mirrors
 * the public constructor + IRepairStep surface so unit tests can construct/
 * assert against the leaf `InitializeActions` subclass without a full
 * Nextcloud + OpenRegister install (adopt-apphost change).
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Repair;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Minimal stand-in mirroring the engine's generic action-matrix repair step.
 */
class GenericInitializeActions implements IRepairStep
{
    /**
     * Constructor.
     *
     * @param string                   $appId      The leaf app id.
     * @param GenericActionAuthService $actionAuth App-scoped action-auth service.
     * @param IAppManager              $appManager App path resolution for the seed file.
     * @param LoggerInterface          $logger     PSR logger.
     */
    public function __construct(
        protected readonly string $appId,
        protected readonly GenericActionAuthService $actionAuth,
        protected readonly IAppManager $appManager,
        protected readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     */
    public function getName(): string
    {
        return sprintf('Initialize %s action-authorization matrix (ADR-023)', $this->appId);
    }//end getName()

    /**
     * Seed the matrix if empty; preserve any existing admin-customised matrix.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     */
    public function run(IOutput $output): void
    {
        // Stub: no-op. Real behaviour lives in the peer OpenRegister app.
    }//end run()
}//end class
