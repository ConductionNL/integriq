<?php

/**
 * Integriq Directory Source.
 *
 * A directory connection is a Source under `source-management`, not a new kind
 * of thing. This class is the one place that turns such a Source into a
 * {@see DirectorySnapshot}: from the connection's mock fixture when the source
 * is in mock mode, and otherwise over the directory's own SCIM 2.0 read API
 * through the shared call engine, so authentication stays where every other
 * outbound call keeps it (a `credentialRef` resolved by OpenRegister's
 * credential broker) and never in this package.
 *
 * Nextcloud's `user_ldap` is not rebuilt here and is not replaced: it
 * authenticates, which this change explicitly leaves to it. What it does not do
 * is keep a Nextcloud group in step with the directory between logins, which is
 * the whole of REQ-DS-001.
 *
 * @category Directory
 * @package  OCA\Integriq\Directory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Directory;

use OCA\Integriq\Service\CallService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a directory connection and answers a snapshot of it.
 *
 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md
 */
class DirectorySource {

	/**
	 * The OpenRegister register every integriq configuration object lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The OpenRegister schema a connection is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'source';

	/**
	 * The `type` value that marks a Source as a directory connection.
	 *
	 * @var string
	 */
	public const SOURCE_TYPE = 'directory';

	/**
	 * Default page size for the SCIM read.
	 *
	 * @var integer
	 */
	private const PAGE_SIZE = 200;

	/**
	 * Hard ceiling on pages, so a directory answering `startIndex` badly cannot
	 * spin this read forever.
	 *
	 * @var integer
	 */
	private const MAX_PAGES = 500;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService OpenRegister object service, for finding connections.
	 * @param CallService $callService The shared outbound call engine (credentials via the broker).
	 * @param IL10N $l10n Translations, so a refusal reads as a sentence.
	 * @param LoggerInterface $logger Logger for secret-free diagnostics.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly CallService $callService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Every enabled directory connection, as Source objects.
	 *
	 * @return array<int,ObjectEntity> The directory connections.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function findConnections(): array {
		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SCHEMA,
					'type' => self::SOURCE_TYPE,
				],
				'limit' => 100,
			]
		);

		$rows = ($matches['results'] ?? $matches);
		$connections = [];
		foreach ($rows as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			// The filter is applied by OR, but a register that ignores an
			// unknown filter key answers everything rather than nothing, so the
			// type is checked again here before a Source is treated as a
			// directory.
			if (($row->getObject()['type'] ?? '') !== self::SOURCE_TYPE) {
				continue;
			}

			$connections[] = $row;
		}

		return $connections;

	}//end findConnections()

	/**
	 * Read one directory connection.
	 *
	 * @param ObjectEntity $connection The directory connection Source.
	 *
	 * @return DirectorySnapshot What the directory answered.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	public function read(ObjectEntity $connection): DirectorySnapshot {
		$configuration = (array)($connection->getObject()['configuration'] ?? []);

		if (($configuration['mock'] ?? false) === true) {
			return $this->readFixture(configuration: $configuration);
		}

		return $this->readScim(connection: $connection, configuration: $configuration);

	}//end read()

	/**
	 * Read the connection's mock fixture.
	 *
	 * The fixture is the same shape the SCIM read produces, so a connection can
	 * be tested from the Sources screen without a directory, and so the sync
	 * tests exercise the real mapping rather than a stub of it.
	 *
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return DirectorySnapshot The fixture as a snapshot.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	private function readFixture(array $configuration): DirectorySnapshot {
		$rows = (array)($configuration['fixture']['users'] ?? []);

		// A fixture says whether it stands for a complete read. A truncated
		// fixture is how the deletion-ratio guard is tested, so the flag is
		// read rather than assumed.
		$complete = (bool)($configuration['fixture']['complete'] ?? true);

		return $this->toSnapshot(rows: $rows, complete: $complete);

	}//end readFixture()

	/**
	 * Read the directory over its SCIM 2.0 `/Users` endpoint.
	 *
	 * @param ObjectEntity $connection The directory connection Source.
	 * @param array<string,mixed> $configuration The connection configuration.
	 *
	 * @return DirectorySnapshot What the directory answered.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	private function readScim(ObjectEntity $connection, array $configuration): DirectorySnapshot {
		$endpoint = (string)($configuration['usersEndpoint'] ?? '/Users');
		$pageSize = (int)($configuration['pageSize'] ?? self::PAGE_SIZE);
		if ($pageSize < 1) {
			$pageSize = self::PAGE_SIZE;
		}

		$rows = [];
		$startIndex = 1;
		$complete = false;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$body = $this->callPage(
				connection: $connection,
				endpoint: $endpoint,
				startIndex: $startIndex,
				pageSize: $pageSize
			);

			if ($body === null) {
				// A page that did not answer leaves the read INCOMPLETE rather
				// than short: the difference is what stops an outage from
				// emptying every group.
				return $this->toSnapshot(rows: $rows, complete: false);
			}

			$resources = (array)($body['Resources'] ?? []);
			foreach ($resources as $resource) {
				$rows[] = (array)$resource;
			}

			$total = (int)($body['totalResults'] ?? count($rows));
			$startIndex += max(1, count($resources));

			if (count($resources) === 0 || count($rows) >= $total) {
				$complete = true;
				break;
			}
		}

		return $this->toSnapshot(rows: $rows, complete: $complete);

	}//end readScim()

	/**
	 * Fetch one SCIM page, answering null when the directory did not.
	 *
	 * @param ObjectEntity $connection The directory connection Source.
	 * @param string $endpoint The SCIM users endpoint.
	 * @param integer $startIndex The 1-based SCIM start index.
	 * @param integer $pageSize The SCIM page size.
	 *
	 * @return array<string,mixed>|null The decoded page, or null when the page did not answer.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-a-directory-connection-synchronises-users-and-groups-req-ds-001
	 */
	private function callPage(
		ObjectEntity $connection,
		string $endpoint,
		int $startIndex,
		int $pageSize,
	): ?array {
		try {
			$callLog = $this->callService->call(
				source: $connection,
				endpoint: $endpoint,
				method: 'GET',
				config: ['query' => ['startIndex' => $startIndex, 'count' => $pageSize]]
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[DirectorySource] directory read failed: ' . $exception->getMessage(),
				['exception' => $exception]
			);

			return null;
		}

		$response = (array)($callLog->getObject()['response'] ?? []);
		$statusCode = (int)($response['statusCode'] ?? 0);
		if ($statusCode < 200 || $statusCode > 299) {
			$this->logger->warning(
				'[DirectorySource] directory answered status ' . $statusCode,
				['startIndex' => $startIndex]
			);

			return null;
		}

		$body = ($response['body'] ?? null);
		if (is_array($body) === true) {
			return $body;
		}

		if (is_string($body) === false) {
			return null;
		}

		$decoded = json_decode(json: $body, associative: true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;

	}//end callPage()

	/**
	 * Turn directory rows into entries, capturing the rows that cannot be read.
	 *
	 * A row without an account id is captured with its reason instead of
	 * aborting the read, per REQ-DS-006.
	 *
	 * @param array<int,array<string,mixed>> $rows The directory rows.
	 * @param boolean $complete Whether the read reached the end of the directory.
	 *
	 * @return DirectorySnapshot The snapshot.
	 *
	 * @spec openspec/changes/directory-and-group-sync/specs/directory-sync/spec.md#requirement-every-run-says-what-it-changed-req-ds-006
	 */
	private function toSnapshot(array $rows, bool $complete): DirectorySnapshot {
		$entries = [];
		$failures = [];

		foreach ($rows as $index => $row) {
			$entry = DirectoryEntry::fromArray(row: (array)$row);
			if ($entry->getUserId() === '') {
				$failures[] = [
					'index' => $index,
					'reason' => $this->l10n->t('The directory entry carries no account name, so it cannot be matched to a Nextcloud account.'),
				];
				continue;
			}

			$entries[] = $entry;
		}

		return new DirectorySnapshot(entries: $entries, complete: $complete, failures: $failures);

	}//end toSnapshot()
}//end class
