<?php
/**
 * Test stub for OCA\OpenRegister\AppHost\Service\GenericActionAuthService.
 *
 * The real class lives in the peer OpenRegister app (not in vendor). Mirrors
 * the public surface (ADR-023 action-authorization matrix) so unit tests can
 * construct/assert against the leaf `InitializeActions` repair step without a
 * full Nextcloud + OpenRegister install (adopt-apphost change).
 *
 * @category Test
 * @package  OCA\OpenRegister\AppHost\Service
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

namespace OCA\OpenRegister\AppHost\Service;

use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Minimal stand-in mirroring the engine's generic action-authorization service.
 */
class GenericActionAuthService
{
    private const CONFIG_KEY = 'actions';

    /**
     * Constructor.
     *
     * @param string        $appId        The calling (leaf) app id.
     * @param IAppConfig    $appConfig    App config store for the matrix.
     * @param IGroupManager $groupManager Group manager.
     */
    public function __construct(
        private readonly string $appId,
        private readonly IAppConfig $appConfig,
        private readonly IGroupManager $groupManager
    ) {
    }//end __construct()

    /**
     * The full action-to-groups matrix.
     *
     * @return array<string, array<int, string>>
     */
    public function getMatrix(): array
    {
        $json = $this->appConfig->getValueString($this->appId, self::CONFIG_KEY, '{}');

        $decoded = json_decode($json, true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;
    }//end getMatrix()

    /**
     * Write the full matrix.
     *
     * @param array<string, array<int, string>> $matrix The new matrix.
     *
     * @return void
     */
    public function setMatrix(array $matrix): void
    {
        $this->appConfig->setValueString($this->appId, self::CONFIG_KEY, json_encode($matrix));
    }//end setMatrix()
}//end class
