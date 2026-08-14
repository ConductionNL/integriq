<?php

/**
 * OpenConnector Log KISS Klantinteracties Provider.
 *
 * Sandbox/mock binding for {@see KlantinteractiesProviderInterface}: performs
 * no real network call, answers `listKlantcontacten()` with an empty page
 * (nothing new to pull), and returns synthetic `MOCK-KISS-<n>` ids from
 * `createKlantcontact()`/`linkOnderwerpobject()`. It MUST NOT read any
 * secret. It is the default for dev/CI and mirrors the LogSmsProvider /
 * LogPeppolAccessPointProvider sandbox convention.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Kiss;

/**
 * Sandbox KISS provider: empty pulls, synthetic created/linked ids.
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */
class LogKlantinteractiesProvider implements KlantinteractiesProviderInterface {

	/**
	 * Per-process counter for synthetic klantcontact/onderwerpobject ids (`MOCK-KISS-<n>`).
	 *
	 * A per-process, in-memory counter is sufficient for a sandbox binding —
	 * ids only need to be locally unique for the duration of one request/job
	 * run (mirrors LogPeppolAccessPointProvider::$counter).
	 *
	 * @var integer
	 */
	private static int $counter = 0;

	/**
	 * {@inheritDoc}
	 *
	 * @return string The stable `log` provider identifier.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function getProviderId(): string {
		return 'log';
	}//end getProviderId()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed> An empty schema — the log provider needs no configuration.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function getConfigSchema(): array {
		return ['type' => 'object', 'properties' => []];
	}//end getConfigSchema()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused — the log provider needs no configuration.
	 * @param string|null $since Unused.
	 * @param integer $pageSize Unused.
	 *
	 * @return array{items: array<int, array<string, mixed>>, nextCursor: string|null} Always an empty page.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md#scenario-the-log-provider-pulls-nothing-without-a-network-call-or-secret
	 */
	public function listCustomerContacts(array $sourceConfiguration, ?string $since, int $pageSize): array {
		return ['items' => [], 'nextCursor' => null];
	}//end listCustomerContacts()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused — the log provider needs no configuration.
	 * @param array $payload Unused.
	 *
	 * @return string The synthetic `MOCK-KISS-<n>` klantcontact id.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function createCustomerContact(array $sourceConfiguration, array $payload): string {
		self::$counter++;
		return 'MOCK-KISS-' . self::$counter;
	}//end createCustomerContact()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $sourceConfiguration Unused — the log provider needs no configuration.
	 * @param string $customerContactId Unused.
	 * @param string $caseReference Unused.
	 * @param string $caseObjectType Unused.
	 *
	 * @return string The synthetic `MOCK-KISS-<n>` onderwerpobject id.
	 *
	 * @spec openspec/specs/kiss-kcc-bridge/spec.md
	 */
	public function linkOnderwerpobject(
		array $sourceConfiguration,
		string $customerContactId,
		string $caseReference,
		string $caseObjectType,
	): string {
		self::$counter++;
		return 'MOCK-KISS-' . self::$counter;
	}//end linkOnderwerpobject()
}//end class
