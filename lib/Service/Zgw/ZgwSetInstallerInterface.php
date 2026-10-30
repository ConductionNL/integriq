<?php

/**
 * Contract of the ZgwSetInstaller.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Zgw;

/**
 * Public operations of {@see ZgwSetInstaller} that other components call.
 */
interface ZgwSetInstallerInterface {
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
	public function install(string $slug, string $register, string $schema): array;

	/**
	 * The recorded abonnementen, {"data set slug": "abonnement uuid"}.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-an-external-change-shows-within-a-minute-and-a-local-change-writes-back-req-zgwc-003
	 */
	public function subscriptions(): array;

	/**
	 * The recorded bindings, {"register/schema": "set slug"}.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/zgw-connectors-for-dossiq/specs/zgw-consumer-connectors/spec.md#requirement-a-set-binds-to-an-operator-chosen-register-and-schema-req-zgwc-002
	 */
	public function bindings(): array;
}
