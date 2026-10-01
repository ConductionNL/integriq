<?php

/**
 * Installs a packaged ZGW set against the register and schema an operator chooses.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Zgw;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\Service\NotificatiesSubscriberService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use stdClass;

/**
 * Binds a set's seeded synchronizations (register.d/zgw-consumer-sets.json) to a
 * target register and schema, after both ZgwSetInstallGuard checks pass.
 *
 * A pull writes into the bound schema; a write-back reads from it. Every
 * synchronization is found before any is saved, so a set never half-binds:
 * a pull bound without its push would read the store and never write back,
 * while every screen showed the set as installed.
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
 *
 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSetCatalogue is a catalogue of constants with no
 * state to instantiate, the same reason ZgwSetInstallGuard gives.
 */
class ZgwSetInstaller {

	/**
	 * App config key holding the bindings, as JSON {"register/schema": "set slug"}.
	 */
	public const BINDINGS_KEY = 'zgw_set_bindings';

	/**
	 * App config key holding the notification abonnementen, as JSON {"data set slug": "abonnement uuid"}.
	 */
	public const SUBSCRIPTIONS_KEY = 'zgw_set_subscriptions';

	/**
	 * Where the packaged set files live.
	 */
	private const SET_DIR = __DIR__ . '/../../Settings/configurations';

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objectService OpenRegister objects, for the seeded synchronizations.
	 * @param IAppConfig         $appConfig     Holds the bindings.
	 * @param ZgwSetInstallGuard            $guard        The template and binding refusals.
	 * @param NotificatiesSubscriberService $subscriber   Registers the abonnementen a subscription set installs.
	 * @param string                        $setDirectory Where the packaged set files are read from.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ZgwSetInstallGuard $guard,
		private readonly NotificatiesSubscriberService $subscriber,
		private readonly string $setDirectory = self::SET_DIR,
	) {
	}//end __construct()

	/**
	 * Install a set: bind its synchronizations to register/schema and record the binding.
	 *
	 * @param string $slug     The set slug, one of ZgwSetCatalogue::SETS.
	 * @param string $register The target register (id or slug).
	 * @param string $schema   The target schema (id or slug).
	 *
	 * @return array<string, mixed> The set, its binding (null for a subscription set) and the bound
	 *                              synchronizations; a subscription set adds subscriptions and refused.
	 *
	 * @throws ZgwSetInstallRefusedException When a guard refuses or a seeded synchronization is missing.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	public function install(string $slug, string $register, string $schema): array {
		$bindings = $this->bindings();
		$refusal  = $this->guard->refuse(
			slug: $slug,
			target: ['register' => $register, 'schema' => $schema],
			bindings: $bindings
		);
		if ($refusal !== null) {
			throw new ZgwSetInstallRefusedException($refusal);
		}

		$template = $this->template(slug: $slug);
		$refusals = $this->guard->refuseTemplate(slug: $slug, template: $template);
		if ($refusals !== []) {
			throw new ZgwSetInstallRefusedException(implode(' ', $refusals));
		}

		if (ZgwSetCatalogue::isSubscriptionSet(slug: $slug) === true) {
			return $this->subscribe(slug: $slug, template: $template, bindings: $bindings);
		}

		$binding = $this->guard->bindingKey(register: trim($register), schema: trim($schema));
		$bound   = [];
		foreach ($this->seededSynchronizations(slugs: (array)($template['synchronizations'] ?? [])) as $syncSlug => $sync) {
			$bound[$syncSlug] = $this->bind(synchronization: $sync['object'], binding: $binding) + ['uuid' => $sync['uuid']];
		}

		foreach ($bound as $object) {
			$uuid = $object['uuid'];
			unset($object['uuid']);
			$this->objectService->saveObject(object: $object, register: 'integriq', schema: 'synchronization', uuid: $uuid);
		}

		$bindings = array_filter($bindings, fn (string $holder): bool => $holder !== $slug);
		$bindings[$binding] = $slug;
		$this->appConfig->setValueString(Application::APP_ID, self::BINDINGS_KEY, (string)json_encode($bindings));

		return ['set' => $slug, 'binding' => $binding, 'synchronizations' => array_keys($bound)];
	}//end install()

	/**
	 * Install a subscription set: one abonnement per installed data set that has none yet.
	 *
	 * A registration the store refuses is reported and not recorded, so
	 * installing the set again retries it (design D3).
	 *
	 * @param string                $slug     The subscription set.
	 * @param array<string, mixed>  $template Its packaged file.
	 * @param array<string, string> $bindings The data set bindings.
	 *
	 * @return array{set: string, binding: null, synchronizations: list<string>, subscriptions: array<string, string>, refused: array<string, string>}
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function subscribe(string $slug, array $template, array $bindings): array {
		$subscriptions = $this->subscriptions();
		$refused       = [];
		$sourceId      = $this->sourceUuid(slug: (string)($template['source']['slug'] ?? ''));
		foreach (array_values(array_unique($bindings)) as $dataSet) {
			if (isset($subscriptions[$dataSet]) === true) {
				continue;
			}

			$kanalen = array_map(
				// The Notificaties API takes filters as an object; [] would encode as a list.
				fn (string $kanaal): array => ['naam' => $kanaal, 'filters' => new stdClass()],
				array_keys(ZgwSetCatalogue::KANAAL_SETS, $dataSet, true)
			);

			$abonnement = $this->subscriber->createAbonnement(
				config: [
					'name'     => sprintf('%s notifications (%s)', (ZgwSetCatalogue::SETS[$dataSet] ?? $dataSet), $dataSet),
					'sourceId' => $sourceId,
					'kanalen'  => $kanalen,
				]
			);
			$data       = $abonnement->getObject();
			if (($data['status'] ?? null) !== 'active') {
				$refused[$dataSet] = (string)($data['lastError'] ?? 'The store did not register the abonnement.');
				continue;
			}

			$subscriptions[$dataSet] = (string)$abonnement->getUuid();
		}//end foreach

		$this->appConfig->setValueString(Application::APP_ID, self::SUBSCRIPTIONS_KEY, (string)json_encode($subscriptions));

		return ['set' => $slug, 'binding' => null, 'synchronizations' => [], 'subscriptions' => $subscriptions, 'refused' => $refused];
	}//end subscribe()

	/**
	 * The uuid of a seeded source: an abonnement names its source by uuid, not by slug.
	 *
	 * @param string $slug The source slug from the set file.
	 *
	 * @return string The uuid.
	 *
	 * @throws ZgwSetInstallRefusedException When this instance lacks the source.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	private function sourceUuid(string $slug): string {
		$source = null;
		try {
			$source = $this->objectService->find(id: $slug, register: 'integriq', schema: 'source');
		} catch (\Throwable) {
			$source = null;
		}

		if ($source === null || (string)$source->getUuid() === '') {
			throw new ZgwSetInstallRefusedException(
				sprintf(
					'The source "%s" this set registers its abonnementen on is not on this instance. '
					.'Repair or reinstall Integriq so its packaged sets are imported, then install the set again.',
					$slug
				)
			);
		}

		return (string)$source->getUuid();
	}//end sourceUuid()

	/**
	 * The recorded abonnementen, {"data set slug": "abonnement uuid"}.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	public function subscriptions(): array {
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::SUBSCRIPTIONS_KEY, '{}'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_filter($decoded, fn ($uuid, $key): bool => is_string($uuid) && is_string($key), ARRAY_FILTER_USE_BOTH);
	}//end subscriptions()

	/**
	 * The recorded bindings, {"register/schema": "set slug"}.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	public function bindings(): array {
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::BINDINGS_KEY, '{}'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_filter($decoded, fn ($holder, $key): bool => is_string($holder) && is_string($key), ARRAY_FILTER_USE_BOTH);
	}//end bindings()

	/**
	 * The packaged set file.
	 *
	 * @param string $slug The set slug (already checked to be packaged).
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ZgwSetInstallRefusedException When the file is missing or unreadable.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-six-packaged-slug-referenced-zgw-consumer-sets-req-zgwc-001
	 */
	private function template(string $slug): array {
		$path     = $this->setDirectory . '/' . basename($slug) . '.json';
		$template = null;
		if (is_readable($path) === true) {
			$template = json_decode((string)file_get_contents($path), true);
		}
		if (is_array($template) === false) {
			throw new ZgwSetInstallRefusedException(sprintf('The set file for "%s" is missing from this installation.', $slug));
		}

		return $template;
	}//end template()

	/**
	 * Find every named synchronization before anything is changed.
	 *
	 * @param array<int, mixed> $slugs The synchronization slugs the set names.
	 *
	 * @return array<string, array{uuid: string|null, object: array<string, mixed>}>
	 *
	 * @throws ZgwSetInstallRefusedException Naming the first one this instance lacks.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	private function seededSynchronizations(array $slugs): array {
		$found = [];
		foreach ($slugs as $syncSlug) {
			$entity = null;
			try {
				$entity = $this->objectService->find(id: (string)$syncSlug, register: 'integriq', schema: 'synchronization');
			} catch (\Throwable) {
				$entity = null;
			}

			if ($entity === null) {
				throw new ZgwSetInstallRefusedException(
					sprintf(
						'The synchronization "%s" this set needs is not on this instance. '
						.'Repair or reinstall Integriq so its packaged sets are imported, then install the set again.',
						(string)$syncSlug
					)
				);
			}

			$found[(string)$syncSlug] = ['uuid' => $entity->getUuid(), 'object' => $entity->getObject()];
		}

		return $found;
	}//end seededSynchronizations()

	/**
	 * Point one synchronization at the binding: a pull writes there, a write-back reads there.
	 *
	 * @param array<string, mixed> $synchronization The seeded synchronization.
	 * @param string               $binding         "register/schema".
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	private function bind(array $synchronization, string $binding): array {
		if (($synchronization['sourceType'] ?? null) === 'register/schema') {
			$synchronization['sourceId'] = $binding;
			return $synchronization;
		}

		$synchronization['targetType'] = 'register/schema';
		$synchronization['targetId']   = $binding;
		return $synchronization;
	}//end bind()
}//end class
