<?php

/**
 * Integriq MigrateWebhookConnections repair step.
 *
 * Moves the signature trust of every signed public webhook on the consumer
 * model (Peppol, NotifyNL, ROD, OSO, UWLR/Edu-V, Verzuimloket, iWMO/iJW,
 * StUF-ZKN, verdicts and the intake channels) from its legacy `source` into
 * its own consumer, with an empty account. Until an administrator chooses
 * the account, that webhook answers 503 and the administrators get one
 * notification per webhook. Before this step those webhooks answered 401 to
 * every correctly signed delivery, so no working delivery stops.
 *
 * Idempotent, and the sources are left as they are. The work is done by the
 * shared {@see WebhookTrustMigrator}.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\Intake\WebhookTrustMigrator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Creates each webhook's consumer from its legacy source trust.
 *
 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
 */
class MigrateWebhookConnections implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the migrator lazily, so the step loads without OpenRegister.
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/design.md
	 */
	public function getName(): string {
		return 'Move the trust of the signed public webhooks into their consumers';

	}//end getName()

	/**
	 * Create each webhook's consumer once.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/public-webhooks-on-the-consumer-model/specs/consumer-management/spec.md#scenario-an-upgrade-moves-the-source-trust-into-the-consumer
	 */
	public function run(IOutput $output): void {
		try {
			$migrator = $this->container->get(WebhookTrustMigrator::class);
		} catch (Throwable $exception) {
			$output->warning('Webhook connection migration skipped: OpenRegister is not available (' . $exception->getMessage() . ').');
			return;
		}

		foreach (WebhookProfiles::all() as $profile) {
			try {
				$created = $migrator->migrate(profile: $profile);
			} catch (Throwable $exception) {
				$output->warning('The ' . $profile->label . ' connection was not migrated: ' . $exception->getMessage());
				continue;
			}

			if ($created === true) {
				$output->info(
					'Created the ' . $profile->authorizationType . ' consumer from the source webhook trust. '
					. 'Choose the account the ' . $profile->label . ' webhook acts as.'
				);
			}
		}

	}//end run()
}//end class
