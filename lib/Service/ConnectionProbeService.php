<?php

/**
 * Integriq ConnectionProbeService.
 *
 * Tests the source linked to a connection and records the outcome as the row's
 * `lastProbe`, then resolves the row. Used by the hourly health job (umbrella
 * design D7) and straight after an admin links a source (D9).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\ConnectionLinkException;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;

/**
 * Probes linked sources and links new ones.
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
 */
class ConnectionProbeService {

	/**
	 * The most probes one health job run makes (D7).
	 *
	 * @var int
	 */
	public const PROBE_LIMIT = 25;

	/**
	 * The longest message stored on a probe.
	 *
	 * @var int
	 */
	private const MESSAGE_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ConnectionStore $store Reads and writes rows and sources.
	 * @param ConnectionRegistryService $registry Resolves a row.
	 * @param ConnectionStatusResolver $resolver The clock the resolver uses.
	 * @param SourceTestService $sourceTest The shared source test call.
	 * @param CatalogRegistryService $catalog Finds a source template's seed payload.
	 * @param LoggerInterface $logger Logs a probe that could not be written.
	 */
	public function __construct(
		private readonly ConnectionStore $store,
		private readonly ConnectionRegistryService $registry,
		private readonly ConnectionStatusResolver $resolver,
		private readonly SourceTestService $sourceTest,
		private readonly CatalogRegistryService $catalog,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Probe the linked connections whose last probe is oldest.
	 *
	 * @param int $limit The most probes to make.
	 *
	 * @return int The number of rows probed.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-at-most-25-probes-per-run
	 */
	public function probeDue(int $limit = self::PROBE_LIMIT): int {
		$linked = array_values(
			array_filter(
				$this->store->findRows(),
				static fn (array $row): bool => (string)($row['data']['source'] ?? '') !== ''
			)
		);

		usort(
			$linked,
			fn (array $left, array $right): int => $this->probedAt(row: $left) <=> $this->probedAt(row: $right)
		);

		$probed = 0;
		foreach (array_slice($linked, 0, $limit) as $row) {
			try {
				$this->probe(row: $row);
				$probed++;
			} catch (\Throwable $e) {
				$this->logger->error(
					'Integriq could not record a probe for connection {uuid}: {reason}',
					['app' => 'integriq', 'uuid' => $row['uuid'], 'reason' => $e->getMessage(), 'exception' => $e]
				);
			}
		}

		return $probed;
	}//end probeDue()

	/**
	 * Probe one row's source, write `lastProbe`, resolve and save.
	 *
	 * An open circuit breaker is recorded as an error without a call.
	 *
	 * @param array{uuid:string,data:array<string,mixed>} $row The stored row.
	 *
	 * @return array{uuid:string,data:array<string,mixed>} The row as saved.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-an-open-breaker-is-not-called
	 */
	public function probe(array $row): array {
		$data = $row['data'];
		$data['lastProbe'] = $this->testSource(sourceId: (string)($data['source'] ?? ''));
		$data = $this->registry->resolveRow(data: $data);
		$this->store->save(data: $data, uuid: $row['uuid']);

		return ['uuid' => $row['uuid'], 'data' => $data];
	}//end probe()

	/**
	 * Link an existing source to a connection that has none, then probe it.
	 *
	 * @param string $connectionId The connection row uuid.
	 * @param string $sourceId The source uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>} The row as saved.
	 *
	 * @throws ConnectionLinkException When the row or source is missing, or the row already has a source.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#scenario-linking-a-source-probes-it-straight-away
	 */
	public function linkSource(string $connectionId, string $sourceId): array {
		$row = $this->unlinkedRow(connectionId: $connectionId);
		if ($this->store->findSource(uuid: $sourceId) === null) {
			throw new ConnectionLinkException(reason: ConnectionLinkException::SOURCE_NOT_FOUND);
		}

		$row['data']['source'] = $sourceId;
		return $this->probe(row: $row);
	}//end linkSource()

	/**
	 * Link a source made from the connection's `sourceTemplate`, then probe it.
	 *
	 * A source that already carries the template's slug is reused rather than
	 * copied. Otherwise the seed payload the catalog uses is saved as a new,
	 * enabled source.
	 *
	 * @param string $connectionId The connection row uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>} The row as saved.
	 *
	 * @throws ConnectionLinkException When the row is missing or linked, or the template is absent.
	 *
	 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function linkTemplate(string $connectionId): array {
		$row = $this->unlinkedRow(connectionId: $connectionId);
		$slug = (string)($row['data']['declaration']['sourceTemplate'] ?? '');
		if ($slug === '') {
			throw new ConnectionLinkException(reason: ConnectionLinkException::NO_TEMPLATE);
		}

		$source = $this->store->findSourceBySlug(slug: $slug) ?? $this->createFromTemplate(slug: $slug);
		$row['data']['source'] = (string)$source->getUuid();

		return $this->probe(row: $row);
	}//end linkTemplate()

	/**
	 * Create a source from a seeded template.
	 *
	 * @param string $slug The template slug.
	 *
	 * @return ObjectEntity The created source.
	 *
	 * @throws ConnectionLinkException When no seed exists for the slug.
	 */
	private function createFromTemplate(string $slug): ObjectEntity {
		$payload = $this->catalog->findSeedSourcePayload(slug: $slug);
		if ($payload === null) {
			throw new ConnectionLinkException(reason: ConnectionLinkException::TEMPLATE_NOT_FOUND);
		}

		$payload['isEnabled'] = true;
		return $this->store->createSource(payload: $payload);
	}//end createFromTemplate()

	/**
	 * Read a connection row that has no source yet.
	 *
	 * @param string $connectionId The connection row uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>}
	 *
	 * @throws ConnectionLinkException When the row is missing or already linked.
	 */
	private function unlinkedRow(string $connectionId): array {
		$row = $this->store->findRow(uuid: $connectionId);
		if ($row === null) {
			throw new ConnectionLinkException(reason: ConnectionLinkException::CONNECTION_NOT_FOUND);
		}

		if ((string)($row['data']['source'] ?? '') !== '') {
			throw new ConnectionLinkException(reason: ConnectionLinkException::ALREADY_LINKED);
		}

		return $row;
	}//end unlinkedRow()

	/**
	 * Test a source and describe the outcome as a probe.
	 *
	 * @param string $sourceId The source uuid.
	 *
	 * @return array{status:string,message:string,at:string}
	 */
	private function testSource(string $sourceId): array {
		$source = $this->store->findSource(uuid: $sourceId);
		if ($source === null) {
			return $this->probeResult(status: 'error', message: 'The linked source no longer exists.');
		}

		$sourceData = $source->getObject();
		if (($sourceData['circuitBreakerState'] ?? 'closed') === 'open') {
			$failures = (int)($sourceData['circuitBreakerFailureCount'] ?? 0);
			return $this->probeResult(
				status: 'error',
				message: 'The circuit breaker is open after ' . $failures . ' failures.'
			);
		}

		return $this->describe(test: $this->sourceTest->run(source: $source));
	}//end testSource()

	/**
	 * Turn a source test outcome into a probe.
	 *
	 * @param array{outcome:string,result:?array,statusCode:?int,statusMessage:string,error:string} $test The test outcome.
	 *
	 * @return array{status:string,message:string,at:string}
	 */
	private function describe(array $test): array {
		$code = $test['statusCode'];
		$reason = trim($code . ' ' . $test['statusMessage']);

		if ($test['outcome'] === SourceTestService::OUTCOME_FAILED) {
			return $this->probeResult(status: 'error', message: 'The call failed: ' . $test['error']);
		}

		if ($test['outcome'] === SourceTestService::OUTCOME_NO_RESPONSE) {
			return $this->probeResult(status: 'error', message: 'The call returned no response: HTTP ' . $reason . '.');
		}

		if ($code !== null && $code < 400) {
			return $this->probeResult(status: 'ok', message: 'The source answered with HTTP ' . $code . '.');
		}

		return $this->probeResult(status: 'error', message: 'The source answered with HTTP ' . $reason . '.');
	}//end describe()

	/**
	 * Build a probe record stamped now.
	 *
	 * @param string $status ok or error.
	 * @param string $message The short reason.
	 *
	 * @return array{status:string,message:string,at:string}
	 */
	private function probeResult(string $status, string $message): array {
		return [
			'status' => $status,
			'message' => mb_substr($message, 0, self::MESSAGE_LIMIT),
			'at' => $this->resolver->now(),
		];
	}//end probeResult()

	/**
	 * The time of a row's last probe, 0 when it was never probed.
	 *
	 * @param array{uuid:string,data:array<string,mixed>} $row The stored row.
	 *
	 * @return int
	 */
	private function probedAt(array $row): int {
		$probedAt = $row['data']['lastProbe']['at'] ?? null;
		if (is_string($probedAt) === false || $probedAt === '') {
			return 0;
		}

		return (int)strtotime($probedAt);
	}//end probedAt()
}//end class
