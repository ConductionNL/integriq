<?php

/**
 * Integriq OpenRegisterFeedSchemaFields: schema fields read from OpenRegister.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Feed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Feed;

use OCA\Integriq\Service\EndpointTargetResolver;
use OCA\OpenRegister\Db\SchemaMapper;
use Throwable;

/**
 * Resolves a register and schema by id or slug, the way an endpoint target
 * is resolved, and answers the schema's property names.
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */
class OpenRegisterFeedSchemaFields implements FeedSchemaFieldsInterface {

	/**
	 * Constructor.
	 *
	 * @param EndpointTargetResolver $resolver     Resolves a register and schema slug to ids.
	 * @param SchemaMapper           $schemaMapper Reads the schema.
	 */
	public function __construct(
		private readonly EndpointTargetResolver $resolver,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()

	/**
	 * The property names a schema declares.
	 *
	 * @param string $register The register id or slug.
	 * @param string $schema   The schema id or slug.
	 *
	 * @return array<int, string>|null The names, or null when the register or schema does not exist.
	 *
	 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
	 */
	public function fieldsOf(string $register, string $schema): ?array {
		try {
			[, $schemaId] = $this->resolver->resolve(targetId: $register . '/' . $schema);
			$entity = $this->schemaMapper->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		$properties = $entity->getProperties();
		if (is_array($properties) === false) {
			return [];
		}

		return array_map('strval', array_keys($properties));
	}//end fieldsOf()
}//end class
