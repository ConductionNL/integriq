<?php

/**
 * Integriq MigrateOpenFormulierenConnection repair step.
 *
 * Moves the Open Formulieren webhook trust from the `open-formulieren`
 * source (`configuration.webhookSignature`) into the one `open-formulieren`
 * consumer, with an empty account. No step guesses an identity: until an
 * administrator chooses the account, submissions are refused with 503 and
 * the administrators get one notification.
 *
 * Idempotent: when an `open-formulieren` consumer exists, or no enabled
 * source carries a webhook secret, nothing is written. The source is left
 * as it is.
 *
 * The consumer write runs through OpenRegister's system operation context,
 * which the `runAsSystem()` docblock allows for "installation, migration,
 * repair, and seeding of the application's own shipped data". It never runs
 * during a request. The source read is an engine read of admin configuration.
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
 * Since public-webhooks-on-the-consumer-model the work is done by the
 * shared {@see WebhookTrustMigrator}, which every signed webhook uses.
 *
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\Intake\WebhookTrustMigrator;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Creates the open-formulieren consumer from the legacy source trust.
 *
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
 */
class MigrateOpenFormulierenConnection implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the migrator lazily, so the step loads without OpenRegister.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
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
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
	 */
	public function getName(): string {
		return 'Move the Open Formulieren webhook trust into an open-formulieren consumer';

	}//end getName()

	/**
	 * Create the consumer once, from the first enabled source with a webhook secret.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OpenFormulierenConnection::profile() is a pure, static
	 * description of the webhook; resolving the whole connection only to read it would be worse.
	 */
	public function run(IOutput $output): void {
		try {
			$migrator = $this->container->get(WebhookTrustMigrator::class);
		} catch (Throwable $exception) {
			$output->warning('Open Formulieren connection migration skipped: OpenRegister is not available (' . $exception->getMessage() . ').');
			return;
		}

		$created = $migrator->migrate(
			profile: OpenFormulierenConnection::profile(),
			name: 'Open Formulieren',
			description: 'Signed submissions of Open Formulieren. Every submission is stored as the account in userId.'
		);
		if ($created === true) {
			$output->info('Created the open-formulieren consumer from the source webhook trust. Choose the account the Open Formulieren intake acts as.');
		}

	}//end run()
}//end class
