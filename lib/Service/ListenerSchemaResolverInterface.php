<?php

/**
 * Contract of the ListenerSchemaResolver.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see ListenerSchemaResolver} that other components call.
 */
interface ListenerSchemaResolverInterface {
	/**
	 * Whether an entity belongs to integriq's own OpenRegister register.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return bool True when the entity's register is integriq's, by slug or by resolved id.
	 *
	 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
	 */
	public function isOwnRegister(?object $entity): bool;

	/**
	 * Whether an entity is an object of the named schema in integriq's register.
	 *
	 * @param object|null $entity       The OpenRegister ObjectEntity from the event.
	 * @param string      $expectedSlug The schema slug to match (for example `event`).
	 *
	 * @return bool True only when the entity sits in integriq's register and its schema is that slug.
	 *
	 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
	 */
	public function matchesSchema(?object $entity, string $expectedSlug): bool;
}
