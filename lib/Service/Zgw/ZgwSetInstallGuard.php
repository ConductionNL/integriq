<?php

/**
 * What the installer refuses, and why each refusal is not a nicety.
 *
 * 🔴 TWO SETS ON ONE SCHEMA IS THE FAILURE THIS EXISTS FOR. Both
 * synchronizations run, both write the bound schema, and each overwrites what
 * the other just wrote. Nothing errors. The schema simply holds whichever set
 * ran last, changing every few minutes, and both source pages report a healthy
 * synchronization because from each one's side it is: it read its store and it
 * wrote its objects. An operator watching one page can watch it forever without
 * seeing the other one undoing it.
 *
 * So the second install is refused AND NAMES THE SET THAT HOLDS THE SCHEMA.
 * "Already bound" sends somebody looking through six sets; "bound by zgw-zaken"
 * is a thing they can act on in one step.
 *
 * 🔴 A SET NAMING A FLEET APP IS REFUSED TOO. App ids in this fleet move, and
 * the lookups they feed are duck-typed: a name nothing answers to does not
 * error, it returns false, and the integration becomes a silent no-op that
 * every screen reports as configured. A ZGW component name is a national
 * standard and does not move.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Integriq\Service\Zgw
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://Integriq.app
 *
 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Zgw;

/**
 * The refusals an operator meets before a set is installed.
 */
class ZgwSetInstallGuard {

	/**
	 * Why this set may not be installed against this target, if it may not.
	 *
	 * @param string                      $slug     The set being installed.
	 * @param array<string, mixed>        $target   The chosen `register` and `schema`.
	 * @param array<string, string>       $bindings Schema key to the set slug holding it.
	 *
	 * @return string|null The refusal, or null when the install may proceed.
	 */
	public function refuse(string $slug, array $target, array $bindings): ?string {
		if (ZgwSetCatalogue::isPackaged($slug) === false) {
			return sprintf(
				'"%s" is not one of the packaged ZGW sets (%s).',
				$slug,
				implode(', ', array_keys(ZgwSetCatalogue::SETS))
			);
		}

		$register = trim((string)($target['register'] ?? ''));
		$schema = trim((string)($target['schema'] ?? ''));

		if ($register === '' || $schema === '') {
			// Installing against nothing would create a synchronization with no
			// target, which runs, reads the store, and writes its objects
			// nowhere while reporting a successful run.
			return 'This set needs a register and a schema to write into. Choose both before installing it.';
		}

		$key = $this->bindingKey(register: $register, schema: $schema);
		$holder = ($bindings[$key] ?? null);

		if ($holder !== null && $holder !== $slug) {
			return sprintf(
				'This schema is already bound to "%s". Two sets on one schema overwrite each other every time '
				.'they run, and both report a healthy synchronization while doing it. Bind "%s" to a schema of '
				.'its own, or remove the "%s" binding first.',
				$holder,
				$slug,
				$holder
			);
		}

		return null;
	}//end refuse()

	/**
	 * Why this set template is not shippable, if it is not.
	 *
	 * Run over the packaged JSON at build time and at install time, because a
	 * set that names a fleet app fails silently at runtime and nowhere else.
	 *
	 * @param string               $slug     The set slug.
	 * @param array<string, mixed> $template The set's declaration.
	 *
	 * @return string[] The refusals, empty when the template is sound.
	 */
	public function refuseTemplate(string $slug, array $template): array {
		$refusals = [];

		$serialised = strtolower((string)json_encode($template));
		foreach (ZgwSetCatalogue::FLEET_APPS as $app) {
			if (preg_match('/\b'.preg_quote($app, '/').'\b/', $serialised) !== 1) {
				continue;
			}

			$refusals[] = sprintf(
				'The set "%s" names the fleet app "%s". App ids in this fleet move, and the lookups they feed '
				.'return false rather than erroring when they do, so the integration would become a silent '
				.'no-op that every screen reports as configured. Name the ZGW component instead.',
				$slug,
				$app
			);
		}

		if ((string)($template['auth'] ?? '') !== ZgwSetCatalogue::AUTH) {
			$refusals[] = sprintf('The set "%s" must declare "%s" auth.', $slug, ZgwSetCatalogue::AUTH);
		}

		if (trim((string)($template['apiVersion'] ?? '')) === '') {
			// A store answering an unknown version is the one thing a translator
			// cannot guess at, and guessing means writing 1.0 shapes into a 1.6
			// store with the mismatch showing up as missing fields much later.
			$refusals[] = sprintf('The set "%s" must declare the apiVersion it speaks.', $slug);
		}

		$refusals = array_merge($refusals, $this->refuseUnslugged(slug: $slug, template: $template));

		return $refusals;
	}//end refuseTemplate()

	/**
	 * The key one register and schema pair is held under.
	 *
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return string The key.
	 */
	public function bindingKey(string $register, string $schema): string {
		return $register.'/'.$schema;
	}//end bindingKey()

	/**
	 * Refuse references that are not slugs, per ADR-015.
	 *
	 * A numeric id in a shipped template points at whatever happens to hold that
	 * id on the installing instance. It resolves, it installs, and it wires the
	 * set to somebody else's mapping.
	 *
	 * @param string               $slug     The set slug.
	 * @param array<string, mixed> $template The declaration.
	 *
	 * @return string[] The refusals.
	 */
	private function refuseUnslugged(string $slug, array $template): array {
		$refusals = [];

		foreach (['synchronizations', 'mappings'] as $section) {
			foreach (($template[$section] ?? []) as $reference) {
				if (is_string($reference) === true && ctype_digit($reference) === false && $reference !== '') {
					continue;
				}

				$refusals[] = sprintf(
					'The set "%s" references a %s by id rather than by slug. A shipped id points at whatever '
					.'holds that id on the installing instance, so it resolves, installs, and wires the set to '
					.'somebody else\'s configuration.',
					$slug,
					rtrim($section, 's')
				);
			}
		}

		return $refusals;
	}//end refuseUnslugged()
}//end class
