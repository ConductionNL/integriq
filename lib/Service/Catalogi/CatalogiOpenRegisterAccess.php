<?php

/**
 * What the Catalogi facade asks of OpenRegister, answered by OpenRegister.
 *
 * The production side of every seam the handlers and the starter set take as
 * a callable, and the one place that knows which OpenRegister service answers
 * which question:
 * - the binding and the document types: the object service, in the system
 *   context. The binding is integriq's admin-only configuration; the types are
 *   read only after the gate checked the client's token and scope, and the
 *   Catalogi API serves them to every client holding catalogi.lezen;
 * - whether the bound schema resolves: the schema mapper;
 * - the Woo information categories: the concept register's
 *   `ConceptRepository::scheme(string $schemeUri): ?array` and
 *   `ConceptRepository::conceptsOf(string $schemeUri): array` (keyed by uri),
 *   for the TOOI scheme. No copy of the list is kept here (decision D3).
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

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenRegister, as the Catalogi seams need it.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */
class CatalogiOpenRegisterAccess {

	/**
	 * The register integriq's own configuration lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The TOOI scheme of the Woo information categories.
	 *
	 * @var string
	 */
	public const TOOI_SCHEME = 'https://identifier.overheid.nl/tooi/set/scw_woo_informatiecategorieen';

	/**
	 * OpenRegister's object service.
	 *
	 * @var string
	 */
	public const OBJECT_SERVICE = 'OCA\OpenRegister\Service\ObjectService';

	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var string
	 */
	public const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * OpenRegister's concept register.
	 *
	 * @var string
	 */
	public const CONCEPTS = 'OCA\OpenRegister\Service\Vocabulary\ConceptRepository';

	/**
	 * The most document types read at once.
	 *
	 * @var int
	 */
	private const LIMIT = 5000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the OpenRegister services lazily.
	 * @param LoggerInterface    $logger    Records what could not be read, never its detail.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The resolved binding, or null when there is none or its schema does not resolve.
	 *
	 * @return CatalogiBinding|null
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) CatalogiBinding::resolve() is the value object's named constructor.
	 */
	public function binding(): ?CatalogiBinding {
		return CatalogiBinding::resolve(
			rows: $this->rows(register: self::REGISTER, schema: CatalogiBinding::SCHEMA),
			schemaExists: fn (string $register, string $schema): bool => $this->schemaExists(schema: $schema)
		);
	}//end binding()

	/**
	 * The stored document types of a binding.
	 *
	 * @param CatalogiBinding $binding The binding.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function types(CatalogiBinding $binding): array {
		return $this->rows(register: $binding->register, schema: $binding->schema);
	}//end types()

	/**
	 * Create one document type in the bound schema.
	 *
	 * @param CatalogiBinding      $binding The binding.
	 * @param array<string, mixed> $stored  The stored fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
	 */
	public function createType(CatalogiBinding $binding, array $stored): void {
		$this->service(name: self::OBJECT_SERVICE)->saveObject(
			object: $stored,
			register: $binding->register,
			schema: $binding->schema
		);
	}//end createType()

	/**
	 * The Woo information categories, or null when the TOOI scheme cannot be read.
	 *
	 * @return list<array{uri: string, label: string}>|null
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-a-woo-starter-set-gives-one-type-per-woo-information-category-req-zcs-003
	 */
	public function wooCategories(): ?array {
		try {
			$concepts = $this->service(name: self::CONCEPTS);
			if ($concepts->scheme(self::TOOI_SCHEME) === null) {
				return null;
			}

			$found = $concepts->conceptsOf(self::TOOI_SCHEME);
		} catch (Throwable $exception) {
			$this->logger->warning('Integriq catalogi: the TOOI scheme could not be read (' . $exception::class . ').');
			return null;
		}

		$categories = [];
		foreach ((array)$found as $uri => $concept) {
			if (is_array($concept) === false) {
				continue;
			}

			$label = trim((string)($concept['prefLabel'] ?? ''));
			if ($label !== '') {
				$categories[] = ['uri' => (string)$uri, 'label' => $label];
			}
		}

		// The concept register answers an unreadable scheme with no concepts:
		// an empty list is never seeded from.
		if ($categories === []) {
			return null;
		}

		return $categories;
	}//end wooCategories()

	/**
	 * Whether a schema resolves.
	 *
	 * @param string $schema The schema slug or id.
	 *
	 * @return bool
	 */
	private function schemaExists(string $schema): bool {
		try {
			// Positional: the mapper is only known as an object with this method.
			$this->service(name: self::SCHEMA_MAPPER)->find($schema, [], false, false);
		} catch (Throwable) {
			return false;
		}

		return true;
	}//end schemaExists()

	/**
	 * The rendered objects of one register and schema, in the system context.
	 *
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return array<int, array<string, mixed>> The rows; none when they cannot be read.
	 */
	private function rows(string $register, string $schema): array {
		try {
			$found = $this->service(name: self::OBJECT_SERVICE)->findAll(
				['filters' => ['register' => $register, 'schema' => $schema], 'limit' => self::LIMIT],
				false,
				false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('Integriq catalogi: ' . $schema . ' could not be read (' . $exception::class . ').');
			return [];
		}

		if (is_array($found) === true && array_key_exists('results', $found) === true) {
			$found = $found['results'];
		}

		$rows = [];
		foreach ((array)$found as $entity) {
			if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
				$entity = $entity->jsonSerialize();
			}

			if (is_array($entity) === true) {
				$rows[] = $entity;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * One service from the container.
	 *
	 * @param string $name The class.
	 *
	 * @return object The service.
	 */
	private function service(string $name): object {
		return $this->container->get($name);
	}//end service()

}//end class
