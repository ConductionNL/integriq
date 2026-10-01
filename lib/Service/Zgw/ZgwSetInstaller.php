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
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;

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
 */
class ZgwSetInstaller {

	/**
	 * App config key holding the bindings, as JSON {"register/schema": "set slug"}.
	 */
	public const BINDINGS_KEY = 'zgw_set_bindings';

	/**
	 * Where the packaged set files live.
	 */
	private const SET_DIR = __DIR__ . '/../../Settings/configurations';

	/**
	 * Constructor.
	 *
	 * @param ObjectService      $objectService OpenRegister objects, for the seeded synchronizations.
	 * @param IAppConfig         $appConfig     Holds the bindings.
	 * @param ZgwSetInstallGuard $guard         The template and binding refusals.
	 * @param string             $setDirectory  Where the packaged set files are read from.
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ZgwSetInstallGuard $guard,
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
	 * @return array{set: string, binding: string, synchronizations: list<string>}
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
