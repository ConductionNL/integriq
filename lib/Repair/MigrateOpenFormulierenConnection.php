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
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\OpenFormulieren\OpenFormulierenConnection;
use OCA\Integriq\Service\OpenFormulierenIntakeService;
use OCA\Integriq\Service\SystemWrite;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Creates the open-formulieren consumer from the existing source's webhook trust.
 *
 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
 */
class MigrateOpenFormulierenConnection implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the OpenRegister-backed services lazily.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
	 */
	public function getName(): string {
		return 'Move the Open Formulieren webhook trust into an open-formulieren consumer';

	}//end getName()

	/**
	 * Create the consumer when there is a source secret and no consumer yet.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemWrite exposes only a static entry point, as in MigrateDsoStamConnection.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/tasks.md#task-5
	 */
	public function run(IOutput $output): void {
		try {
			$connection = $this->container->get(OpenFormulierenConnection::class);
			$objectService = $this->container->get(OrObjectService::class);
			$alerts = $this->container->get(DsoConnectionAlerts::class);
		} catch (Throwable $exception) {
			$output->warning('Open Formulieren connection migration skipped: OpenRegister is not available (' . $exception->getMessage() . ').');
			return;
		}

		if ($connection->findConsumers() !== []) {
			return;
		}

		$trust = $this->legacyTrust(objectService: $objectService);
		if ($trust === null) {
			return;
		}

		SystemWrite::run(
			what: 'the Open Formulieren connection migration',
			operation: static fn () => $objectService->saveObject(
				object: [
					'name' => 'Open Formulieren',
					'description' => 'Signed submissions of Open Formulieren. Every submission is stored as the account in userId.',
					'authorizationType' => OpenFormulierenConnection::AUTHORIZATION_TYPE,
					'authorizationConfiguration' => $trust,
					'userId' => '',
				],
				register: DsoConnection::REGISTER,
				schema: DsoConnection::SCHEMA_CONSUMER
			)
		);

		$alerts->notify(
			reason: DsoConnectionAlerts::REASON_CHOOSE_ACCOUNT,
			channel: DsoConnectionUnavailableException::CHANNEL_OPEN_FORMULIEREN
		);
		$output->info('Created the open-formulieren consumer from the source webhook trust. Choose the account the Open Formulieren intake acts as.');

	}//end run()

	/**
	 * The webhook trust of the first enabled open-formulieren source with a secret.
	 *
	 * Engine read of admin configuration (`_rbac: false`, `_render: false`).
	 *
	 * @param OrObjectService $objectService OpenRegister's object service.
	 *
	 * @return array<string, mixed>|null The trust, or null when no source carries a secret.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md#contract-gaps
	 */
	private function legacyTrust(OrObjectService $objectService): ?array {
		$matches = $objectService->findAll(
			config: [
				'filters' => [
					'register' => OpenFormulierenIntakeService::REGISTER,
					'schema' => OpenFormulierenIntakeService::SCHEMA_SOURCE,
					'type' => OpenFormulierenIntakeService::SOURCE_TYPE,
				],
			],
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);

		foreach ($results as $source) {
			if ($source instanceof ObjectEntity === false) {
				continue;
			}

			$raw = $objectService->find(
				id: (string)$source->getUuid(),
				register: OpenFormulierenIntakeService::REGISTER,
				schema: OpenFormulierenIntakeService::SCHEMA_SOURCE,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
			if ($raw instanceof ObjectEntity === false) {
				$raw = $source;
			}

			$data = $raw->getObject();
			if (($data['isEnabled'] ?? true) === false) {
				continue;
			}

			$signature = ($data['configuration']['webhookSignature'] ?? []);
			if (is_array($signature) === false || (string)($signature['secret'] ?? '') === '') {
				continue;
			}

			return [
				'scheme' => (string)($signature['scheme'] ?? OpenFormulierenConnection::DEFAULT_SCHEME),
				'secret' => (string)$signature['secret'],
				'header' => (string)($signature['header'] ?? OpenFormulierenConnection::DEFAULT_HEADER),
				'toleranceSeconds' => (int)($signature['toleranceSeconds'] ?? 300),
			];
		}//end foreach

		return null;

	}//end legacyTrust()
}//end class
