<?php

/**
 * Integriq IdpRegistrationTester.
 *
 * The "Test" action of Beheer > Authenticatie (portal-idp-broker-config
 * REQ-IDPC-008): whether the identity provider's metadata (SAML) or discovery
 * document (OIDC) can be read, and whether each credential the registration
 * needs resolves. Each check reports passed or failed by name; nothing secret
 * is returned and no login starts.
 *
 * @category Service
 * @package  OCA\Integriq\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use DOMDocument;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Runs the checks of one registration.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
 */
class IdpRegistrationTester {

	/**
	 * The HTTP timeout toward the identity provider, in seconds.
	 *
	 * @var integer
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * Constructor.
	 *
	 * @param IClientService $http    Reaches the identity provider.
	 * @param IdpSecretSeam  $secrets Reads each credential, failing closed.
	 */
	public function __construct(
		private readonly IClientService $http,
		private readonly IdpSecretSeam $secrets,
	) {

	}//end __construct()

	/**
	 * Every check of a registration, in order: the provider document, then each required credential.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return list<array{check: string, passed: bool, reason: string}> The checks; a reason only when one failed.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-beheer-authenticatie-configures-the-registrations-req-idpc-008
	 */
	public function run(IdpRegistration $registration): array {
		$checks = [$this->providerDocument(registration: $registration)];
		foreach ($registration->requiredCredentialRoles() as $role) {
			$checks[] = $this->credential(registration: $registration, role: $role);
		}

		return $checks;

	}//end run()

	/**
	 * Whether the identity provider's metadata or discovery document can be read.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return array{check: string, passed: bool, reason: string}
	 */
	private function providerDocument(IdpRegistration $registration): array {
		if ($registration->kind() === IdpRegistration::KIND_OIDC) {
			$issuer = rtrim($registration->text(key: 'issuer'), '/');
			if ($issuer === '') {
				return self::failed(check: 'discovery', reason: 'No issuer is set.');
			}

			$document = json_decode((string)$this->fetch(url: $issuer.'/.well-known/openid-configuration'), true);
			if (is_array($document) === false || rtrim((string)($document['issuer'] ?? ''), '/') !== $issuer) {
				return self::failed(check: 'discovery', reason: 'The discovery document cannot be read or names another issuer.');
			}

			return self::passed(check: 'discovery');
		}

		$url = $registration->text(key: 'idpMetadataUrl');
		if ($url === '') {
			return self::failed(check: 'metadata', reason: 'No metadata address is set.');
		}

		if (self::isEntityDescriptor(xml: $this->fetch(url: $url)) === false) {
			return self::failed(check: 'metadata', reason: 'The metadata cannot be read as SAML metadata.');
		}

		return self::passed(check: 'metadata');

	}//end providerDocument()

	/**
	 * Whether one credential resolves; its value is read and dropped.
	 *
	 * @param IdpRegistration $registration The registration.
	 * @param string          $role         The credential role.
	 *
	 * @return array{check: string, passed: bool, reason: string}
	 */
	private function credential(IdpRegistration $registration, string $role): array {
		try {
			$this->secrets->read(
				registration: $registration->id,
				organisation: $registration->organisation(),
				role: $role,
				reference: $registration->credential(role: $role)
			);
		} catch (IdpSecretRefusedException $refused) {
			return self::failed(check: 'credential:'.$role, reason: 'The '.$role.' credential cannot be read ('.$refused->reason.').');
		}

		return self::passed(check: 'credential:'.$role);

	}//end credential()

	/**
	 * The body at an address, or null when it cannot be fetched.
	 *
	 * @param string $url The address.
	 *
	 * @return string|null
	 */
	private function fetch(string $url): ?string {
		if (str_starts_with($url, 'https://') === false) {
			return null;
		}

		try {
			$body = $this->http->newClient()->get($url, ['timeout' => self::TIMEOUT_SECONDS])->getBody();
		} catch (Throwable $exception) {
			return null;
		}

		if (is_resource($body) === true) {
			return (string)stream_get_contents($body);
		}

		return (string)$body;

	}//end fetch()

	/**
	 * Whether a body is SAML metadata: an EntityDescriptor or EntitiesDescriptor root, read without external entities.
	 *
	 * @param string|null $xml The body.
	 *
	 * @return bool
	 */
	private static function isEntityDescriptor(?string $xml): bool {
		if ($xml === null || trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) {
			return false;
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadXML($xml, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false || $document->documentElement === null) {
			return false;
		}

		return $document->documentElement->namespaceURI === 'urn:oasis:names:tc:SAML:2.0:metadata'
			&& in_array($document->documentElement->localName, ['EntityDescriptor', 'EntitiesDescriptor'], true);

	}//end isEntityDescriptor()

	/**
	 * A passed check.
	 *
	 * @param string $check The check's name.
	 *
	 * @return array{check: string, passed: bool, reason: string}
	 */
	private static function passed(string $check): array {
		return ['check' => $check, 'passed' => true, 'reason' => ''];

	}//end passed()

	/**
	 * A failed check.
	 *
	 * @param string $check  The check's name.
	 * @param string $reason Why, naming no value.
	 *
	 * @return array{check: string, passed: bool, reason: string}
	 */
	private static function failed(string $check, string $reason): array {
		return ['check' => $check, 'passed' => false, 'reason' => $reason];

	}//end failed()

}//end class
