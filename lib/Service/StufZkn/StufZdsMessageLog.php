<?php

/**
 * Integriq StUF-ZDS Message Log.
 *
 * One `stuf_message` row per ZDS case message: opened when the envelope is
 * built, moved to `retrying` while a transient failure waits for its next
 * attempt, and concluded `sent` or `failed`. The envelope is kept only while
 * a retry needs it and erased at conclusion, so a concluded row holds no
 * personal data beyond the referentienummer and the zaak identificatie.
 *
 * Also reads the StUF-ZKN source a request names, raw, so its encrypted
 * credentials survive for the transport.
 *
 * The rows are integriq's own audit records, written for whichever app asked
 * for the message and from cron, so they are written and read without the
 * acting user's RBAC (`_rbac: false`); nothing user-supplied chooses the row.
 *
 * Ported from dossiq's StufMessageHandler and StufRegisterAccess.
 *
 * @category Service
 * @package  OCA\Integriq\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-every-case-message-is-audited
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\StufZkn;

use DateTime;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Throwable;

/**
 * Audit rows and source reads for the ZDS case client.
 *
 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-every-case-message-is-audited
 */
class StufZdsMessageLog {

	/**
	 * The register.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The audit schema.
	 *
	 * @var string
	 */
	public const SCHEMA_MESSAGE = 'stuf_message';

	/**
	 * The source schema.
	 *
	 * @var string
	 */
	public const SCHEMA_SOURCE = 'source';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * Read a StUF-ZKN source raw.
	 *
	 * @param string $sourceId The source id or uuid.
	 *
	 * @return array The source object.
	 *
	 * @throws StufZknProviderException When no such source exists or it is not a stuf-zkn source.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-every-case-message-is-audited
	 */
	public function source(string $sourceId): array {
		$found = null;
		if ($sourceId !== '') {
			try {
				$found = $this->objectService->find(
					id: $sourceId,
					register: self::REGISTER,
					schema: self::SCHEMA_SOURCE,
					_rbac: false,
					_multitenancy: false,
					_render: false
				);
			} catch (Throwable $exception) {
				$found = null;
			}
		}

		if ($found === null) {
			throw new StufZknProviderException(message: 'No StUF-ZKN source "' . $sourceId . '" exists in integriq.');
		}

		$source = $found->getObject();
		if (($source['type'] ?? '') !== 'stuf-zkn') {
			throw new StufZknProviderException(message: 'Source "' . $sourceId . '" is not a stuf-zkn source.');
		}

		$source['id'] = (string)($found->getUuid() ?? $sourceId);
		return $source;
	}//end source()

	/**
	 * Open the row of an outbound case message.
	 *
	 * @param array $fields berichttype, operation, referentienummer, sourceId, correlationId, envelope, sourceApp, entiteittype, verwerkingssoort.
	 *
	 * @return array The stored row, `id` set.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-every-case-message-is-audited
	 */
	public function open(array $fields): array {
		$row = array_merge(
			[
				'direction' => 'outbound',
				'status' => 'retrying',
				'attempts' => 0,
				'syncedAt' => (new DateTime())->format('c'),
			],
			array_filter($fields, static fn ($value): bool => $value !== null && $value !== '')
		);

		return $this->save(row: $row, uuid: null);
	}//end open()

	/**
	 * Record a failed attempt that will be retried.
	 *
	 * @param array      $row        The row.
	 * @param int        $attempt    The attempt that failed (1-indexed).
	 * @param array      $transport  httpStatus and durationMs of the attempt.
	 * @param array|null $fout       The classified fout.
	 * @param int        $retryAfter Seconds until the next attempt.
	 *
	 * @return array The stored row.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-every-case-message-is-audited
	 */
	public function retrying(array $row, int $attempt, array $transport, ?array $fout, int $retryAfter): array {
		$row['status']       = 'retrying';
		$row['attempts']     = $attempt;
		$row['httpStatus']   = (int)($transport['httpStatus'] ?? 0);
		$row['durationMs']   = (int)($transport['durationMs'] ?? 0);
		$row['faultCode']    = (string)($fout['code'] ?? '');
		$row['error']        = (string)($fout['omschrijving'] ?? '');
		$row['nextAttemptAt'] = (new DateTime('@' . (time() + $retryAfter)))->format('c');
		$row['syncedAt']     = (new DateTime())->format('c');

		return $this->save(row: $row, uuid: (string)($row['id'] ?? ''));
	}//end retrying()

	/**
	 * Conclude a row `sent` or `failed`, erasing the envelope.
	 *
	 * @param array       $row                The row.
	 * @param bool        $sent               True for sent, false for failed.
	 * @param int         $attempt            The concluding attempt (1-indexed).
	 * @param array       $transport          httpStatus and durationMs.
	 * @param array|null  $fout               The fout, on failure.
	 * @param string|null $caseIdentification The zaak identificatie the case system named, if any.
	 *
	 * @return array The stored row.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-every-case-message-is-audited
	 */
	public function conclude(array $row, bool $sent, int $attempt, array $transport, ?array $fout, ?string $caseIdentification=null): array {
		$row['status']     = 'failed';
		$row['faultCode']  = (string)($fout['code'] ?? '');
		$row['error']      = trim((string)($fout['omschrijving'] ?? '') . ' ' . (string)($fout['details'] ?? ''));
		if ($sent === true) {
			$row['status']    = 'sent';
			$row['faultCode'] = '';
			$row['error']     = '';
		}

		$row['attempts']   = $attempt;
		$row['httpStatus'] = (int)($transport['httpStatus'] ?? 0);
		$row['durationMs'] = (int)($transport['durationMs'] ?? 0);
		$row['syncedAt']   = (new DateTime())->format('c');
		if ($caseIdentification !== null && $caseIdentification !== '') {
			$row['caseIdentification'] = $caseIdentification;
		}

		unset($row['envelope'], $row['nextAttemptAt']);
		foreach (['faultCode', 'error'] as $empty) {
			if ($row[$empty] === '') {
				unset($row[$empty]);
			}
		}

		return $this->save(row: $row, uuid: (string)($row['id'] ?? ''));
	}//end conclude()

	/**
	 * Record the asynchronous confirmation the case system sent for an outbound row.
	 *
	 * A bevestiging (Bv) makes the row `sent` and ends any pending retry; a
	 * foutbericht (Fo) makes it `failed` with the case system's fout.
	 *
	 * @param array       $row                The outbound row.
	 * @param string      $berichtcode        Bv01, Bv03, Fo01, Fo02 or Fo03.
	 * @param array|null  $fout               The fout, for a foutbericht.
	 * @param string|null $caseIdentification The zaak identificatie the confirmation named, if any.
	 *
	 * @return array The stored row.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function confirm(array $row, string $berichtcode, ?array $fout, ?string $caseIdentification): array {
		$row['confirmation'] = $berichtcode;
		$row['confirmedAt']  = (new DateTime())->format('c');
		$row['syncedAt']     = $row['confirmedAt'];
		$row['status']       = 'sent';
		unset($row['faultCode'], $row['error'], $row['envelope'], $row['nextAttemptAt']);
		if ($fout !== null) {
			$row['status']    = 'failed';
			$row['faultCode'] = (string)($fout['code'] ?? '');
			$row['error']     = trim((string)($fout['omschrijving'] ?? '') . ' ' . (string)($fout['details'] ?? ''));
			$row = array_filter($row, static fn ($value): bool => $value !== '');
		}

		if ($caseIdentification !== null && $caseIdentification !== '') {
			$row['caseIdentification'] = $caseIdentification;
		}

		return $this->save(row: $row, uuid: (string)($row['id'] ?? ''));
	}//end confirm()

	/**
	 * Find the outbound row a confirmation cross-references.
	 *
	 * @param string $referentienummer The outbound message's referentienummer.
	 *
	 * @return array|null The row, `id` set, or null.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
	 */
	public function findOutbound(string $referentienummer): ?array {
		if ($referentienummer === '') {
			return null;
		}

		try {
			$matches = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER,
						'schema' => self::SCHEMA_MESSAGE,
						'direction' => 'outbound',
						'referentienummer' => $referentienummer,
					],
					'limit' => 1,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			return null;
		}

		$results = ($matches['results'] ?? $matches);
		$found   = null;
		if (is_array($results) === true) {
			$found = ($results[0] ?? null);
		}

		if ($found === null) {
			return null;
		}

		$row       = $found->getObject();
		$row['id'] = (string)$found->getUuid();
		return $row;
	}//end findOutbound()

	/**
	 * Read one row.
	 *
	 * @param string $messageId The row uuid.
	 *
	 * @return array|null The row, `id` set, or null.
	 *
	 * @spec openspec/changes/stuf-zds-case-client/specs/stuf-zds-case-client/spec.md#requirement-every-case-message-is-audited
	 */
	public function find(string $messageId): ?array {
		try {
			$found = $this->objectService->find(
				id: $messageId,
				register: self::REGISTER,
				schema: self::SCHEMA_MESSAGE,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);
		} catch (Throwable $exception) {
			return null;
		}

		if ($found === null) {
			return null;
		}

		$row       = $found->getObject();
		$row['id'] = (string)($found->getUuid() ?? $messageId);
		return $row;
	}//end find()

	/**
	 * Save a row and hand it back with its id.
	 *
	 * @param array       $row  The row.
	 * @param string|null $uuid The uuid, null for a new row.
	 *
	 * @return array The row.
	 */
	private function save(array $row, ?string $uuid): array {
		unset($row['id']);
		if ($uuid === '') {
			$uuid = null;
		}

		$saved     = $this->objectService->saveObject(
			object: $row,
			register: self::REGISTER,
			schema: self::SCHEMA_MESSAGE,
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);
		$row['id'] = (string)($saved->getUuid() ?? $uuid ?? '');
		return $row;
	}//end save()
}//end class
