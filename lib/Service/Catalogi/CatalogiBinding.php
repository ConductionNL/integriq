<?php

/**
 * The Catalogi facade's binding: which catalogue it serves, over which schema.
 *
 * An admin-only `catalogi_binding` names the catalogue (domein, rsin, naam and
 * the uuid it is published under), the register and schema that hold the
 * organisation's document types, a field map from that schema to the
 * InformatieObjectType resource, and the ZGW clients that may read it. The
 * default field map fits a document-type schema with `name`, `confidentiality`,
 * `category`, `categoryUri`, `active`, `identifier` and `validFrom`; no app id is
 * named here, the operator binds the facade to a register and schema.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalogi
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
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Catalogi;

/**
 * One resolved binding, and the mapping of a stored document type onto the
 * Catalogi resource.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */
final class CatalogiBinding {

	/**
	 * The schema a binding is declared in.
	 *
	 * @var string
	 */
	public const SCHEMA = 'catalogi_binding';

	/**
	 * The read scope a client needs (VNG Autorisaties).
	 *
	 * @var string
	 */
	public const SCOPE_READ = 'catalogi.lezen';

	/**
	 * Resource field => stored field. A leading `!` inverts a boolean.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULT_FIELD_MAP = [
		'omschrijving' => 'name',
		'vertrouwelijkheidaanduiding' => 'confidentiality',
		'informatieobjectcategorie' => 'category',
		'informatieobjectcategorieUri' => 'categoryUri',
		'concept' => '!active',
		'identificatie' => 'identifier',
		'beginGeldigheid' => 'validFrom',
	];

	/**
	 * Constructor.
	 *
	 * @param string                           $uuid     The uuid the catalogue is published under.
	 * @param array<string, string>            $catalog  `domein`, `rsin` and `naam`.
	 * @param string                           $register The register holding the document types.
	 * @param string                           $schema   The schema holding the document types.
	 * @param array<string, string>            $fieldMap Resource field => stored field.
	 * @param array<int, array<string, mixed>> $clients  The clients: `clientId`, `scopes`, `concepts`.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly array $catalog,
		public readonly string $register,
		public readonly string $schema,
		public readonly array $fieldMap,
		public readonly array $clients,
	) {

	}//end __construct()

	/**
	 * The first complete binding whose schema resolves, or null.
	 *
	 * @param array<int, array<string, mixed>> $rows         The stored `catalogi_binding` objects.
	 * @param callable(string, string): bool   $schemaExists Whether a register and schema resolve.
	 *
	 * @return self|null Null without a binding, or when its schema does not resolve.
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public static function resolve(array $rows, callable $schemaExists): ?self {
		foreach ($rows as $row) {
			$binding = self::fromObject(row: $row);
			if ($binding !== null && $schemaExists($binding->register, $binding->schema) === true) {
				return $binding;
			}
		}

		return null;
	}//end resolve()

	/**
	 * A binding from one stored object, or null when it lacks the uuid, the
	 * register or the schema.
	 *
	 * @param array<string, mixed> $row The stored object.
	 *
	 * @return self|null
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public static function fromObject(array $row): ?self {
		$uuid = trim((string)($row['publishedUuid'] ?? ''));
		$register = trim((string)($row['register'] ?? ''));
		$schema = trim((string)($row['schema'] ?? ''));
		if ($uuid === '' || $register === '' || $schema === '') {
			return null;
		}

		$fieldMap = self::DEFAULT_FIELD_MAP;
		foreach ((array)($row['fieldMap'] ?? []) as $field => $stored) {
			if (is_string($field) === true && is_string($stored) === true && trim($stored) !== '') {
				$fieldMap[$field] = trim($stored);
			}
		}

		return new self(
			uuid: $uuid,
			catalog: [
				'domein' => (string)($row['domein'] ?? ''),
				'rsin' => (string)($row['rsin'] ?? ''),
				'naam' => (string)($row['naam'] ?? ''),
			],
			register: $register,
			schema: $schema,
			fieldMap: $fieldMap,
			clients: array_values(array_filter((array)($row['clients'] ?? []), 'is_array'))
		);
	}//end fromObject()

	/**
	 * A stored document type as the resource's own fields (without urls).
	 *
	 * @param array<string, mixed> $object The stored object.
	 *
	 * @return array<string, mixed> `uuid`, `omschrijving`, `vertrouwelijkheidaanduiding`,
	 *                              `informatieobjectcategorie`, `informatieobjectcategorieUri`,
	 *                              `concept` (bool), `identificatie`, `beginGeldigheid` (date or null).
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function mapType(array $object): array {
		$begin = trim((string)$this->read(object: $object, field: 'beginGeldigheid'));
		$beginDate = null;
		if ($begin !== '') {
			$beginDate = substr($begin, 0, 10);
		}

		return [
			'uuid' => (string)($object['@self']['id'] ?? ($object['id'] ?? ($object['uuid'] ?? ''))),
			'omschrijving' => (string)$this->read(object: $object, field: 'omschrijving'),
			'vertrouwelijkheidaanduiding' => (string)$this->read(object: $object, field: 'vertrouwelijkheidaanduiding'),
			'informatieobjectcategorie' => (string)$this->read(object: $object, field: 'informatieobjectcategorie'),
			'informatieobjectcategorieUri' => (string)$this->read(object: $object, field: 'informatieobjectcategorieUri'),
			'concept' => ($this->read(object: $object, field: 'concept') === true),
			'identificatie' => (string)$this->read(object: $object, field: 'identificatie'),
			'beginGeldigheid' => $beginDate,
		];
	}//end mapType()

	/**
	 * A stored object for a new type, from resource fields, through the field map.
	 *
	 * @param array<string, mixed> $resource Resource field => value.
	 *
	 * @return array<string, mixed> Stored field => value.
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
	 */
	public function storedFrom(array $resource): array {
		$stored = [];
		foreach ($resource as $field => $value) {
			$target = ($this->fieldMap[$field] ?? '');
			if ($target === '') {
				continue;
			}

			if (str_starts_with($target, '!') === true) {
				$stored[substr($target, 1)] = !(bool)$value;
				continue;
			}

			$stored[$target] = $value;
		}

		return $stored;
	}//end storedFrom()

	/**
	 * The client entry a ZGW client id is authorised by, or null.
	 *
	 * @param string $clientId The JWT's client id.
	 *
	 * @return array{scopes: array<int, string>, concepts: bool}|null
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-only-an-authorised-client-reads-and-concept-types-stay-hidden-req-zcs-002
	 */
	public function clientFor(string $clientId): ?array {
		if (trim($clientId) === '') {
			return null;
		}

		foreach ($this->clients as $client) {
			if (trim((string)($client['clientId'] ?? '')) === trim($clientId)) {
				return [
					'scopes' => array_values(array_map('strval', (array)($client['scopes'] ?? []))),
					'concepts' => (($client['concepts'] ?? false) === true),
				];
			}
		}

		return null;
	}//end clientFor()

	/**
	 * One resource field read through the field map.
	 *
	 * @param array<string, mixed> $object The stored object.
	 * @param string               $field  The resource field.
	 *
	 * @return mixed The value, inverted for a `!` mapping; null when absent.
	 */
	private function read(array $object, string $field): mixed {
		$stored = ($this->fieldMap[$field] ?? '');
		if ($stored === '') {
			return null;
		}

		if (str_starts_with($stored, '!') === true) {
			$value = ($object[substr($stored, 1)] ?? null);

			// An absent flag is not a statement: a type without `active` is not a concept.
			if ($value === null) {
				return false;
			}

			return !(bool)$value;
		}

		$value = ($object[$stored] ?? null);

		if (is_scalar($value) === false) {
			return null;
		}

		return $value;
	}//end read()

}//end class
