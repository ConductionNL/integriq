<?php

/**
 * Contract of the SourceDestructionService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see SourceDestructionService} that other components call.
 */
interface SourceDestructionServiceInterface {
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
	public function handleZgwDestroyed(string $sourceId, string $resourceUrl): array;

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
	public function handleDestroyed(string $synchronizationId, string $originId, ?string $reference): array;

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
	public function signatureConfig(string $synchronizationId): ?array;
}
