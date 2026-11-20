<?php

/**
 * Integriq SyncFeedDeclarations repair step.
 *
 * On install and upgrade, reads lib/Settings/feeds.json from every enabled
 * app and keeps the feed endpoints and API products in step with it.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\FeedDeclarationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Syncs feed declarations; idempotent, and never throws.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */
class SyncFeedDeclarations implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Builds the sync service lazily, after OpenRegister is known to be there.
	 * @param LoggerInterface    $logger    Logs a failed sync.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function getName(): string {
		return 'Sync data feed declarations into Integriq feed endpoints (gateway-declared-data-feeds)';
	}//end getName()

	/**
	 * Run the sync.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function run(IOutput $output): void {
		if (class_exists('\\OCA\\OpenRegister\\Service\\ObjectService') === false) {
			$output->info('Integriq: OpenRegister is not available, skipping the feed declaration sync');
			return;
		}

		try {
			$summary = $this->container->get(FeedDeclarationService::class)->sync();
		} catch (\Throwable $e) {
			$output->warning('Integriq: the feed declaration sync failed: ' . $e->getMessage());
			$this->logger->error('Integriq: SyncFeedDeclarations failed', ['app' => 'integriq', 'exception' => $e]);
			return;
		}

		$refused = 'none';
		if ($summary['refused'] !== []) {
			$refused = implode(', ', array_keys($summary['refused']));
		}

		$output->info(
			sprintf(
				'Integriq: feed endpoints created %d, updated %d, deleted %d, unchanged %d; refused: %s',
				$summary['created'],
				$summary['updated'],
				$summary['deleted'],
				$summary['unchanged'],
				$refused
			)
		);
	}//end run()
}//end class
