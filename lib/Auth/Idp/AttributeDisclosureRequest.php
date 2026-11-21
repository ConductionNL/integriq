<?php

/**
 * Integriq AttributeDisclosureRequest.
 *
 * The attributes one login asks a person to disclose, checked against the
 * attributes the organisation's registration allows. Built at the start of an
 * attribute login (Yivi): the ids come from the consuming app, and every one
 * must be on the registration's allowlist, so an app can never ask for more
 * than the organisation agreed to.
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
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use OCA\Integriq\Exception\IdpAssertionException;

/**
 * The checked list of attributes one login asks for.
 *
 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
 */
final class AttributeDisclosureRequest {

	/**
	 * The registration property that lists the attributes an organisation allows.
	 *
	 * @var string
	 */
	public const ALLOWLIST_PROPERTY = 'yiviAttributes';

	/**
	 * The most attributes one login may ask for.
	 *
	 * @var integer
	 */
	public const MAX_ATTRIBUTES = 32;

	/**
	 * The claim name per requested attribute id, in the order asked.
	 *
	 * @var array<string, string>
	 */
	private readonly array $claims;

	/**
	 * Check the attributes a start asks for against the registration's allowlist.
	 *
	 * @param IdpRegistration $registration The organisation's registration for the provider.
	 * @param string          $requested    The attribute ids, comma-separated, as the start received them.
	 *
	 * @throws IdpAssertionException When the list is empty, too long, or names an attribute the registration does not allow.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function __construct(IdpRegistration $registration, string $requested) {
		$ids = self::idsOf(requested: $requested);
		if ($ids === []) {
			throw new IdpAssertionException(message: 'The login names no attributes to disclose, so no login starts.');
		}

		if (count($ids) > self::MAX_ATTRIBUTES) {
			throw new IdpAssertionException(message: 'The login asks for too many attributes, so no login starts.');
		}

		$allowed = self::allowlist(registration: $registration);
		$claims = [];
		foreach ($ids as $id) {
			if (isset($allowed[$id]) === false) {
				throw new IdpAssertionException(
					message: 'The login asks for an attribute the organisation does not allow, so no login starts.'
				);
			}

			$claims[$id] = $allowed[$id];
		}

		$this->claims = $claims;

	}//end __construct()

	/**
	 * The requested attribute ids, as the signed state records them.
	 *
	 * @return string The ids, comma-separated, in the order asked.
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function stateValue(): string {
		return implode(',', array_keys($this->claims));

	}//end stateValue()

	/**
	 * The claim names the identity provider is asked for, one per requested attribute.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/idp-broker-yivi-route/specs/idp-broker-yivi/spec.md#requirement-a-login-asks-for-exactly-the-attributes-the-consumer-names-req-yivi-002
	 */
	public function claimNames(): array {
		return array_values(array_unique(array_values($this->claims)));

	}//end claimNames()

	/**
	 * The ids in a comma-separated list, trimmed, without blanks or repeats.
	 *
	 * @param string $requested The list.
	 *
	 * @return list<string>
	 */
	private static function idsOf(string $requested): array {
		$ids = [];
		foreach (explode(',', $requested) as $id) {
			$id = trim($id);
			if ($id !== '' && in_array($id, $ids, true) === false) {
				$ids[] = $id;
			}
		}

		return $ids;

	}//end idsOf()

	/**
	 * The claim name per attribute id the registration allows.
	 *
	 * An entry without an id or a claim name is no entry: it cannot be asked
	 * for, so it allows nothing.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return array<string, string>
	 */
	private static function allowlist(IdpRegistration $registration): array {
		$entries = ($registration->data[self::ALLOWLIST_PROPERTY] ?? []);
		if (is_array($entries) === false) {
			return [];
		}

		$allowed = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$id = self::scalarText(value: ($entry['id'] ?? null));
			$claim = self::scalarText(value: ($entry['claim'] ?? null));
			if ($id !== '' && $claim !== '') {
				$allowed[$id] = $claim;
			}
		}

		return $allowed;

	}//end allowlist()

	/**
	 * A scalar as trimmed text; '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function scalarText(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);

	}//end scalarText()

}//end class
