<?php

/**
 * Contract of the ConnectionStore.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see ConnectionStore} that other components call.
 */
interface ConnectionStoreInterface {
	/**
	 * The register every connection row lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * Read connection rows, optionally for one app.
	 *
	 * @param string|null $app The declaring app, or null for every row.
	 *
	 * @return array<int,array{uuid:string,data:array<string,mixed>}>
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
	 */
	public function findRows(?string $app = null): array;

	/**
	 * Read one connection row by uuid.
	 *
	 * @param string $uuid The row uuid.
	 *
	 * @return array{uuid:string,data:array<string,mixed>}|null
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function findRow(string $uuid): ?array;

	/**
	 * Create or replace a row.
	 *
	 * OpenRegister saves with PUT semantics, so an absent property is stored as
	 * null. Null values are therefore left out rather than sent, which keeps
	 * `format: uuid` and `format: date-time` validation from refusing them.
	 *
	 * @param array<string,mixed> $data The row data.
	 * @param string|null $uuid The uuid to replace, or null to create.
	 *
	 * @return string The saved row's uuid.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
	 */
	public function save(array $data, ?string $uuid = null): string;

	/**
	 * Delete a row.
	 *
	 * @param string $uuid The row uuid.
	 *
	 * @return void
	 *
	 * @spec exclude Backend-only persistence seam for the declaration sync (REQ-CONN-002).
	 *   No controller or frontend reaches it, so it is not the ADR-022 pass-through gate 17 looks for.
	 */
	public function delete(string $uuid): void;

	/**
	 * Read a source by uuid.
	 *
	 * @param string $uuid The source uuid.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-health-job-probes-linked-sources-every-hour-req-conn-005
	 */
	public function findSource(string $uuid): ?ObjectEntity;

	/**
	 * Read a source by its slug.
	 *
	 * @param string $slug The source slug.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function findSourceBySlug(string $slug): ?ObjectEntity;

	/**
	 * Re-read a located source raw, so its write-only credentials survive.
	 *
	 * A rendered read strips `configuration.authentication.mtls` and its
	 * siblings for everyone, admins included (99-source-nested-auth-writeonly).
	 * The Berichtenbox binding needs the encrypted certificate to present it, so
	 * it re-reads the one source it already found, by uuid, unrendered. This is
	 * a read of integriq's own configuration from a background or event context
	 * with no user, the same read `CallService::resolveSourceForDispatch()`
	 * makes, so RBAC is off for the read only. Nothing is written here.
	 *
	 * @param ObjectEntity $source The source as a rendered read returned it.
	 *
	 * @return ObjectEntity The raw source, or the one passed in when the re-read fails.
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
	 */
	public function readSourceRaw(ObjectEntity $source): ObjectEntity;

	/**
	 * Create a source.
	 *
	 * @param array<string,mixed> $payload The source data.
	 *
	 * @return ObjectEntity The created source.
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
	 */
	public function createSource(array $payload): ObjectEntity;

	/**
	 * Keep only the D3 properties that hold a value.
	 *
	 * @param array<string,mixed> $data The row data.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
	 */
	public function payload(array $data): array;
}
