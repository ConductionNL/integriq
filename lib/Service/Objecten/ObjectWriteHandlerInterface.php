<?php

/**
 * Contract of the ObjectWriteHandler.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Objecten
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

namespace OCA\Integriq\Service\Objecten;

/**
 * Public operations of {@see ObjectWriteHandler} that other components call.
 */
interface ObjectWriteHandlerInterface {
	/**
	 * `POST /api/v2/objects`.
	 *
	 * @param string               $type      The objecttype.
	 * @param array<string, mixed> $body      The submitted record.
	 * @param string               $principal The token's principal.
	 * @param string               $baseUrl   The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function create(string $type, array $body, string $principal, string $baseUrl = ''): array;

	/**
	 * `PUT /api/v2/objects/{uuid}` — replace.
	 *
	 * @param string               $type      The objecttype.
	 * @param string               $uuid      The object.
	 * @param array<string, mixed> $body      The submitted record.
	 * @param string               $principal The token's principal.
	 * @param string               $baseUrl   The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function replace(string $type, string $uuid, array $body, string $principal, string $baseUrl = ''): array;

	/**
	 * `PATCH /api/v2/objects/{uuid}` — partial update.
	 *
	 * 🔴 A PARTIAL UPDATE MERGES, and this is the one place the difference from
	 * a replace is a data-loss bug rather than a preference: sending a PATCH
	 * through the replace path drops every field the caller did not mention,
	 * and the response looks exactly like a successful update.
	 *
	 * @param string               $type      The objecttype.
	 * @param string               $uuid      The object.
	 * @param array<string, mixed> $body      The submitted fields.
	 * @param array<string, mixed> $current   The object as stored.
	 * @param string               $principal The token's principal.
	 * @param string               $baseUrl   The API base.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function update(
		string $type,
		string $uuid,
		array $body,
		array $current,
		string $principal,
		string $baseUrl = ''
	): array;

	/**
	 * `DELETE /api/v2/objects/{uuid}`.
	 *
	 * @param string $type      The objecttype.
	 * @param string $uuid      The object.
	 * @param string $principal The token's principal.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function delete(string $type, string $uuid, string $principal): array;
}
