<?php

/**
 * Integriq Listener Schema Resolver.
 *
 * Tells an OpenRegister event listener whether an object belongs to one of
 * integriq's own schemas, given the object as OpenRegister really emits it.
 *
 * OpenRegister stamps the numeric ids of the register and schema onto every
 * {@see \OCA\OpenRegister\Db\ObjectEntity} it saves
 * (`SaveObject`: `(string)$registerId`, `(string)$schemaId`), and
 * `ObjectEntity` declares `getRegister()` and `getSchema()` concretely. A
 * listener that compares those values with a slug literal (`integriq`,
 * `event`) can therefore never match, which is how the Peppol outbound
 * consumer came to drop every request (integriq#1222). Before OpenRegister
 * declared the getters, a `method_exists()` probe on them was false and the
 * scoping was skipped altogether, so both states were wrong.
 *
 * This resolver turns the ids back into slugs. It is a port of the resolver
 * shillinq, decidiq and learniq use, with three properties kept:
 *
 * 1. Register-scoped. A schema slug alone is not unique on an instance (two
 *    apps can both have an `event` schema), so a schema match is only
 *    reported inside integriq's own register.
 * 2. Container-resolved. OpenRegister's mappers are pulled from the DI
 *    container at call time, so building this class never loads them.
 * 3. Fail-closed. Every failure (mapper missing, id unknown, accessor
 *    throwing) answers "not ours" and logs a warning, so an unresolvable
 *    object can never be mistaken for a match.
 *
 * A slug is still accepted as-is, so an entity that already carries a slug
 * (a future OpenRegister, or a hand-built entity) keeps matching.
 *
 * The lookups skip OpenRegister's RBAC and multitenancy filters on purpose:
 * this only reads a register's or schema's slug to classify an object, it
 * grants nothing, and an event raised in a user's request must not be
 * classified differently because that user's organisation cannot list
 * integriq's register. {@see \OCA\OpenRegister\Db\RegisterMapper::find()} and
 * {@see \OCA\OpenRegister\Db\SchemaMapper::find()} cache per request, so a
 * burst of events does not add a query per event.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves an OpenRegister object's register and schema ids to slugs, scoped
 * to integriq's own register.
 *
 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
 */
class ListenerSchemaResolver {

	/**
	 * Integriq's OpenRegister register slug.
	 *
	 * Frozen: this is the OpenRegister REGISTER SLUG, not the app id.
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'integriq';

	/**
	 * FQCN of OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	/**
	 * FQCN of OpenRegister's register mapper.
	 *
	 * @var string
	 */
	private const REGISTER_MAPPER = 'OCA\\OpenRegister\\Db\\RegisterMapper';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container, from which OpenRegister's mappers are resolved at call time.
	 * @param LoggerInterface $logger Logger for resolution failures.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether an entity is an object of the named schema in integriq's register.
	 *
	 * The register is checked first and always, so a schema that happens to
	 * share the slug in another app's register never matches.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 * @param string $expectedSlug The schema slug to match (for example `event`).
	 *
	 * @return bool True only when the entity sits in integriq's register and its schema is that slug.
	 *
	 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
	 */
	public function matchesSchema(?object $entity, string $expectedSlug): bool {
		if ($expectedSlug === '') {
			return false;
		}

		if ($this->isOwnRegister(entity: $entity) === false) {
			return false;
		}

		$rawSchema = $this->readAccessor(entity: $entity, getter: 'getSchema');
		if ($rawSchema === '') {
			return false;
		}

		// The entity already carries a slug rather than an id.
		if (strcasecmp($rawSchema, $expectedSlug) === 0) {
			return true;
		}

		return strcasecmp($this->resolveSlug(service: self::SCHEMA_MAPPER, id: $rawSchema), $expectedSlug) === 0;

	}//end matchesSchema()

	/**
	 * Whether an entity belongs to integriq's own OpenRegister register.
	 *
	 * @param object|null $entity The OpenRegister ObjectEntity from the event.
	 *
	 * @return bool True when the entity's register is integriq's, by slug or by resolved id.
	 *
	 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
	 */
	public function isOwnRegister(?object $entity): bool {
		$rawRegister = $this->readAccessor(entity: $entity, getter: 'getRegister');
		if ($rawRegister === '') {
			return false;
		}

		// The entity already carries a slug rather than an id.
		if (strcasecmp($rawRegister, self::REGISTER_SLUG) === 0) {
			return true;
		}

		return strcasecmp(
			$this->resolveSlug(service: self::REGISTER_MAPPER, id: $rawRegister),
			self::REGISTER_SLUG
		) === 0;

	}//end isOwnRegister()

	/**
	 * Read a scalar value off an entity through an accessor that may be magic.
	 *
	 * Calls the accessor rather than probing it with `method_exists()`, which
	 * is false for anything Nextcloud's `Entity` serves through `__call()`.
	 * `Entity::__call()` throws for an unknown property, and a non-scalar
	 * value is treated as absent.
	 *
	 * @param object|null $entity The entity to read.
	 * @param string $getter The accessor name (for example `getSchema`).
	 *
	 * @return string The value as a string, or '' when unavailable.
	 */
	private function readAccessor(?object $entity, string $getter): string {
		if ($entity === null) {
			return '';
		}

		try {
			$value = $entity->{$getter}();
		} catch (Throwable $e) {
			return '';
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;

	}//end readAccessor()

	/**
	 * Look up the slug of a register or schema by id.
	 *
	 * @param string $service The mapper FQCN (SchemaMapper or RegisterMapper).
	 * @param string $id The id to resolve.
	 *
	 * @return string The slug, or '' when it cannot be resolved.
	 */
	private function resolveSlug(string $service, string $id): string {
		try {
			$mapper = $this->container->get($service);
			// Both mappers take `_rbac` and `_multitenancy` by name; see the class
			// docblock for why a slug lookup skips them.
			$entity = $mapper->find($id, _rbac: false, _multitenancy: false);
			if (is_object($entity) === true) {
				// The slug accessor is magic on OpenRegister's Register and
				// Schema entities, so it is called, not probed.
				return $this->readAccessor(entity: $entity, getter: 'getSlug');
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ListenerSchemaResolver] could not resolve an OpenRegister slug, so the object is not treated as integriq\'s',
				[
					'service' => $service,
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return '';

	}//end resolveSlug()
}//end class
