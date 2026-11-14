<?php

/**
 * Integriq IssuerKeyService
 *
 * Integriq's own token-signing key (access-oauth-and-token-validation, D3).
 * The private key is minted as an organisation-scope credential in
 * OpenRegister's credential broker and referenced by its credentialRef; it
 * never sits on an OpenRegister object. Only non-secret material is kept in
 * the app config: the credentialRef, the organisation, the key id and the
 * public key (as a JWK and as PEM).
 *
 * @category Service
 * @package  OCA\Integriq\Service\OAuth
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OAuth;

use OCA\Integriq\Service\OrganisationBridgeService;
use OCA\Integriq\Service\Security\InlineSecretMigrationExecutor;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Creates, publishes and signs with the issuer key.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 */
class IssuerKeyService {

	/**
	 * The app config key of the broker credential that holds the private key.
	 */
	public const CONFIG_CREDENTIAL_REF = 'oauth_issuer_credential_ref';

	/**
	 * The app config key of the organisation that owns the credential.
	 */
	public const CONFIG_ORGANISATION = 'oauth_issuer_organisation';

	/**
	 * The app config key of the public key as a JWK (JSON).
	 */
	public const CONFIG_PUBLIC_JWK = 'oauth_issuer_public_jwk';

	/**
	 * The app config key of the public key as PEM.
	 */
	public const CONFIG_PUBLIC_PEM = 'oauth_issuer_public_pem';

	/**
	 * The signing algorithm.
	 */
	public const ALGORITHM = 'RS256';

	/**
	 * The broker provider an inject-only secret is held under (as for the S/MIME private key).
	 */
	public const BROKER_PROVIDER = 'generic-apikey';

	/**
	 * This app's id in the app config.
	 */
	private const APP = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig                $appConfig     Holds the credentialRef and the public key.
	 * @param IURLGenerator             $urlGenerator  Builds the issuer URL.
	 * @param ContainerInterface        $container     Resolves OpenRegister's credential broker lazily.
	 * @param OrganisationBridgeService $organisations The default organisation that owns the credential.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
		private readonly ContainerInterface $container,
		private readonly OrganisationBridgeService $organisations,
	) {
	}//end __construct()

	/**
	 * The issuer URL integriq's tokens carry as `iss`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function issuerUrl(): string {
		return rtrim($this->urlGenerator->getAbsoluteURL('/apps/' . self::APP), '/');
	}//end issuerUrl()

	/**
	 * Whether an issuer key exists.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function hasKey(): bool {
		return $this->value(key: self::CONFIG_CREDENTIAL_REF) !== '' && $this->publicJwk() !== null;
	}//end hasKey()

	/**
	 * Create (or replace) the issuer key: mint the private key into the broker, keep the public half.
	 *
	 * @param string $owner The admin who creates it; the credential's owner.
	 *
	 * @return array<string, string> The public JWK.
	 *
	 * @throws RuntimeException When no broker or organisation is available, or the key cannot be made.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function create(string $owner): array {
		$organisation = (string) ($this->organisations->getDefaultOrganisationUuid() ?? '');
		if ($organisation === '') {
			throw new RuntimeException('No default organisation to hold the issuer key.');
		}

		$pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		if ($pair === false || openssl_pkey_export($pair, $privatePem) === false) {
			throw new RuntimeException('The issuer key could not be generated.');
		}

		$details   = openssl_pkey_get_details($pair);
		$kid       = bin2hex(random_bytes(8));
		$credential = $this->broker()->mint(
			'Integriq token issuer key ' . $kid,
			self::BROKER_PROVIDER,
			$owner,
			[InlineSecretMigrationExecutor::APP_ID],
			$privatePem,
			InlineSecretMigrationExecutor::SCOPE_ORGANISATION,
			$organisation
		);
		$ref = '';
		if ($credential instanceof ObjectEntity) {
			$ref = (string) $credential->getUuid();
		}

		if ($ref === '' || is_array($details) === false) {
			throw new RuntimeException('The issuer key could not be stored in the credential broker.');
		}

		$jwk = [
			'kty' => 'RSA',
			'use' => 'sig',
			'alg' => self::ALGORITHM,
			'kid' => $kid,
			'n'   => self::base64Url(bytes: (string) $details['rsa']['n']),
			'e'   => self::base64Url(bytes: (string) $details['rsa']['e']),
		];

		$this->appConfig->setValueString(self::APP, self::CONFIG_CREDENTIAL_REF, $ref);
		$this->appConfig->setValueString(self::APP, self::CONFIG_ORGANISATION, $organisation);
		$this->appConfig->setValueString(self::APP, self::CONFIG_PUBLIC_JWK, (string) json_encode($jwk));
		$this->appConfig->setValueString(self::APP, self::CONFIG_PUBLIC_PEM, (string) $details['key']);

		return $jwk;
	}//end create()

	/**
	 * The published key set: the public JWK, or none before a key was created.
	 *
	 * @return array{keys: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function jwks(): array {
		$jwk = $this->publicJwk();
		if ($jwk === null) {
			return ['keys' => []];
		}

		return ['keys' => [$jwk]];
	}//end jwks()

	/**
	 * The public key as PEM, '' before a key was created.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function publicPem(): string {
		return $this->value(key: self::CONFIG_PUBLIC_PEM);
	}//end publicPem()

	/**
	 * Sign claims as a JWT with the issuer key.
	 *
	 * @param array<string, mixed> $claims The claims.
	 *
	 * @return string The compact JWT.
	 *
	 * @throws RuntimeException When there is no key or the broker does not hand it over.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function sign(array $claims): string {
		$jwk = $this->publicJwk();
		if ($jwk === null || $this->hasKey() === false) {
			throw new RuntimeException('No issuer key has been created.');
		}

		try {
			$privatePem = $this->broker()->resolveInjectable(
				$this->value(key: self::CONFIG_CREDENTIAL_REF),
				InlineSecretMigrationExecutor::APP_ID,
				null,
				$this->value(key: self::CONFIG_ORGANISATION)
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The issuer key could not be read from the credential broker.');
		}

		$header  = ['alg' => self::ALGORITHM, 'typ' => 'JWT', 'kid' => $jwk['kid']];
		$signing = self::base64Url(bytes: (string) json_encode($header)) . '.' . self::base64Url(bytes: (string) json_encode($claims));
		$key     = false;
		if (is_string($privatePem) === true) {
			$key = openssl_pkey_get_private($privatePem);
		}

		if ($key === false || openssl_sign($signing, $signature, $key, OPENSSL_ALGO_SHA256) === false) {
			throw new RuntimeException('The token could not be signed.');
		}

		return $signing . '.' . self::base64Url(bytes: $signature);
	}//end sign()

	/**
	 * The public JWK, or null before a key was created.
	 *
	 * @return array<string, string>|null
	 */
	private function publicJwk(): ?array {
		$jwk = json_decode($this->value(key: self::CONFIG_PUBLIC_JWK), true);
		if (is_array($jwk) === false || is_string($jwk['kid'] ?? null) === false) {
			return null;
		}

		return $jwk;
	}//end publicJwk()

	/**
	 * An app config value, '' when unset.
	 *
	 * @param string $key The key.
	 *
	 * @return string
	 */
	private function value(string $key): string {
		return $this->appConfig->getValueString(self::APP, $key, '');
	}//end value()

	/**
	 * OpenRegister's credential broker, duck-typed: it exposes mint() and resolveInjectable().
	 *
	 * @return object
	 *
	 * @throws RuntimeException When OpenRegister ships no broker.
	 */
	private function broker(): object {
		try {
			return $this->container->get(InlineSecretMigrationExecutor::BROKER_CLASS);
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister\'s credential broker is not available.');
		}
	}//end broker()

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes The bytes.
	 *
	 * @return string
	 */
	private static function base64Url(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end base64Url()
}//end class
