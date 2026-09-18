<?php

/**
 * The contract every registry-backed property source binds to.
 *
 * @category Contract
 * @package  OCA\Integriq\PropertySource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\PropertySource;

/**
 * One contract, three calls, a binding per registry.
 *
 * A schema property declaring `x-openregister-property-source` names a
 * provider id here. openregister resolves through this contract; a leaf app
 * never reaches a registry on its own.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-a-property-source-is-resolved-through-one-provider-contract-req-rfs-001
 */
interface PropertySourceProviderInterface {
	/**
	 * The provider id a schema property names.
	 *
	 * @return string Stable provider id, for example `bag`.
	 */
	public function id(): string;

	/**
	 * Type-ahead over a partial query. May answer from cache.
	 *
	 * @param string $query Partial query typed by a person.
	 * @param array<string,mixed> $config Declared provider configuration.
	 *
	 * @return array<int,array<string,mixed>> Suggestions, each with `identifier` and `label`.
	 */
	public function suggest(string $query, array $config = []): array;

	/**
	 * One authoritative read, keyed by the source's own identifier.
	 *
	 * @param string $identifier Identifier at the source.
	 * @param array<string,mixed> $config Declared provider configuration.
	 *
	 * @return array<string,mixed> The value as the source holds it.
	 *
	 * @throws \OCA\Integriq\PropertySource\Exception\SourceUnreachableException When the source did not answer.
	 * @throws \OCA\Integriq\PropertySource\Exception\MissingSourceConfigurationException When no source is configured.
	 */
	public function resolve(string $identifier, array $config = []): array;

	/**
	 * What this provider can answer and how fresh it keeps it.
	 *
	 * @return array{id:string,label:string,identifier:string,stalenessBudget:int,listShaped:bool}
	 */
	public function describe(): array;
}//end interface
