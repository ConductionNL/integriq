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

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\Settings;
use OCP\Http\Client\IClientService;
use OCA\Integriq\Exception\IdpAssertionException;
use OCA\Integriq\Auth\Idp\TrustLevelMapper;
use OCA\Integriq\Auth\Idp\IdpSecretSeam;
use OCA\Integriq\Auth\Idp\IdpRegistration;
use OCA\Integriq\Auth\Idp\GovernmentIdpAdapterInterface;
use Throwable;

/**
 * The SAML Service Provider adapter of one registration.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) IdPMetadataParser::parseXML() is the toolkit's metadata reader.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
 */
class SamlServiceProviderAdapter implements GovernmentIdpAdapterInterface {
	use RegistrationAdapterSupport;


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
	 * Reads the `signing` and `decryption` credentials, for this request only.
	 *
	 * @var IdpSecretSeam
	 */
	private readonly IdpSecretSeam $secrets;

	/**
	 * Reads the identity provider's metadata.
	 *
	 * @var IClientService
	 */
	private readonly IClientService $http;

	/**
	 * Says which spellings of the registration reach the floor.
	 *
	 * @var TrustLevelMapper
	 */
	private readonly TrustLevelMapper $trust;

	/**
	 * Constructor.
	 *
	 * @param IdpRegistration    $registration The organisation's ready `saml-sp` registration.
	 * @param IdpAdapterServices $services     The secret seam, the HTTP client and the trust table.
	 * @param string             $entityId     Our EntityID: the registration's own, else its metadata address (D3).
	 * @param string             $acsUrl       The shared assertion consumer service of the registration's provider.
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-an-identity-provider-adapter-is-one-of-two-vendor-neutral-kinds-req-idpc-001
	 */
	public function __construct(
		private readonly IdpRegistration $registration,
		IdpAdapterServices $services,
		private readonly string $entityId,
		private readonly string $acsUrl,
	) {
		$this->secrets = $services->secrets;
		$this->http = $services->http;
		$this->trust = $services->trust;

	}//end __construct()



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

		$assertion = new SamlVerifiedAssertion(registration: $this->registration, entityId: $this->entityId);

		return $assertion->normalised(response: $assertion->verify(settings: $settings, encoded: trim($encoded), acsUrl: $this->acsUrl));

	}//end readAssertion()

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
		$requestedContext = $this->floorSpellings();
		$comparison = 'exact';
		if (count($requestedContext) === 1) {
			$comparison = 'minimum';
		}

		if ($requestedContext === []) {
			$requestedContext = false;
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
				'requestedAuthnContext' => $requestedContext,
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
		$secret = $this->credential(role: $role);
		$hasCertificate = preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $secret, $certificate);
		$hasKey = preg_match('/-----BEGIN (?:RSA |ENCRYPTED )?PRIVATE KEY-----.+?-----END (?:RSA |ENCRYPTED )?PRIVATE KEY-----/s', $secret, $key);
		if ($hasCertificate !== 1 || $hasKey !== 1) {
			throw new IdpAssertionException(message: 'The '.$role.' credential holds no certificate and private key, so it is not used.');
		}

		return [$certificate[0], $key[0]];

	}//end keyPair()


}//end class
