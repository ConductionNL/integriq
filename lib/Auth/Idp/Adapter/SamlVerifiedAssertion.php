<?php

/**
 * Integriq SamlVerifiedAssertion.
 *
 * The assertion of a Response the SAML toolkit answered valid, normalised
 * into the shape AssertionGuard reads. It is built only after the toolkit's
 * checks pass, and it reads the subject through the registration's mapping
 * only: an attribute the mapping does not name is never read.
 *
 * @category Service
 * @package  OCA\Integriq\Auth\Idp\Adapter
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp\Adapter;

use DOMDocument;
use DOMElement;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\SubjectPseudonymService;
use OCA\Integriq\Exception\IdpAssertionException;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use Throwable;

/**
 * Normalises one verified assertion.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
final class SamlVerifiedAssertion {

	/**
	 * The subject fields in the order they are looked for, with the subject type each yields.
	 *
	 * @var array<string, string>
	 */
	public const SUBJECT_FIELDS = [
		'bsn' => 'bsn',
		'pseudonym' => SubjectPseudonymService::SUBTYPE_BSN_PSEUDONYM,
		'kvk' => 'kvk',
		'rsin' => 'rsin',
		'eidasPersonIdentifier' => 'eidas-person-identifier',
	];

	/**
	 * Constructor.
	 *
	 * @param IdpRegistration $registration The registration the Response was read for.
	 * @param string          $entityId     Our EntityID.
	 */
	public function __construct(
		private readonly IdpRegistration $registration,
		private readonly string $entityId,
	) {

	}//end __construct()

	/**
	 * The Response, once the toolkit answers it valid for the request it names.
	 *
	 * `InResponseTo` is read first and required: the toolkit then checks the
	 * Response and the assertion's subject confirmation both answer it, and
	 * the login service refuses it when no state of ours was stored for it.
	 * The toolkit's destination and recipient checks compare with the address
	 * the request arrived on, so for the length of the check that address is
	 * pinned to our own assertion consumer service.
	 *
	 * @param Settings $settings The toolkit settings with the decryption key.
	 * @param string   $encoded  The base64 SAMLResponse.
	 * @param string   $acsUrl   Our assertion consumer service, which the Response must name.
	 *
	 * @return Response
	 *
	 * @throws IdpAssertionException When the Response does not parse or does not verify.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function verify(Settings $settings, string $encoded, string $acsUrl): Response {
		$restore = (new SamlCurrentAddress())->pin(address: $acsUrl);
		try {
			$response = new Response($settings, $encoded);
			$inResponseTo = $response->document->documentElement?->getAttribute('InResponseTo') ?? '';
			if (trim($inResponseTo) === '') {
				throw new IdpAssertionException(message: 'The Response answers no request (InResponseTo), so it is refused.');
			}

			$valid = $response->isValid(trim($inResponseTo));
		} catch (IdpAssertionException $refused) {
			throw $refused;
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The Response cannot be read ('.$exception::class.'), so it is refused.');
		} finally {
			$restore();
		}

		if ($valid !== true) {
			// The toolkit's reason names checks, never attribute values.
			throw new IdpAssertionException(message: 'The Response does not verify ('.$response->getError().'), so it is refused.');
		}

		return $response;

	}//end verify()

	/**
	 * The normalised assertion of a verified Response, read through the registration's subject mapping only.
	 *
	 * @param Response $response The verified Response.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws IdpAssertionException When the verified assertion cannot be found again.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function normalised(Response $response): array {
		$assertionId = (string)$response->getAssertionId();
		$assertion = self::assertionElement(document: $response->getXMLDocument(), assertionId: $assertionId);
		$conditions = self::firstChild(parent: $assertion, name: 'Conditions');

		$attributes = $response->getAttributes();
		$mapping = $this->registration->stringMap(key: 'subjectMapping');
		[$subject, $subType] = self::subject(attributes: $attributes, mapping: $mapping);

		$normalised = [
			'id' => $assertionId,
			'inResponseTo' => trim($response->document->documentElement?->getAttribute('InResponseTo') ?? ''),
			'audience' => $this->audience(audiences: $response->getAudiences()),
			'notBefore' => ($conditions?->getAttribute('NotBefore') ?? ''),
			'notOnOrAfter' => ($conditions?->getAttribute('NotOnOrAfter') ?? ''),
			'subject' => $subject,
			'subType' => $subType,
			'assuranceLevel' => self::authnContext(assertion: $assertion),
			'organisation' => $this->registration->organisation(),
		];

		$branchAttribute = ($mapping['branch'] ?? '');
		if ($branchAttribute !== '' && self::attributeText(attributes: $attributes, name: $branchAttribute) !== '') {
			$normalised['branch'] = self::attributeText(attributes: $attributes, name: $branchAttribute);
		}

		return $normalised;

	}//end normalised()

	/**
	 * The one assertion element the toolkit verified, found by its ID.
	 *
	 * @param DOMDocument $document    The (decrypted) Response document.
	 * @param string      $assertionId The verified assertion's ID.
	 *
	 * @return DOMElement
	 *
	 * @throws IdpAssertionException When not exactly one assertion directly under the Response carries that ID.
	 */
	private static function assertionElement(DOMDocument $document, string $assertionId): DOMElement {
		$found = [];
		foreach ($document->documentElement->childNodes as $child) {
			if ($child instanceof DOMElement && $child->namespaceURI === Constants::NS_SAML
				&& $child->localName === 'Assertion' && $child->getAttribute('ID') === $assertionId
			) {
				$found[] = $child;
			}
		}

		if ($assertionId === '' || count($found) !== 1) {
			throw new IdpAssertionException(message: 'The verified assertion cannot be found once, so the Response is refused.');
		}

		return $found[0];

	}//end assertionElement()

	/**
	 * The first SAML assertion child element of a name, or null.
	 *
	 * @param DOMElement $parent The parent.
	 * @param string     $name   The local name.
	 *
	 * @return DOMElement|null
	 */
	private static function firstChild(DOMElement $parent, string $name): ?DOMElement {
		foreach ($parent->childNodes as $child) {
			if ($child instanceof DOMElement && $child->namespaceURI === Constants::NS_SAML && $child->localName === $name) {
				return $child;
			}
		}

		return null;

	}//end firstChild()

	/**
	 * The AuthnContextClassRef of the assertion's one AuthnStatement: the assurance level as the provider spells it.
	 *
	 * @param DOMElement $assertion The verified assertion.
	 *
	 * @return string
	 */
	private static function authnContext(DOMElement $assertion): string {
		$statement = self::firstChild(parent: $assertion, name: 'AuthnStatement');
		$context = null;
		if ($statement !== null) {
			$context = self::firstChild(parent: $statement, name: 'AuthnContext');
		}

		$classRef = null;
		if ($context !== null) {
			$classRef = self::firstChild(parent: $context, name: 'AuthnContextClassRef');
		}

		return trim((string)$classRef?->textContent);

	}//end authnContext()

	/**
	 * The audience the guard checks: our EntityID when the assertion names it, else the first it names.
	 *
	 * @param array<int, string> $audiences The assertion's audiences.
	 *
	 * @return string
	 */
	private function audience(array $audiences): string {
		if (in_array($this->entityId, $audiences, true) === true) {
			return $this->entityId;
		}

		return trim((string)($audiences[0] ?? ''));

	}//end audience()

	/**
	 * The first mapped subject field the attributes carry, with its subject type.
	 *
	 * @param array<string, mixed>  $attributes The verified attributes, by name.
	 * @param array<string, string> $mapping    The registration's subject mapping.
	 *
	 * @return array{0: string, 1: string} The subject and its type; both empty when none is carried.
	 */
	private static function subject(array $attributes, array $mapping): array {
		foreach (self::SUBJECT_FIELDS as $field => $subType) {
			$name = ($mapping[$field] ?? '');
			if ($name === '') {
				continue;
			}

			$value = self::attributeText(attributes: $attributes, name: $name);
			if ($value !== '') {
				return [$value, $subType];
			}
		}

		return ['', ''];

	}//end subject()

	/**
	 * The first value of an attribute as trimmed text, or ''.
	 *
	 * @param array<string, mixed> $attributes The attributes, by name, each a list of values.
	 * @param string               $name       The attribute name.
	 *
	 * @return string
	 */
	private static function attributeText(array $attributes, string $name): string {
		$values = ($attributes[$name] ?? []);
		if (is_array($values) === false) {
			$values = [$values];
		}

		$value = ($values[0] ?? '');
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);

	}//end attributeText()

}//end class
