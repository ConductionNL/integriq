<?php

/**
 * A source's destruction notice, applied to the one object it names.
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
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Two ways in, one action. A ZGW notification with `actie: destroy` names the
 * destroyed record by its resource URL and arrives on an abonnement of one
 * source; a signed call to `POST /api/synchronizations/{id}/destroyed` names
 * one synchronization and the record's id. Either way the engine resolves the
 * one contract whose `originId` names the record and purges, or applies the
 * disappearance policy to, that object alone. Nothing here runs a full
 * synchronization, and an object without a contract is never touched.
 *
 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
 */
class SourceDestructionService {
	/**
	 * The outcome when the route names a synchronization that does not exist.
	 */
	public const OUTCOME_NO_SYNCHRONIZATION = 'no_synchronization';

	/**
	 * The signature header a source uses when its configuration names none.
	 */
	public const DEFAULT_SIGNATURE_HEADER = 'X-OpenConnector-Signature';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService        $objectService OpenRegister's object service, for synchronizations and sources.
	 * @param SynchronizationService $engine        The engine that resolves the contract and acts on the object.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly SynchronizationService $engine,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply a ZGW destroy notification to every synchronization of the source it arrived for.
	 *
	 * The contract's `originId` is either the resource URL itself or its last
	 * path segment (the uuid), depending on how the synchronization reads the
	 * source's id; both are tried, the URL first.
	 *
	 * @param string $sourceId    The source the abonnement belongs to.
	 * @param string $resourceUrl The notification's `resourceUrl`.
	 *
	 * @return list<array<string,mixed>> One outcome per synchronization that held the record.
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function handleZgwDestroyed(string $sourceId, string $resourceUrl): array {
		if ($sourceId === '' || $resourceUrl === '') {
			return [];
		}

		$originIds = [$resourceUrl];
		$segment = self::lastPathSegment(url: $resourceUrl);
		if ($segment !== '' && $segment !== $resourceUrl) {
			$originIds[] = $segment;
		}

		$outcomes = [];
		foreach ($this->synchronizationsOfSource(sourceId: $sourceId) as $synchronization) {
			$outcome = $this->engine->applySourceDestruction(
				synchronization: $synchronization,
				originIds: $originIds,
				reference: $resourceUrl
			);
			if ($outcome['outcome'] !== SynchronizationService::DESTRUCTION_NO_CONTRACT) {
				$outcomes[] = $outcome;
			}
		}

		return $outcomes;
	}//end handleZgwDestroyed()

	/**
	 * Apply a signed destroyed call to one synchronization.
	 *
	 * @param string      $synchronizationId The synchronization from the route.
	 * @param string      $originId          The destroyed record's id at the source.
	 * @param string|null $reference         The caller's reference for the destruction, if any.
	 *
	 * @return array<string,mixed> The outcome.
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function handleDestroyed(string $synchronizationId, string $originId, ?string $reference): array {
		$synchronization = $this->findObject(id: $synchronizationId, schema: 'synchronization');
		if ($synchronization === null) {
			return ['outcome' => self::OUTCOME_NO_SYNCHRONIZATION, 'synchronizationId' => $synchronizationId];
		}

		return $this->engine->applySourceDestruction(
			synchronization: $synchronization,
			originIds: [$originId],
			reference: ($reference ?? $originId)
		);
	}//end handleDestroyed()

	/**
	 * The webhook signature configuration of a synchronization's source.
	 *
	 * Null when the synchronization or its source does not exist, or the
	 * source declares no secret: the caller then refuses, it never accepts an
	 * unsigned call.
	 *
	 * @param string $synchronizationId The synchronization from the route.
	 *
	 * @return array{scheme: string, secret: string, toleranceSeconds: int, header: string}|null
	 *
	 * @spec openspec/changes/synchronisation-source-destruction-purge/specs/synchronization-engine/spec.md#requirement-a-destruction-notice-purges-one-object-without-a-full-run-req-sdp-002
	 */
	public function signatureConfig(string $synchronizationId): ?array {
		$synchronization = $this->findObject(id: $synchronizationId, schema: 'synchronization');
		$sourceId = (string)($synchronization?->getObject()['sourceId'] ?? '');
		if ($sourceId === '') {
			return null;
		}

		$source = $this->findObject(id: $sourceId, schema: 'source');
		$config = ($source?->getObject()['configuration']['webhookSignature'] ?? []);
		if (is_array($config) === false || (string)($config['secret'] ?? '') === '') {
			return null;
		}

		return [
			'scheme' => (string)($config['scheme'] ?? 'openconnector'),
			'secret' => (string)$config['secret'],
			'toleranceSeconds' => (int)($config['toleranceSeconds'] ?? WebhookSignatureService::DEFAULT_TOLERANCE_SECONDS),
			'header' => (string)($config['header'] ?? self::DEFAULT_SIGNATURE_HEADER),
		];
	}//end signatureConfig()

	/**
	 * The synchronizations that read from one source.
	 *
	 * The filter is checked again on every row: a lookup that ignored it must
	 * not reach another source's objects.
	 *
	 * @param string $sourceId The source.
	 *
	 * @return list<ObjectEntity>
	 */
	private function synchronizationsOfSource(string $sourceId): array {
		try {
			$matches = $this->objectService->findAll(
				config: ['filters' => ['register' => 'integriq', 'schema' => 'synchronization', 'sourceId' => $sourceId]]
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[integriq] destruction notice: the synchronizations of the source could not be read',
				['sourceId' => $sourceId, 'error' => $exception->getMessage()]
			);
			return [];
		}

		$rows = ($matches['results'] ?? $matches);

		return array_values(
			array_filter(
				(array)$rows,
				static fn ($row): bool => $row instanceof ObjectEntity && (string)($row->getObject()['sourceId'] ?? '') === $sourceId
			)
		);
	}//end synchronizationsOfSource()

	/**
	 * One integriq object by id, or null.
	 *
	 * @param string $id     The object's id.
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectEntity|null
	 */
	private function findObject(string $id, string $schema): ?ObjectEntity {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, register: 'integriq', schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $exception) {
			return null;
		}

		if ($object instanceof ObjectEntity) {
			return $object;
		}

		return null;
	}//end findObject()

	/**
	 * The last path segment of a URL: the uuid of a ZGW resource.
	 *
	 * @param string $url The resource URL.
	 *
	 * @return string
	 */
	private static function lastPathSegment(string $url): string {
		$path = rtrim((string)parse_url($url, PHP_URL_PATH), '/');
		$position = strrpos($path, '/');
		if ($position === false) {
			return $path;
		}

		return substr($path, $position + 1);
	}//end lastPathSegment()
}//end class
