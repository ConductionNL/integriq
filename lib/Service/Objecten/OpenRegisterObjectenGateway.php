<?php

/**
 * Builds the Objecten facade's services with every seam wired.
 *
 * The handlers were written against callables so each could be tested without
 * an OpenRegister. Nothing handed them one, so every route answered 401 (no
 * token was ever loaded) or an empty list. This class hands every handler its
 * seams from {@see ObjectenOpenRegisterAccess}, and loads the declared objecttypes and
 * tokens once per request.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

use Psr\Log\LoggerInterface;

/**
 * Builds the facade's services with every seam wired.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class OpenRegisterObjectenGateway {

	/**
	 * The one registry every service of this request shares.
	 *
	 * @var ObjecttypeRegistry|null
	 */
	private ?ObjecttypeRegistry $registry = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectenOpenRegisterAccess       $access       OpenRegister, as the seams need it.
	 * @param LoggerInterface                  $logger       Records a refused declaration.
	 * @param ObjecttypeDeclarationReader|null $declarations The objecttypes leaf apps declare (design D8);
	 *                                                       null reads none.
	 */
	public function __construct(
		private readonly ObjectenOpenRegisterAccess $access,
		private readonly LoggerInterface $logger,
		private readonly ?ObjecttypeDeclarationReader $declarations = null,
	) {
	}//end __construct()

	/**
	 * The published objecttypes, loaded once per request.
	 *
	 * @return ObjecttypeRegistry The registry.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-an-objecttype-is-a-declared-mapping-onto-a-register-and-schema-req-oaf-001
	 */
	public function registry(): ObjecttypeRegistry {
		if ($this->registry !== null) {
			return $this->registry;
		}

		$this->registry = new ObjecttypeRegistry();
		$this->registry->load(declarations: $this->objecttypeDeclarations());

		foreach ($this->registry->refused() as $refusal) {
			$declaredBy = '';
			if (is_array($refusal['declaration']) === true && isset($refusal['declaration']['declaredBy']) === true) {
				$declaredBy = ' (declared by ' . (string)$refusal['declaration']['declaredBy'] . ')';
			}

			$this->logger->warning('Integriq objecten: an objecttype declaration was refused' . $declaredBy . ': ' . $refusal['reason']);
		}

		return $this->registry;
	}//end registry()

	/**
	 * The token service with the broker behind it and the declared tokens loaded.
	 *
	 * @return ObjectenTokenService The service.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-token-carries-a-permission-per-objecttype-req-oaf-005
	 */
	public function tokens(): ObjectenTokenService {
		$tokens = new ObjectenTokenService(
			objecttypes: $this->registry(),
			credentialRead: fn (string $reference): ?string => $this->access->readCredential(reference: $reference)
		);

		foreach ($tokens->load(declarations: $this->access->declarations(schema: ObjectenOpenRegisterAccess::TOKEN_SCHEMA)) as $reason) {
			$this->logger->warning('Integriq objecten: a token declaration was refused: ' . $reason);
		}

		return $tokens;
	}//end tokens()

	/**
	 * The Objecttypen API handler.
	 *
	 * @return ObjecttypeEndpointHandler The handler.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The seam hands (register, schema); a schema
	 * slug resolves on its own in OpenRegister's schema mapper, so the register is not needed.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-the-objecttypen-api-serves-the-schema-it-stands-for-req-oaf-002
	 */
	public function types(): ObjecttypeEndpointHandler {
		return new ObjecttypeEndpointHandler(
			objecttypes: $this->registry(),
			schemaRead: fn (string $register, string $schema): array => $this->access->readSchema(schema: $schema)
		);
	}//end types()

	/**
	 * The Objecten API read handler.
	 *
	 * @return ObjectEndpointHandler The handler.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-the-objecten-api-reads-objects-in-the-standards-shape-req-oaf-003
	 */
	public function objects(): ObjectEndpointHandler {
		return new ObjectEndpointHandler(
			objecttypes: $this->registry(),
			translator: new ObjectRecordTranslator(),
			objectRead: fn (string $register, string $schema, string $principal = ''): array => $this->access->readObjects(
				register: $register,
				schema: $schema,
				principal: $principal
			)
		);
	}//end objects()

	/**
	 * The Objecten API write handler.
	 *
	 * @return ObjectWriteHandler The handler.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-write-lands-in-openregister-and-announces-req-oaf-004
	 */
	public function writes(): ObjectWriteHandler {
		return new ObjectWriteHandler(
			objecttypes: $this->registry(),
			translator: new ObjectRecordTranslator(),
			objectWrite: fn (string $register, string $schema, ?string $uuid, array $data, string $principal): array => $this->access->writeObject(
				register: $register,
				schema: $schema,
				uuid: $uuid,
				data: $data,
				principal: $principal
			),
			objectDelete: function (string $register, string $schema, string $uuid, string $principal): void {
				$this->access->deleteObject(register: $register, schema: $schema, uuid: $uuid, principal: $principal);
			},
			announce: function (array $notification): void {
				$this->access->announce(notification: $notification);
			}
		);
	}//end writes()

	/**
	 * The declared objecttypes in the registry's shape.
	 *
	 * The published uuid is `publishedUuid`, not the configuration object's own
	 * id: a counterparty registers the published one, and a reseed that gives the
	 * configuration object a new id must not move it (design D1, Risks). The
	 * objecttypes leaf apps declare in their own `lib/Settings/objecttypes.json`
	 * follow the configured ones (design D8).
	 *
	 * @return array<int, mixed> The declarations.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-leaf-app-declares-the-objecttypes-it-publishes-req-oaf-006
	 */
	private function objecttypeDeclarations(): array {
		$declarations = [];
		foreach ($this->access->declarations(schema: ObjectenOpenRegisterAccess::OBJECTTYPE_SCHEMA) as $row) {
			$row['uuid'] = (string)($row['publishedUuid'] ?? '');
			unset($row['publishedUuid']);
			$declarations[] = $row;
		}

		// Configured first, declared after: for one uuid the administrator's
		// objecttype wins and the app's declaration is refused (design D8).
		if ($this->declarations !== null) {
			$declarations = array_merge($declarations, $this->declarations->read());
		}

		return $declarations;
	}//end objecttypeDeclarations()

}//end class
