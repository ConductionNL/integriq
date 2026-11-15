<?php

/**
 * A registry binding that renames source fields per target schema.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Registry
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


namespace OCA\Integriq\Service\Registry;

/**
 * The BRP calls an address `verblijfplaats`; the schema that follows a person
 * may call it `residence`. OpenRegister applies only what a schema owns, so
 * the translation happens here, per target schema (decision 178).
 *
 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
 */
interface MapsSourceFieldsInterface {
	/**
	 * Which source field becomes which property of the target schema.
	 *
	 * @param string $targetSchema The target schema's slug.
	 *
	 * @return array<string,string>|null Source field to schema property, or null when this binding
	 *                                   has no map for that schema (the change then goes out unchanged).
	 *
	 * @spec openspec/changes/registry-update-maps-source-fields/specs/registry-subscription-connector/spec.md#requirement-a-change-is-posted-in-each-target-schemas-own-property-names-req-rsc-004
	 */
	public function fieldMapFor(string $targetSchema): ?array;
}//end interface
