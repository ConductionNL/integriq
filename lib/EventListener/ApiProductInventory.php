<?php

/**
 * Integriq ApiProductInventory.
 *
 * The API inventory lists integriq's own API products next to the APIs the
 * discovery synchronizations found on other gateways (design D3: one page,
 * `api_product` and `external_api` together). Each product version is kept
 * as one `external_api` row on the gateway `integriq`: written when the
 * product is saved, marked no longer served when it is retired or deleted.
 * So the inventory stays one schema, with its gateway filter, its columns and
 * its row action, and a product shows the same fields a discovered API does.
 *
 * @category Service
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IURLGenerator;

/**
 * Keeps one inventory row per integriq API product.
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
 */
class ApiProductInventory {

	/**
	 * The gateway and vendor an integriq product is listed under.
	 *
	 * @var string
	 */
	public const GATEWAY = 'integriq';

	/**
	 * The integriq register.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The inventory schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'external_api';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService Reads and writes the inventory rows.
	 * @param IURLGenerator   $urlGenerator  Builds the gateway and description addresses.
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly IURLGenerator $urlGenerator,
	) {

	}//end __construct()

	/**
	 * Write a product's inventory row: listed when it is served, no longer seen once it is retired.
	 *
	 * @param ObjectEntity $product The saved `api_product` object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function mirror(ObjectEntity $product): void {
		$data = $product->getObject();
		$ended = null;
		if (($data['status'] ?? 'active') === 'retired') {
			$ended = $this->now();
		}

		$this->write(product: $product, ended: $ended);

	}//end mirror()

	/**
	 * Mark a deleted product's inventory row as no longer seen; the row itself stays, as history.
	 *
	 * @param ObjectEntity $product The deleted `api_product` object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function retire(ObjectEntity $product): void {
		if ($this->rowOf(productUuid: $product->getUuid()) === null) {
			return;
		}

		$this->write(product: $product, ended: $this->now());

	}//end retire()

	/**
	 * The inventory row a product version is listed with.
	 *
	 * @param ObjectEntity $product The product.
	 * @param string|null  $ended   When it stopped being served, or null while it is.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001
	 */
	public function rowFor(ObjectEntity $product, ?string $ended): array {
		$data = $product->getObject();
		$uuid = $product->getUuid();

		return [
			'gateway' => self::GATEWAY,
			'vendor' => self::GATEWAY,
			'name' => (string)($data['name'] ?? ($data['productSlug'] ?? $uuid)),
			'version' => (string)($data['version'] ?? ''),
			'baseUrl' => $this->urlGenerator->getAbsoluteURL('/index.php/apps/integriq/api/endpoint'),
			'gatewayApiId' => $uuid,
			'gatewayUrl' => $this->urlGenerator->getAbsoluteURL('/index.php/apps/integriq/api/products/'.rawurlencode($uuid).'/openapi.json'),
			'lastSeen' => $this->now(),
			'sourceEndedAt' => $ended,
		];

	}//end rowFor()

	/**
	 * Save a product's row, over the one it already has.
	 *
	 * @param ObjectEntity $product The product.
	 * @param string|null  $ended   When it stopped being served, or null.
	 *
	 * @return void
	 */
	private function write(ObjectEntity $product, ?string $ended): void {
		$row = $this->rowFor(product: $product, ended: $ended);
		$existing = $this->rowOf(productUuid: $product->getUuid());
		if ($existing !== null) {
			// A row that is no longer seen keeps the moment it ended.
			$previous = $existing->getObject();
			if ($ended !== null && empty($previous['sourceEndedAt']) === false) {
				$row['sourceEndedAt'] = $previous['sourceEndedAt'];
			}

			$row = array_merge($previous, $row);
		}

		$this->objectService->saveObject(
			object: $row,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA),
				uuid: $existing?->getUuid()
			)
		);

	}//end write()

	/**
	 * The inventory row of a product, or null.
	 *
	 * @param string $productUuid The product's uuid.
	 *
	 * @return ObjectEntity|null
	 */
	private function rowOf(string $productUuid): ?ObjectEntity {
		$found = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA,
					'gateway' => self::GATEWAY,
					'gatewayApiId' => $productUuid,
				],
				'limit' => 2,
			]
		);

		foreach ((array)($found['results'] ?? $found) as $candidate) {
			if ($candidate instanceof ObjectEntity
				&& ($candidate->getObject()['gateway'] ?? null) === self::GATEWAY
				&& ($candidate->getObject()['gatewayApiId'] ?? null) === $productUuid
			) {
				return $candidate;
			}
		}

		return null;

	}//end rowOf()

	/**
	 * Now, as the inventory writes its times.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

	}//end now()

}//end class
