<?php

/**
 * Integriq repair step: move the DSO STAM trust from app config into a consumer.
 *
 * Before dso-intake-through-an-integriq-connection the STAM signature trust
 * lived in app config (`dso_pki_*`) and the intake had no identity. Now it
 * lives on the instance's one `dso-stam` consumer, whose `userId` names the
 * account the intake acts as. This step copies the old keys into that
 * consumer once. It leaves `userId` empty: no step guesses an identity. The
 * administrators get one notification asking them to choose the account.
 * Until they do, DSO-LV pushes are answered with 503 and retried.
 *
 * The write is the app's own configuration on nobody's behalf, during a
 * repair, never during a request: the one use `runAsSystem()` allows. It is
 * the only system write of the change.
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
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#scenario-existing-configuration-migrates-without-an-account
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\Dso\DsoConnection;
use OCA\Integriq\Service\Dso\DsoConnectionAlerts;
use OCA\Integriq\Service\DSOSignatureVerifierService;
use OCA\Integriq\Service\SystemWrite;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Creates the dso-stam consumer from the legacy app config, once.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-5
 */
class MigrateDsoStamConnection implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig The legacy `dso_pki_*` keys.
	 * @param ContainerInterface $container Resolves the OpenRegister-backed services lazily.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-5
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The repair step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-5
	 */
	public function getName(): string {
		return 'Move the DSO STAM signature configuration into a dso-stam consumer';

	}//end getName()

	/**
	 * Create the consumer when the old keys are set and no consumer exists.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-5
	 */
	public function run(IOutput $output): void {
		$trust = $this->legacyTrust();
		if ($trust === null) {
			return;
		}

		try {
			$connection = $this->container->get(DsoConnection::class);
			$objectService = $this->container->get(OrObjectService::class);
			$alerts = $this->container->get(DsoConnectionAlerts::class);
		} catch (Throwable $exception) {
			$output->warning('DSO connection migration skipped: OpenRegister is not available (' . $exception->getMessage() . ').');
			return;
		}

		if ($connection->findConsumers() !== []) {
			return;
		}

		SystemWrite::run(
			what: 'the DSO connection migration',
			operation: static fn () => $objectService->saveObject(
				object: [
					'name' => 'DSO-LV (STAM)',
					'description' => 'The STAM koppelvlak of the Omgevingsloket (DSO-LV). Every push is stored as the account in userId.',
					'authorizationType' => DsoConnection::AUTHORIZATION_TYPE,
					'authorizationConfiguration' => $trust,
					'userId' => '',
				],
				register: DsoConnection::REGISTER,
				schema: DsoConnection::SCHEMA_CONSUMER
			)
		);

		$alerts->notify(reason: DsoConnectionAlerts::REASON_CHOOSE_ACCOUNT);
		$output->info('Created the dso-stam consumer from the dso_pki_* app config. Choose the account the DSO intake acts as.');

	}//end run()

	/**
	 * The legacy trust configuration, or null when no key is set.
	 *
	 * @return array<string, string>|null
	 */
	private function legacyTrust(): ?array {
		$read = fn (string $key): string => $this->appConfig->getValueString(Application::APP_ID, $key, '');

		$trust = [
			'mode' => $read(DSOSignatureVerifierService::CONFIG_MODE),
			'hmacSecret' => $read(DSOSignatureVerifierService::CONFIG_HMAC_SECRET),
			'signingCertificate' => $read(DSOSignatureVerifierService::CONFIG_SIGNING_CERTIFICATE),
			'intermediateChain' => $read(DSOSignatureVerifierService::CONFIG_INTERMEDIATE_CHAIN),
			'rootCa' => $read(DSOSignatureVerifierService::CONFIG_ROOT_CA),
		];

		if (implode('', $trust) === '') {
			return null;
		}

		$trust['mode'] = DSOSignatureVerifierService::normalizeMode(mode: $trust['mode']);

		return $trust;

	}//end legacyTrust()
}//end class
