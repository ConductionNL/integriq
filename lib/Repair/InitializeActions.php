<?php

/**
 * Initialize Actions Repair Step
 *
 * Seeds the ADR-023 action-authorization matrix on fresh install if empty.
 * Preserves any admin-customized matrix on upgrade — existing non-empty
 * matrix values are left untouched.
 *
 * @category Repair
 * @package  OCA\OpenConnector\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Repair;

use OCA\OpenConnector\Service\ActionAuthService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Seed the action-authorization matrix from lib/actions.seed.json on install.
 *
 * @spec openspec/architecture/adr-023-action-authorization.md
 */
class InitializeActions implements IRepairStep
{
    private const SEED_PATH = __DIR__.'/../actions.seed.json';

    /**
     * Constructor.
     *
     * @param ActionAuthService $actionAuth The action authorization service.
     * @param LoggerInterface   $logger     Logger.
     */
    public function __construct(
        private ActionAuthService $actionAuth,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Repair-step name.
     *
     * @return string
     *
     * @spec openspec/architecture/adr-023-action-authorization.md
     */
    public function getName(): string
    {
        return 'Initialize action-authorization matrix (ADR-023)';

    }//end getName()

    /**
     * Seed the matrix if empty; preserve any existing admin-customized matrix.
     *
     * @param IOutput $output Repair output channel.
     *
     * @return void
     *
     * @spec openspec/architecture/adr-023-action-authorization.md
     */
    public function run(IOutput $output): void
    {
        $existing = $this->actionAuth->getMatrix();
        if (count($existing) > 0) {
            $entrySuffix = 'ies';
            if (count($existing) === 1) {
                $entrySuffix = 'y';
            }

            $output->info(
                sprintf(
                    'Action matrix already has %d entr%s — preserving.',
                    count($existing),
                    $entrySuffix
                )
            );
            return;
        }

        if (file_exists(self::SEED_PATH) === false) {
            $output->warning('actions.seed.json not found — matrix left empty (default-deny).');
            $this->logger->warning('[openconnector] ADR-023 seed file missing at '.self::SEED_PATH);
            return;
        }

        $raw = file_get_contents(self::SEED_PATH);
        if ($raw === false) {
            $output->warning('Could not read actions.seed.json — matrix left empty (default-deny).');
            return;
        }

        try {
            $parsed = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->warning('actions.seed.json invalid JSON: '.$e->getMessage());
            $this->logger->error('[openconnector] ADR-023 seed malformed: '.$e->getMessage());
            return;
        }

        $actions = ($parsed['actions'] ?? null);
        if (is_array($actions) === false) {
            $output->warning('actions.seed.json missing `actions` object — matrix left empty.');
            return;
        }

        try {
            $this->actionAuth->setMatrix(matrix: $actions);
        } catch (\JsonException $e) {
            $output->warning('Failed to write matrix: '.$e->getMessage());
            return;
        }

        $actionSuffix = 's';
        if (count($actions) === 1) {
            $actionSuffix = '';
        }

        $output->info(
            sprintf(
                'Seeded action matrix with %d action%s (default: admin-only).',
                count($actions),
                $actionSuffix
            )
        );

    }//end run()
}//end class
