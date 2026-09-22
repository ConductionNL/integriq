<?php

/**
 * Integriq SubjectPseudonymService.
 *
 * Turns a citizen identity into a pseudonym at the broker edge, before
 * anything else in the stack sees it. Two routes: the broker's polymorphic
 * pseudonym when that is contracted, and a salted HMAC computed in memory
 * during one callback when it is not.
 *
 * Nothing here logs, stores or returns a BSN. The parameter is the only place
 * it exists, and it goes out of scope with the call.
 *
 * @category Auth
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;

/**
 * Pseudonymises a citizen identity at the broker edge.
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-bsn-pseudonymisation-at-the-broker-edge
 */
class SubjectPseudonymService {

	/**
	 * The shortest salt this service will compute under.
	 *
	 * A short salt makes the whole pseudonym space brute-forceable: a BSN is
	 * nine digits, so an attacker holding the salt and one pseudonym recovers
	 * the BSN in under a billion hashes.
	 *
	 * @var integer
	 */
	public const MINIMUM_SALT_BYTES = 32;

	/**
	 * The subject type a DigiD pseudonym carries.
	 *
	 * @var string
	 */
	public const SUBTYPE_BSN_PSEUDONYM = 'bsn-pseudonym';

	/**
	 * Use the pseudonym the broker already decrypted.
	 *
	 * The preferred route. Under a polymorphie contract the BSN never
	 * materialises in this stack at all, so there is nothing here to leak.
	 *
	 * @param string $polymorphicPseudonym The per-service-provider pseudonym the broker delivered.
	 *
	 * @return string The pseudonym.
	 *
	 * @throws IdpAssertionException When the broker delivered an empty pseudonym.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-bsn-pseudonymisation-at-the-broker-edge
	 */
	public function fromPolymorphic(string $polymorphicPseudonym): string {
		$pseudonym = trim($polymorphicPseudonym);
		if ($pseudonym === '') {
			// An empty pseudonym under a polymorphie contract is a broken
			// decryption, and treating it as "no subject" would let the
			// caller mint an envelope for nobody.
			throw new IdpAssertionException(
				message: 'The broker delivered an empty polymorphic pseudonym, so no envelope is issued.'
			);
		}

		return $pseudonym;

	}//end fromPolymorphic()

	/**
	 * Compute the salted pseudonym for one organisation.
	 *
	 * The organisation is part of the signed message, not only part of the
	 * key. An operator who reuses one salt across two organisations would
	 * otherwise get the same pseudonym in both, which is exactly the
	 * cross-organisation linkability the salt exists to prevent. Binding the
	 * organisation into the message makes that impossible to misconfigure.
	 *
	 * @param string $bsn The citizen identity. Never logged, never stored, never returned.
	 * @param string $organisation The organisation the pseudonym is scoped to.
	 * @param string $salt The per-organisation salt from the encrypted credential store.
	 *
	 * @return string The pseudonym, as lowercase hex.
	 *
	 * @throws IdpAssertionException When the identity, the organisation or the salt is unusable.
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-bsn-pseudonymisation-at-the-broker-edge
	 */
	public function pseudonymFor(string $bsn, string $organisation, string $salt): string {
		$subject = trim($bsn);
		$scope = strtolower(trim($organisation));

		if ($subject === '' || $scope === '') {
			throw new IdpAssertionException(
				message: 'A pseudonym needs both a subject and an organisation, so no envelope is issued.'
			);
		}

		if (strlen($salt) < self::MINIMUM_SALT_BYTES) {
			// Fail-closed rather than computing under a weak salt: a
			// reversible pseudonym is worse than no login, because it looks
			// exactly like a safe one.
			throw new IdpAssertionException(
				message: 'The organisation salt is shorter than '
				. (string)self::MINIMUM_SALT_BYTES . ' bytes, so no envelope is issued.'
			);
		}

		return hash_hmac('sha256', $scope . "\0" . $subject, $salt);

	}//end pseudonymFor()

}//end class
