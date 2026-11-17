<?php

/**
 * Integriq IdpMetadataService.
 *
 * The document an identity provider or broker reads to register one
 * organisation's Service Provider or Relying Party (portal-idp-broker-config
 * D3): SAML SP metadata for a `saml-sp` registration, a client document for an
 * `oidc-rp` one, and the JWKS its `private_key_jwt` client assertion is
 * checked against. Only public certificate material leaves this class.
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
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Auth\Idp;

use DOMDocument;
use DOMElement;
use Jose\Component\KeyManagement\JWKFactory;
use OCA\Integriq\Exception\IdpMetadataUnavailableException;
use OCA\Integriq\Exception\IdpSecretRefusedException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the published metadata of a registration.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
 */
class IdpMetadataService {

	/**
	 * The SAML 2.0 metadata namespace.
	 */
	public const NS_MD = 'urn:oasis:names:tc:SAML:2.0:metadata';

	/**
	 * The XML signature namespace (key info).
	 */
	public const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

	/**
	 * The SAML 2.0 protocol, the only one the SP speaks.
	 */
	public const PROTOCOL = 'urn:oasis:names:tc:SAML:2.0:protocol';

	/**
	 * The two bindings the shared callback accepts.
	 */
	public const BINDINGS = [
		'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
		'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
	];

	/**
	 * The attribute name format of a requested attribute.
	 */
	public const ATTRIBUTE_NAME_FORMAT = 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri';

	/**
	 * Constructor.
	 *
	 * @param IdpRegistrationService $registrations The registrations.
	 * @param IdpSecretSeam          $secretSeam    Reads the certificate credentials, failing closed.
	 * @param IURLGenerator          $urlGenerator  Builds the absolute metadata, callback and JWKS addresses.
	 * @param LoggerInterface        $logger        Logs an unreadable certificate by registration and role.
	 */
	public function __construct(
		private readonly IdpRegistrationService $registrations,
		private readonly IdpSecretSeam $secretSeam,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The published registration of an organisation and provider, or null.
	 *
	 * An unknown provider, a missing registration and a disabled one are all
	 * null, so the caller answers each the same.
	 *
	 * @param string $organisation The organisation.
	 * @param string $provider     The provider.
	 *
	 * @return IdpRegistration|null
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function published(string $organisation, string $provider): ?IdpRegistration {
		if (in_array($provider, IdpAdapterRegistry::PROVIDERS, true) === false) {
			return null;
		}

		$registration = $this->registrations->find(organisation: $organisation, provider: $provider);
		if ($registration === null || $registration->isEnabled() === false) {
			return null;
		}

		return $registration;

	}//end published()

	/**
	 * The metadata address of a registration, which is also the default EntityID.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function metadataUrl(IdpRegistration $registration): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'integriq.idpMetadata.metadata',
			['organisation' => $registration->organisation(), 'provider' => $registration->provider()]
		);

	}//end metadataUrl()

	/**
	 * The EntityID: the registration's own, else its metadata address (D3).
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function entityId(IdpRegistration $registration): string {
		$entityId = $registration->text(key: 'spEntityId');
		if ($entityId !== '') {
			return $entityId;
		}

		return $this->metadataUrl(registration: $registration);

	}//end entityId()

	/**
	 * The SAML SP metadata XML of a `saml-sp` registration.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return string
	 *
	 * @throws IdpMetadataUnavailableException When a certificate it names cannot be read.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function samlMetadata(IdpRegistration $registration): string {
		$certificates = ['signing' => $this->certificate(registration: $registration, role: 'signing')];
		if ($registration->credential(role: 'decryption') !== '') {
			$certificates['encryption'] = $this->certificate(registration: $registration, role: 'decryption');
		}

		$document = new DOMDocument(version: '1.0', encoding: 'UTF-8');
		$document->formatOutput = true;
		$entity = $document->createElementNS(self::NS_MD, 'md:EntityDescriptor');
		$entity->setAttribute('entityID', $this->entityId(registration: $registration));
		$document->appendChild($entity);

		$descriptor = $document->createElementNS(self::NS_MD, 'md:SPSSODescriptor');
		$descriptor->setAttribute('AuthnRequestsSigned', 'true');
		$descriptor->setAttribute('WantAssertionsSigned', 'true');
		$descriptor->setAttribute('protocolSupportEnumeration', self::PROTOCOL);
		$entity->appendChild($descriptor);

		foreach ($certificates as $use => $certificate) {
			$descriptor->appendChild($this->keyDescriptor(document: $document, use: $use, certificate: $certificate));
		}

		$callback = $this->callbackUrl(registration: $registration);
		foreach (self::BINDINGS as $index => $binding) {
			$service = $document->createElementNS(self::NS_MD, 'md:AssertionConsumerService');
			$service->setAttribute('Binding', $binding);
			$service->setAttribute('Location', $callback);
			$service->setAttribute('index', (string)$index);
			if ($index === 0) {
				$service->setAttribute('isDefault', 'true');
			}

			$descriptor->appendChild($service);
		}

		$attributes = $this->requestedAttributes(registration: $registration);
		if ($attributes !== []) {
			$descriptor->appendChild($this->attributeService(document: $document, registration: $registration, attributes: $attributes));
		}

		return (string)$document->saveXML();

	}//end samlMetadata()

	/**
	 * The client document of an `oidc-rp` registration: what a broker asks for
	 * when it registers the client. It carries no secret.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function oidcClient(IdpRegistration $registration): array {
		$clientAuth = $registration->text(key: 'clientAuth');
		$document = [
			'client_id' => $registration->text(key: 'clientId'),
			'client_name' => $registration->name(),
			'redirect_uris' => [$this->callbackUrl(registration: $registration)],
			'response_types' => ['code'],
			'grant_types' => ['authorization_code'],
			'token_endpoint_auth_method' => $clientAuth,
		];
		if ($clientAuth !== 'client_secret_basic') {
			$document['jwks_uri'] = $this->urlGenerator->linkToRouteAbsolute(
				'integriq.idpMetadata.jwks',
				['organisation' => $registration->organisation(), 'provider' => $registration->provider()]
			);
		}

		return $document;

	}//end oidcClient()

	/**
	 * The JWKS of an `oidc-rp` registration's `signing` certificate: its public key only.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return array{keys: list<array<string, mixed>>}
	 *
	 * @throws IdpMetadataUnavailableException When the certificate cannot be read.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-every-sp-is-published-at-its-own-metadata-url-req-idpc-003
	 */
	public function jwks(IdpRegistration $registration): array {
		$certificate = $this->certificate(registration: $registration, role: 'signing');
		try {
			$key = JWKFactory::createFromCertificate(
				certificate: $this->pem(certificate: $certificate),
				additional_values: ['use' => 'sig']
			)->toPublic();
		} catch (Throwable $exception) {
			throw $this->unavailable(
				registration: $registration,
				role: 'signing',
				cause: 'its certificate holds no usable public key ('.$exception::class.')'
			);
		}

		return ['keys' => [$key->all()]];

	}//end jwks()

	/**
	 * The shared callback of the registration's provider (D3).
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return string
	 */
	private function callbackUrl(IdpRegistration $registration): string {
		return $this->urlGenerator->linkToRouteAbsolute('integriq.idpBroker.callback', ['provider' => $registration->provider()]);

	}//end callbackUrl()

	/**
	 * The base64 DER body of the first certificate in a credential.
	 *
	 * Reads the credential through the seam and keeps only a CERTIFICATE
	 * block that parses as an X.509 certificate; a private key beside it in the
	 * same credential never leaves this method.
	 *
	 * @param IdpRegistration $registration The registration.
	 * @param string          $role         The credential role (`signing`, `decryption`).
	 *
	 * @return string
	 *
	 * @throws IdpMetadataUnavailableException When the credential is refused or holds no certificate.
	 */
	private function certificate(IdpRegistration $registration, string $role): string {
		try {
			$value = $this->secretSeam->read(
				registration: $registration->id,
				organisation: $registration->organisation(),
				role: $role,
				reference: $registration->credential(role: $role)
			);
		} catch (IdpSecretRefusedException $exception) {
			throw $this->unavailable(registration: $registration, role: $role, cause: 'the credential is refused ('.$exception->reason.')');
		}

		if (preg_match('/-----BEGIN CERTIFICATE-----([A-Za-z0-9+\/=\s]+)-----END CERTIFICATE-----/', $value, $match) !== 1) {
			throw $this->unavailable(registration: $registration, role: $role, cause: 'the credential holds no certificate');
		}

		$body = (string)preg_replace('/\s+/', '', $match[1]);
		if (base64_decode($body, true) === false || @openssl_x509_read($this->pem(certificate: $body)) === false) {
			throw $this->unavailable(registration: $registration, role: $role, cause: 'the certificate does not parse');
		}

		return $body;

	}//end certificate()

	/**
	 * A certificate body as PEM.
	 *
	 * @param string $certificate The base64 DER body.
	 *
	 * @return string
	 */
	private function pem(string $certificate): string {
		return "-----BEGIN CERTIFICATE-----\n".chunk_split($certificate, 64, "\n")."-----END CERTIFICATE-----\n";

	}//end pem()

	/**
	 * Log an unreadable certificate by registration, role and reference, and build the refusal.
	 *
	 * @param IdpRegistration $registration The registration.
	 * @param string          $role         The credential role.
	 * @param string          $cause        Why, naming no credential byte.
	 *
	 * @return IdpMetadataUnavailableException
	 */
	private function unavailable(IdpRegistration $registration, string $role, string $cause): IdpMetadataUnavailableException {
		$detail = 'The metadata of registration '.$registration->id.' cannot be published: the '.$role.' certificate cannot be read, '.$cause;
		$this->logger->warning(
			$detail,
			['app' => 'integriq', 'registration' => $registration->id, 'role' => $role, 'reference' => $registration->credential(role: $role)]
		);

		return new IdpMetadataUnavailableException(detail: $detail);

	}//end unavailable()

	/**
	 * A key descriptor carrying one certificate.
	 *
	 * @param DOMDocument $document    The document.
	 * @param string      $use         `signing` or `encryption`.
	 * @param string      $certificate The base64 DER body.
	 *
	 * @return DOMElement
	 */
	private function keyDescriptor(DOMDocument $document, string $use, string $certificate): DOMElement {
		$descriptor = $document->createElementNS(self::NS_MD, 'md:KeyDescriptor');
		$descriptor->setAttribute('use', $use);
		$keyInfo = $document->createElementNS(self::NS_DS, 'ds:KeyInfo');
		$data = $document->createElementNS(self::NS_DS, 'ds:X509Data');
		$data->appendChild($document->createElementNS(self::NS_DS, 'ds:X509Certificate', $certificate));
		$keyInfo->appendChild($data);
		$descriptor->appendChild($keyInfo);

		return $descriptor;

	}//end keyDescriptor()

	/**
	 * The attribute names the subject mapping reads, once each, in mapping order.
	 *
	 * @param IdpRegistration $registration The registration.
	 *
	 * @return list<string>
	 */
	private function requestedAttributes(IdpRegistration $registration): array {
		$names = [];
		foreach ($registration->stringMap(key: 'subjectMapping') as $name) {
			$name = trim($name);
			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		return $names;

	}//end requestedAttributes()

	/**
	 * The attribute consuming service naming the requested attributes.
	 *
	 * @param DOMDocument     $document     The document.
	 * @param IdpRegistration $registration The registration.
	 * @param list<string>    $attributes   The attribute names.
	 *
	 * @return DOMElement
	 */
	private function attributeService(DOMDocument $document, IdpRegistration $registration, array $attributes): DOMElement {
		$service = $document->createElementNS(self::NS_MD, 'md:AttributeConsumingService');
		$service->setAttribute('index', '0');
		$name = $document->createElementNS(self::NS_MD, 'md:ServiceName');
		$name->appendChild($document->createTextNode($registration->name()));
		$name->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'nl');
		$service->appendChild($name);
		foreach ($attributes as $attribute) {
			$requested = $document->createElementNS(self::NS_MD, 'md:RequestedAttribute');
			$requested->setAttribute('Name', $attribute);
			$requested->setAttribute('NameFormat', self::ATTRIBUTE_NAME_FORMAT);
			$service->appendChild($requested);
		}

		return $service;

	}//end attributeService()
}//end class
