<?php

/**
 * Integriq SamlServiceProviderAdapter.
 *
 * One organisation's `saml-sp` registration as a government identity provider
 * adapter (portal-idp-broker-config D1): a signed AuthnRequest over the HTTP
 * Redirect binding, built from the identity provider's metadata, and a
 * Response on the assertion consumer service whose assertion is verified
 * against the identity provider's certificate, decrypted with our decryption
 * key when it is encrypted, and normalised into the shape AssertionGuard reads.
 *
 * Every XML signature, wrapping, entity and decryption check is
 * onelogin/php-saml's (design D1, task 1); this class only configures it,
 * fail-closed, and reads the verified assertion through the registration's
 * subject mapping. Nothing here names a broker.
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
use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\SubjectPseudonymService;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCP\Http\Client\IClientService;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use Throwable;

/**
 * The SAML Service Provider adapter of one registration.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The SAML toolkit is split over several small classes.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
class SamlServiceProviderAdapter implements GovernmentIdpAdapterInterface {

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
	 * The HTTP timeout toward the metadata address, in seconds.
	 *
	 * @var integer
	 */
	private const TIMEOUT_SECONDS = 10;

	/**
	 * The parsed identity provider part of the toolkit settings, once read for this adapter.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $idp = null;

	/**
	 * Constructor.
	 *
	 * @param IdpRegistration  $registration The organisation's ready `saml-sp` registration.
	 * @param IdpSecretSeam    $secrets      Reads the `signing` and `decryption` credentials, for this request only.
	 * @param IClientService   $http         Reads the identity provider's metadata.
	 * @param TrustLevelMapper $trust        Says which spellings of the registration reach the floor.
	 * @param string           $entityId     Our EntityID: the registration's own, else its metadata address (D3).
	 * @param string           $acsUrl       The shared assertion consumer service of the registration's provider.
	 */
	public function __construct(
		private readonly IdpRegistration $registration,
		private readonly IdpSecretSeam $secrets,
		private readonly IClientService $http,
		private readonly TrustLevelMapper $trust,
		private readonly string $entityId,
		private readonly string $acsUrl,
	) {

	}//end __construct()

	/**
	 * The provider id this adapter answers to.
	 *
	 * @return string The registration's provider.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function getProviderId(): string {
		return $this->registration->provider();

	}//end getProviderId()

	/**
	 * Whether this adapter can authenticate anybody: it is built only for a ready registration.
	 *
	 * @return boolean True.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function isConfigured(): bool {
		return true;

	}//end isConfigured()

	/**
	 * Start a login with a signed AuthnRequest over the HTTP Redirect binding.
	 *
	 * @param array<string,mixed> $context `{organisation, consumer, trust, relayState}`; the relay state is
	 *                                     integriq's own state id and travels as `RelayState`.
	 *
	 * @return array{requestId: string, redirectUrl: string} The AuthnRequest ID, which the Response answers
	 *                                                       in `InResponseTo`, and the signed redirect.
	 *
	 * @throws IdpAssertionException When there is no relay state, or the metadata or the signing key cannot be used.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function beginAuthentication(array $context): array {
		$relayState = trim((string)($context['relayState'] ?? ''));
		if ($relayState === '') {
			throw new IdpAssertionException(message: 'A SAML login needs a relay state, so no login starts.');
		}

		[$certificate, $privateKey] = $this->keyPair(role: 'signing');
		try {
			$auth = new Auth($this->settings(certificate: $certificate, privateKey: $privateKey));
			$redirectUrl = (string)$auth->login(returnTo: $relayState, stay: true, setNameIdPolicy: false);
			$requestId = (string)$auth->getLastRequestID();
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The AuthnRequest could not be built ('.$exception::class.'), so no login starts.');
		}

		if ($requestId === '' || $redirectUrl === '') {
			throw new IdpAssertionException(message: 'The AuthnRequest could not be built, so no login starts.');
		}

		return ['requestId' => $requestId, 'redirectUrl' => $redirectUrl];

	}//end beginAuthentication()

	/**
	 * Verify the Response on the assertion consumer service and normalise its assertion.
	 *
	 * The toolkit refuses a bad signature, a wrapped assertion, a DOCTYPE or
	 * entity, an unsigned assertion, a Response for another audience or
	 * destination, and an encrypted assertion it cannot decrypt. Only after it
	 * answers valid is any attribute read.
	 *
	 * @param array<string,mixed> $callback What arrived on the callback: `SAMLResponse` (and `RelayState`).
	 *
	 * @return array<string,mixed> The assertion {@see \OCA\Integriq\Auth\Idp\AssertionGuard} reads.
	 *
	 * @throws IdpAssertionException When the callback carries no Response, or the Response does not verify.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function readAssertion(array $callback): array {
		$encoded = ($callback['SAMLResponse'] ?? null);
		if (is_string($encoded) === false || trim($encoded) === '') {
			throw new IdpAssertionException(message: 'The callback carries no SAMLResponse, so it is refused.');
		}

		$role = 'signing';
		if ($this->registration->credential(role: 'decryption') !== '') {
			$role = 'decryption';
		}

		[$certificate, $privateKey] = $this->keyPair(role: $role);
		try {
			$settings = new Settings($this->settings(certificate: $certificate, privateKey: $privateKey));
		} catch (IdpAssertionException $refused) {
			throw $refused;
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The SAML settings of the registration are refused ('.$exception::class.').');
		}

		$response = $this->verifiedResponse(settings: $settings, encoded: trim($encoded));

		return $this->normalised(response: $response);

	}//end readAssertion()

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
	 *
	 * @return Response
	 *
	 * @throws IdpAssertionException When the Response does not parse or does not verify.
	 */
	private function verifiedResponse(Settings $settings, string $encoded): Response {
		$restore = $this->pinCurrentUrl();
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

	}//end verifiedResponse()

	/**
	 * Point the toolkit's idea of the current address at our assertion consumer service.
	 *
	 * @return callable(): void Puts back what was there before.
	 */
	private function pinCurrentUrl(): callable {
		$parts = parse_url($this->acsUrl);
		$scheme = strtolower((string)($parts['scheme'] ?? 'https'));
		$port = ($parts['port'] ?? null);
		if ($port === null) {
			$port = 443;
			if ($scheme === 'http') {
				$port = 80;
			}
		}

		$server = [];
		foreach (['REQUEST_URI', 'QUERY_STRING'] as $key) {
			$server[$key] = ($_SERVER[$key] ?? null);
		}

		$basePath = Utils::getBaseURLPath();

		Utils::setSelfProtocol($scheme);
		Utils::setSelfHost((string)($parts['host'] ?? ''));
		Utils::setSelfPort((int)$port);
		Utils::setBaseURLPath(null);
		$_SERVER['REQUEST_URI'] = (string)($parts['path'] ?? '/');
		unset($_SERVER['QUERY_STRING']);

		return static function () use ($server, $basePath): void {
			Utils::setSelfProtocol(null);
			Utils::setSelfHost(null);
			Utils::setSelfPort(null);
			Utils::setBaseURLPath($basePath);
			foreach ($server as $key => $value) {
				if ($value === null) {
					unset($_SERVER[$key]);
					continue;
				}

				$_SERVER[$key] = $value;
			}
		};

	}//end pinCurrentUrl()

	/**
	 * The normalised assertion of a verified Response, read through the registration's subject mapping only.
	 *
	 * @param Response $response The verified Response.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws IdpAssertionException When the verified assertion cannot be found again.
	 */
	private function normalised(Response $response): array {
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
		$root = $document->documentElement;
		foreach (($root?->childNodes ?? []) as $child) {
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

	/**
	 * The toolkit settings: strict, signed requests, signed assertions, SHA-256, and our key pair.
	 *
	 * @param string $certificate Our certificate, PEM.
	 * @param string $privateKey  Our private key, PEM.
	 *
	 * @return array<string, mixed> The toolkit's settings array.
	 *
	 * @throws IdpAssertionException When the metadata cannot be used.
	 */
	private function settings(string $certificate, string $privateKey): array {
		$requestedContext = $this->requestedAuthnContext();
		$comparison = 'exact';
		if (count($requestedContext) === 1) {
			$comparison = 'minimum';
		}

		return [
			'strict' => true,
			'debug' => false,
			'sp' => [
				'entityId' => $this->entityId,
				'assertionConsumerService' => ['url' => $this->acsUrl, 'binding' => Constants::BINDING_HTTP_POST],
				'NameIDFormat' => Constants::NAMEID_UNSPECIFIED,
				'x509cert' => $certificate,
				'privateKey' => $privateKey,
			],
			'idp' => $this->identityProvider(),
			'security' => [
				'authnRequestsSigned' => true,
				'wantAssertionsSigned' => true,
				'wantMessagesSigned' => false,
				'wantAssertionsEncrypted' => $this->registration->credential(role: 'decryption') !== '',
				'wantNameId' => false,
				'wantXMLValidation' => true,
				'requestedAuthnContext' => ($requestedContext === [] ? false : $requestedContext),
				'requestedAuthnContextComparison' => $comparison,
				'rejectUnsolicitedResponsesWithInResponseTo' => true,
				'relaxDestinationValidation' => false,
				'destinationStrictlyMatches' => true,
				'rejectDeprecatedAlgorithm' => true,
				'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
				'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
			],
		];

	}//end settings()

	/**
	 * The spellings of the registration's assurance mapping at or above the floor, for RequestedAuthnContext.
	 *
	 * @return list<string>
	 */
	private function requestedAuthnContext(): array {
		$aliases = $this->registration->stringMap(key: 'assuranceMapping');
		$values = [];
		foreach (array_keys($aliases) as $spelling) {
			try {
				$trust = $this->trust->map(provider: $this->registration->provider(), level: $spelling, aliases: $aliases);
			} catch (IdpAssertionException $unmapped) {
				continue;
			}

			if ($this->trust->meetsFloor(trust: $trust) === true) {
				$values[] = $spelling;
			}
		}

		return $values;

	}//end requestedAuthnContext()

	/**
	 * The identity provider part of the settings, read from its metadata address.
	 *
	 * @return array<string, mixed> `{entityId, singleSignOnService, x509cert | x509certMulti}`.
	 *
	 * @throws IdpAssertionException When the metadata cannot be read or offers no Redirect sign-on or no signing certificate.
	 */
	private function identityProvider(): array {
		if ($this->idp !== null) {
			return $this->idp;
		}

		$url = $this->registration->text(key: 'idpMetadataUrl');
		try {
			$response = $this->http->newClient()->get(
				$url,
				['headers' => ['Accept' => 'application/samlmetadata+xml, application/xml'], 'timeout' => self::TIMEOUT_SECONDS]
			);
			$parsed = IdPMetadataParser::parseXML(self::body(body: $response->getBody()));
		} catch (Throwable $exception) {
			throw new IdpAssertionException(message: 'The identity provider metadata could not be read ('.$exception::class.').');
		}

		$idp = ($parsed['idp'] ?? null);
		if (is_array($idp) === false || (string)($idp['singleSignOnService']['url'] ?? '') === '') {
			throw new IdpAssertionException(message: 'The identity provider metadata offers no HTTP Redirect sign-on service.');
		}

		if (str_starts_with((string)$idp['singleSignOnService']['url'], 'https://') === false) {
			throw new IdpAssertionException(message: 'The identity provider metadata offers no https sign-on service.');
		}

		if ((string)($idp['x509cert'] ?? '') === '' && empty($idp['x509certMulti']['signing']) === true) {
			throw new IdpAssertionException(message: 'The identity provider metadata carries no signing certificate.');
		}

		$this->idp = $idp;

		return $idp;

	}//end identityProvider()

	/**
	 * The certificate and the private key one credential holds, both PEM.
	 *
	 * @param string $role The credential role (`signing`, `decryption`).
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws IdpAssertionException When the seam refuses it, or it lacks a certificate or a private key.
	 */
	private function keyPair(string $role): array {
		try {
			$secret = $this->secrets->read(
				registration: $this->registration->id,
				organisation: $this->registration->organisation(),
				role: $role,
				reference: $this->registration->credential(role: $role)
			);
		} catch (IdpSecretRefusedException $refused) {
			throw new IdpAssertionException(message: 'The '.$role.' credential of the registration could not be read.', previous: $refused);
		}

		$hasCertificate = preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $secret, $certificate);
		$hasKey = preg_match('/-----BEGIN (?:RSA |ENCRYPTED )?PRIVATE KEY-----.+?-----END (?:RSA |ENCRYPTED )?PRIVATE KEY-----/s', $secret, $key);
		if ($hasCertificate !== 1 || $hasKey !== 1) {
			throw new IdpAssertionException(message: 'The '.$role.' credential holds no certificate and private key, so it is not used.');
		}

		return [$certificate[0], $key[0]];

	}//end keyPair()

	/**
	 * A response body as a string.
	 *
	 * @param mixed $body The body: a string or a stream resource.
	 *
	 * @return string
	 */
	private static function body(mixed $body): string {
		if (is_resource($body) === true) {
			return (string)stream_get_contents($body);
		}

		return (string)$body;

	}//end body()

}//end class
