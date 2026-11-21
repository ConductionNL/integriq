<?php

/**
 * Integriq AttributeEnvelopeFactory.
 *
 * The callback half of an attribute login (Yivi). It reads, from the
 * identity provider's answer, exactly the attributes the login asked for and
 * refuses when one is missing; then it mints the envelope a consumer gets:
 * the disclosed attributes and nothing else, a subject that is a pseudonym of
 * the disclosure, and every BSN attribute replaced by its pseudonym unless
 * the registration lets the consumer receive it.
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
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpDisclosureIncompleteException;

/**
 * Reads the requested attributes from an answer and mints their envelope.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
 */
class AttributeEnvelopeFactory {

	/**
	 * Constructor.
	 *
	 * @param SubjectPseudonymService $pseudonyms Turns a disclosed BSN into its pseudonym.
	 */
	public function __construct(
		private readonly SubjectPseudonymService $pseudonyms,
	) {

	}//end __construct()

	/**
	 * The value of every attribute the login asked for, by attribute id.
	 *
	 * Only the ids in the state are read, through the claim name the
	 * registration gives each; whatever else the answer carries is left
	 * where it is.
	 *
	 * @param IdpRegistration     $registration The organisation's registration.
	 * @param string              $requested    The attribute ids the state recorded, comma-separated.
	 * @param array<string,mixed> $assertion    The normalised assertion, with `disclosed` by claim name.
	 *
	 * @return array<string,string> The disclosed values, in the order asked.
	 *
	 * @throws IdpDisclosureIncompleteException When a requested attribute is missing from the answer.
	 * @throws IdpAssertionException When the state asked for nothing, or for an attribute the registration no longer allows.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function disclosed(IdpRegistration $registration, string $requested, array $assertion): array {
		$ids = array_values(array_filter(array_map('trim', explode(',', $requested)), static fn (string $id): bool => $id !== ''));
		if ($ids === []) {
			throw new IdpAssertionException(message: 'The login state records no attributes, so no envelope is issued.');
		}

		$allowed = $registration->attributeAllowlist();
		$answered = ($assertion['disclosed'] ?? []);
		if (is_array($answered) === false) {
			$answered = [];
		}

		$disclosed = [];
		foreach ($ids as $id) {
			if (isset($allowed[$id]) === false) {
				throw new IdpAssertionException(
					message: 'The login asked for an attribute the registration no longer allows, so no envelope is issued.'
				);
			}

			$value = ($answered[$allowed[$id]['claim']] ?? '');
			if (is_string($value) === false || trim($value) === '') {
				throw new IdpDisclosureIncompleteException();
			}

			$disclosed[$id] = trim($value);
		}

		return $disclosed;

	}//end disclosed()

	/**
	 * The envelope of one attribute login.
	 *
	 * The subject is an HMAC, under the registration's salt and scoped to the
	 * organisation, over the requested ids and their values: the same
	 * disclosure gives the same subject, and nothing more can be read from
	 * it. A BSN attribute is replaced by the pseudonym a DigiD login would
	 * get, unless the registration passes it to the consumer.
	 *
	 * @param IdpRegistration      $registration The organisation's registration.
	 * @param array<string,string> $attributes   The disclosed values, by attribute id.
	 * @param string               $salt         The registration's salt.
	 * @param string               $audience     The consumer the envelope is minted for.
	 * @param string               $trust        The trust the login carries.
	 *
	 * @return SubjectEnvelope The envelope.
	 *
	 * @throws IdpAssertionException When the salt or the organisation is unusable.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-yivi-envelope-carries-only-the-disclosed-attributes-req-yivi-003
	 */
	public function envelope(IdpRegistration $registration, array $attributes, string $salt, string $audience, string $trust): SubjectEnvelope {
		$organisation = strtolower($registration->organisation());

		return new SubjectEnvelope(
			subject: $this->pseudonyms->pseudonymFor(
				bsn: (string)json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				organisation: $organisation,
				salt: $salt
			),
			subType: SubjectEnvelope::SUBTYPE_ATTRIBUTES,
			provider: $registration->provider(),
			audience: $audience,
			organisation: $organisation,
			trust: $trust,
			attributes: $this->released(registration: $registration, attributes: $attributes, salt: $salt)
		);

	}//end envelope()

	/**
	 * The attributes as the consumer receives them: BSN attributes pseudonymised unless passed on.
	 *
	 * @param IdpRegistration      $registration The organisation's registration.
	 * @param array<string,string> $attributes   The disclosed values, by attribute id.
	 * @param string               $salt         The registration's salt.
	 *
	 * @return array<string,string>
	 *
	 * @throws IdpAssertionException When a BSN cannot be pseudonymised.
	 */
	private function released(IdpRegistration $registration, array $attributes, string $salt): array {
		if ($registration->passesBsnToConsumer() === true) {
			return $attributes;
		}

		$allowed = $registration->attributeAllowlist();
		$released = [];
		foreach ($attributes as $id => $value) {
			if (($allowed[$id]['carriesBsn'] ?? false) === true) {
				$value = $this->pseudonyms->pseudonymFor(bsn: $value, organisation: $registration->organisation(), salt: $salt);
			}

			$released[$id] = $value;
		}

		return $released;

	}//end released()

}//end class
