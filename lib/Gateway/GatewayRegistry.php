<?php

/**
 * The statutory gateways this instance reaches.
 *
 * @category Registry
 * @package  OCA\Integriq\Gateway
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

namespace OCA\Integriq\Gateway;

use InvalidArgumentException;

/**
 * An entry that cannot say which law it serves is refused at registration,
 * naming the entry and the field. The catalogue filters by standard, and the
 * overview answers "where does data leave to" without anyone opening a
 * configuration file.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-its-standard-and-its-conformance-claim-req-sg-001
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-a-gateway-declares-where-its-endpoint-sits-req-sg-008
 */
class GatewayRegistry {
	/**
	 * The gateway entries this instance ships, keyed by id.
	 *
	 * @var array<string,GatewayDescriptor>
	 */
	private array $gateways = [];

	/**
	 * Constructor.
	 *
	 * @param array<int,array<string,mixed>> $entries The declared entries.
	 *
	 * @throws InvalidArgumentException When an entry cannot be registered.
	 */
	public function __construct(array $entries = []) {
		foreach ($entries as $entry) {
			$this->register(entry: $entry);
		}
	}//end __construct()

	/**
	 * Register one entry.
	 *
	 * @param array<string,mixed>|GatewayDescriptor $entry The entry.
	 *
	 * @return GatewayDescriptor The registered descriptor.
	 *
	 * @throws InvalidArgumentException When the entry cannot be registered.
	 */
	public function register(array|GatewayDescriptor $entry): GatewayDescriptor {
		$descriptor = $entry;
		if ($entry instanceof GatewayDescriptor === false) {
			$descriptor = GatewayDescriptor::fromArray($entry);
		}
		$this->gateways[$descriptor->getId()] = $descriptor;

		return $descriptor;
	}//end register()

	/**
	 * One gateway by id.
	 *
	 * @param string $id Gateway id.
	 *
	 * @return GatewayDescriptor|null The gateway, or null when nothing answers to the id.
	 */
	public function get(string $id): ?GatewayDescriptor {
		return ($this->gateways[$id] ?? null);
	}//end get()

	/**
	 * Every registered gateway, optionally narrowed to one standard.
	 *
	 * @param string|null $standard The standard to filter by.
	 *
	 * @return array<int,GatewayDescriptor> The gateways.
	 */
	public function all(?string $standard = null): array {
		$all = array_values($this->gateways);
		if ($standard === null || $standard === '') {
			return $all;
		}

		return array_values(
			array_filter($all, static fn (GatewayDescriptor $g): bool => $g->getStandard() === $standard)
		);
	}//end all()

	/**
	 * Every standard this instance has a gateway for, as the catalogue facet.
	 *
	 * @return array<int,string> Standards, in the order they were registered.
	 */
	public function standards(): array {
		$standards = [];
		foreach ($this->gateways as $gateway) {
			if (in_array($gateway->getStandard(), $standards, true) === false) {
				$standards[] = $gateway->getStandard();
			}
		}

		return $standards;
	}//end standards()

	/**
	 * The gateway overview: every gateway with its jurisdiction.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	public function overview(): array {
		return array_map(
			static function (GatewayDescriptor $gateway): array {
				$entry = $gateway->toArray();

				return [
					'id' => $entry['id'],
					'label' => $entry['label'],
					'standard' => $entry['standard'],
					'claimLevel' => $entry['claim']['level'],
					'jurisdiction' => $entry['jurisdiction'],
					'jurisdictionDeclared' => $entry['jurisdictionDeclared'],
					'transport' => $entry['transport'],
				];
			},
			array_values($this->gateways)
		);
	}//end overview()

	/**
	 * The overview as a comma-separated export.
	 *
	 * @return string The export.
	 */
	public function exportOverview(): string {
		$lines = ['id,label,standard,claimLevel,jurisdiction,transport'];
		foreach ($this->overview() as $row) {
			$lines[] = implode(
				',',
				array_map(
					static fn (string $value): string => ('"' . str_replace('"', '""', $value) . '"'),
					[
						(string)$row['id'],
						(string)$row['label'],
						(string)$row['standard'],
						(string)$row['claimLevel'],
						(string)$row['jurisdiction'],
						(string)$row['transport'],
					]
				)
			);
		}

		return implode("\n", $lines);
	}//end exportOverview()
}//end class
