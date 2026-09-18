<?php

/**
 * The Objecten API write path: create, update, replace, delete — and announce.
 *
 * 🔴 A WRITE THAT CANNOT ANNOUNCE IS REFUSED, NOT PERFORMED. The requirement
 * pairs the two in one sentence: a write lands in OpenRegister AND announces on
 * the `objecten` kanaal. Those are not two features, they are one contract —
 * the other systems in a municipal landscape learn that an object changed by
 * hearing about it, so an object that changed without an announcement is an
 * object that, as far as the landscape is concerned, did not change. Shipping
 * the write half alone would look like progress and produce exactly that
 * divergence, silently, one object at a time.
 *
 * So this refuses with 503 when no announcer is wired. That is a loud,
 * temporary refusal an operator can read, and it is strictly better than a
 * quiet permanent inconsistency.
 *
 * 🔑 THE WRITE GOES THROUGH OPENREGISTER'S OBJECT SERVICE, never around it, so
 * schema validation, the audit trail, versioning, RBAC and multitenancy apply
 * unchanged. The facade's job is the shape and the token; it is not a second
 * place where an object may be written.
 *
 * 🔑 AND THE AUDIT ENTRY NAMES THE TOKEN'S PRINCIPAL. A write attributed to an
 * anonymous caller is one nobody can ask about afterwards, which is the whole
 * value of an audit trail on an interoperability surface.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
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
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

use Throwable;

/**
 * Serves the Objecten API write routes.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjectWriteHandler {

	/**
	 * The kanaal a change is announced on.
	 *
	 * @var string
	 */
	public const KANAAL = 'objecten';

	/**
	 * Constructor.
	 *
	 * @param ObjecttypeRegistry     $objecttypes The declared mappings.
	 * @param ObjectRecordTranslator $translator  The record shape.
	 * @param callable|null          $objectWrite Writes through OpenRegister's object service.
	 * @param callable|null          $objectDelete Deletes through it.
	 * @param callable|null          $announce    Publishes on the kanaal.
	 */
	public function __construct(
		private readonly ObjecttypeRegistry $objecttypes,
		private readonly ObjectRecordTranslator $translator,
		private $objectWrite = null,
		private $objectDelete = null,
		private $announce = null,
	) {
	}//end __construct()

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
	public function create(string $type, array $body, string $principal, string $baseUrl = ''): array {
		return $this->write(type: $type, uuid: null, body: $body, principal: $principal, baseUrl: $baseUrl, action: 'create');
	}//end create()

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
	public function replace(string $type, string $uuid, array $body, string $principal, string $baseUrl = ''): array {
		return $this->write(type: $type, uuid: $uuid, body: $body, principal: $principal, baseUrl: $baseUrl, action: 'update');
	}//end replace()

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
	): array {
		$merged = array_merge(
			$this->translator->dataOf(object: $current),
			(array)($body['record']['data'] ?? [])
		);

		return $this->write(
			type: $type,
			uuid: $uuid,
			body: ['record' => ['data' => $merged, 'geometry' => ($body['record']['geometry'] ?? ($current['geometry'] ?? null))]],
			principal: $principal,
			baseUrl: $baseUrl,
			action: 'update'
		);
	}//end update()

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
	public function delete(string $type, string $uuid, string $principal): array {
		$declaration = $this->objecttypes->find(uuid: $type);
		if ($declaration === null) {
			return $this->problem(status: 404, title: 'Not found', detail: sprintf('No objecttype "%s" is published here.', $type));
		}

		$unannounceable = $this->refuseWhenNoAnnouncer();
		if ($unannounceable !== null) {
			return $unannounceable;
		}

		if ($this->objectDelete === null) {
			return $this->problem(status: 503, title: 'Unavailable', detail: 'No write path is wired.');
		}

		try {
			($this->objectDelete)((string)$declaration['register'], (string)$declaration['schema'], $uuid, $principal);
		} catch (Throwable $e) {
			return $this->problem(status: 422, title: 'Refused', detail: $e->getMessage());
		}

		$this->publish(type: $type, uuid: $uuid, action: 'destroy');

		return ['status' => 204, 'body' => []];
	}//end delete()

	/**
	 * The shared write path.
	 *
	 * @param string               $type      The objecttype.
	 * @param string|null          $uuid      The object, null on a create.
	 * @param array<string, mixed> $body      The submitted record.
	 * @param string               $principal The token's principal.
	 * @param string               $baseUrl   The API base.
	 * @param string               $action    The announced action.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Every branch here is a refusal with its own
	 * status and its own sentence: no objecttype, no principal, no write path, no announcer, a
	 * schema that rejected the record. Folding them into fewer branches would fold the reasons
	 * into fewer messages, and the message is what the caller acts on.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same branches, counted the other way.
	 */
	private function write(
		string $type,
		?string $uuid,
		array $body,
		string $principal,
		string $baseUrl,
		string $action
	): array {
		$declaration = $this->objecttypes->find(uuid: $type);
		if ($declaration === null) {
			return $this->problem(status: 404, title: 'Not found', detail: sprintf('No objecttype "%s" is published here.', $type));
		}

		if (trim($principal) === '') {
			// Without a principal the audit entry names nobody, which is the
			// thing the requirement's third scenario exists to prevent.
			return $this->problem(
				status: 403,
				title: 'Refused',
				detail: 'This token names no principal, so a write could not be attributed to anyone.'
			);
		}

		$unannounceable = $this->refuseWhenNoAnnouncer();
		if ($unannounceable !== null) {
			return $unannounceable;
		}

		if ($this->objectWrite === null) {
			return $this->problem(status: 503, title: 'Unavailable', detail: 'No write path is wired.');
		}

		$data = (array)($body['record']['data'] ?? []);
		if ($data === []) {
			return $this->problem(status: 400, title: 'Bad request', detail: 'A write needs "record.data".');
		}

		$geometry = ($body['record']['geometry'] ?? null);
		if ($geometry !== null) {
			$data['geometry'] = $geometry;
		}

		try {
			// Through the object service, so the SCHEMA validates it. A
			// validation refusal here is the schema's answer, passed through
			// rather than reworded: the consumer needs to know which field.
			$stored = ($this->objectWrite)(
				(string)$declaration['register'],
				(string)$declaration['schema'],
				$uuid,
				$data,
				$principal
			);
		} catch (Throwable $e) {
			return $this->problem(status: 400, title: 'Validation refused', detail: $e->getMessage());
		}

		$written = $data;
		if (is_array($stored) === true) {
			$written = $stored;
		}

		$rendered = $this->translator->toRecord(
			object: $written,
			objecttype: $type,
			baseUrl: $baseUrl
		);

		$this->publish(type: $type, uuid: (string)$rendered['uuid'], action: $action);

		$status = 200;
		if ($uuid === null) {
			$status = 201;
		}

		return ['status' => $status, 'body' => $rendered];
	}//end write()

	/**
	 * Refuse when nothing can announce the change.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The refusal.
	 */
	private function refuseWhenNoAnnouncer(): ?array {
		if ($this->announce !== null) {
			return null;
		}

		return $this->problem(
			status: 503,
			title: 'Unavailable',
			detail: 'Writes are refused while the objecten kanaal is not wired: a change nobody is told about is a '
				. 'change the rest of the landscape does not have.'
		);
	}//end refuseWhenNoAnnouncer()

	/**
	 * Announce one change.
	 *
	 * @param string $type   The objecttype.
	 * @param string $uuid   The object.
	 * @param string $action The action.
	 *
	 * @return void
	 */
	private function publish(string $type, string $uuid, string $action): void {
		if ($this->announce === null) {
			return;
		}

		try {
			($this->announce)(
				[
					'kanaal' => self::KANAAL,
					'hoofdObject' => $uuid,
					'resource' => 'object',
					'resourceUrl' => $uuid,
					'actie' => $action,
					'aanmaakdatum' => date(DATE_ATOM),
					'kenmerken' => ['objectType' => $type],
				]
			);
		} catch (Throwable $e) {
			// The object is already written by now. Failing here would report
			// a failed write for a change that happened — the announcement is
			// what is missing, and the operator learns that from the publisher's
			// own retry path rather than from a 500 on a successful write.
			return;
		}
	}//end publish()

	/**
	 * A problem response in the standard's shape.
	 *
	 * @param int    $status The status.
	 * @param string $title  The title.
	 * @param string $detail The detail.
	 *
	 * @return array{status: int, body: array<string, mixed>} The response.
	 */
	private function problem(int $status, string $title, string $detail): array {
		return [
			'status' => $status,
			'body' => ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => $detail],
		];
	}//end problem()
}//end class
