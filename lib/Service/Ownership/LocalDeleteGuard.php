<?php

/**
 * Refuses a local delete of a record an external source owns.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Ownership
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

namespace OCA\Integriq\Service\Ownership;

use InvalidArgumentException;
use OCP\IL10N;

/**
 * A delete of a source-owned record is refused, and the refusal names the
 * synchronisation that maintains it. An override is a written statement: a
 * reason, a user and a timestamp, recorded on the object and readable
 * afterwards.
 *
 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md#requirement-a-local-delete-of-a-source-owned-record-is-refused-unless-somebody-says-why-req-sor-005
 */
class LocalDeleteGuard {
	/**
	 * The key an override is recorded on the object under.
	 */
	public const OVERRIDE_KEY = 'ownershipDeleteOverride';

	/**
	 * Constructor.
	 *
	 * @param IL10N|null $l Translates the refusals, which a person reads. Without it
	 *                      (a bare construction in a script) they stay English.
	 */
	public function __construct(
		private readonly ?IL10N $l = null,
	) {

	}//end __construct()

	/**
	 * Decide whether a delete may go ahead.
	 *
	 * @param OwnershipState $ownership The record's ownership.
	 * @param string|null $reason The typed override reason, or null for no override.
	 * @param string|null $userId The user overriding.
	 *
	 * @return array<string,mixed>|null The override record to store, or null when no override was needed.
	 *
	 * @throws InvalidArgumentException When the delete is refused.
	 *
	 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md
	 */
	public function guard(OwnershipState $ownership, ?string $reason = null, ?string $userId = null): ?array {
		if ($ownership->isSourceOwned() === false) {
			return null;
		}

		if ($reason === null) {
			throw new InvalidArgumentException($this->refusalMessage(ownership: $ownership));
		}

		if (trim($reason) === '') {
			$message = 'An override of an ownership refusal requires a reason. Nothing was deleted.';
			if ($this->l !== null) {
				$message = $this->l->t('An override of an ownership refusal requires a reason. Nothing was deleted.');
			}

			throw new InvalidArgumentException($message);
		}

		return [
			'reason' => trim($reason),
			'user' => ($userId ?? ''),
			'at' => gmdate('c'),
			'synchronization' => $ownership->getSynchronizationId(),
			'mode' => $ownership->getMode(),
		];
	}//end guard()

	/**
	 * The refusal a person reads, naming the synchronisation.
	 *
	 * @param OwnershipState $ownership The record's ownership.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/records-owned-by-an-external-source/specs/source-owned-records/spec.md
	 */
	public function refusalMessage(OwnershipState $ownership): string {
		$name = ($ownership->getSynchronizationName() ?? $ownership->getSynchronizationId() ?? 'an external synchronisation');

		if ($this->l !== null) {
			return $this->l->t(
				'This record is maintained by "%s", so it cannot be deleted here. Override the refusal with a reason if it really has to go.',
				[$name]
			);
		}

		return sprintf(
			'This record is maintained by "%s", so it cannot be deleted here. Override the refusal with a reason if it really has to go.',
			$name
		);
	}//end refusalMessage()
}//end class
