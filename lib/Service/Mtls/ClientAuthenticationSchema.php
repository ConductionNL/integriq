<?php

/**
 * Integriq client authentication schema.
 *
 * The `configuration.authentication` JSON Schema fragments that every REST
 * client authenticating with an encrypted token or a client certificate
 * (`DsoClient`, `FscDirectoryClient`, `IStandardsClient`, `StufZknClient`)
 * reports from its getConfigSchema(). Only the descriptions that name the
 * partner differ per client; the clients pass those in.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Mtls
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Mtls;

/**
 * Builds the shared `authentication` schema fragments of the token-or-mTLS REST clients.
 *
 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
 */
final class ClientAuthenticationSchema {

	/**
	 * Default `Authorization` header scheme in token mode.
	 *
	 * @var string
	 */
	public const DEFAULT_SCHEME = 'Bearer';

	/**
	 * The `authentication` object of a client that supports the `token` and `mtls` modes.
	 *
	 * @param string $modeDescription  Description of the `mode` field, naming the partner transport.
	 * @param string $tokenDescription Description of the `encryptedToken` field, naming the partner token.
	 *
	 * @return array<string, mixed> The `authentication` JSON Schema object.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
	 */
	public static function tokenOrMtls(string $modeDescription, string $tokenDescription): array {
		return [
			'type' => 'object',
			'properties' => [
				'mode' => self::modeField(modes: ['token', 'mtls'], description: $modeDescription),
				'encryptedToken' => self::tokenField(description: $tokenDescription),
				'scheme' => self::schemeField(),
				'mtls' => self::mtlsField(),
			],
		];

	}//end tokenOrMtls()

	/**
	 * The `authentication.mode` field.
	 *
	 * @param array<int, string> $modes       The supported modes; `token` is the default.
	 * @param string             $description The field description.
	 *
	 * @return array<string, mixed> The `mode` JSON Schema field.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
	 */
	public static function modeField(array $modes, string $description): array {
		return [
			'type' => 'string',
			'enum' => $modes,
			'default' => 'token',
			'description' => $description,
		];

	}//end modeField()

	/**
	 * The `authentication.encryptedToken` field.
	 *
	 * @param string $description The field description.
	 *
	 * @return array<string, string> The `encryptedToken` JSON Schema field.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
	 */
	public static function tokenField(string $description): array {
		return [
			'type' => 'string',
			'description' => $description,
		];

	}//end tokenField()

	/**
	 * The `authentication.scheme` field.
	 *
	 * @return array<string, string> The `scheme` JSON Schema field.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
	 */
	public static function schemeField(): array {
		return [
			'type' => 'string',
			'description' => 'Authorization header scheme, used only when `mode=token`.',
			'default' => self::DEFAULT_SCHEME,
		];

	}//end schemeField()

	/**
	 * The `authentication.mtls` object: the encrypted client certificate material.
	 *
	 * @return array<string, mixed> The `mtls` JSON Schema object.
	 *
	 * @spec openspec/specs/mtls-client-certificate-transport/spec.md#requirement-shared-mtls-transport-builds-guzzle-tls-options-from-encrypted-at-rest-certificate-material-req-001
	 */
	public static function mtlsField(): array {
		return [
			'type' => 'object',
			'description' => 'Required when `mode=mtls`. Client certificate material, each field '
				. 'individually encrypted at rest via OCP\\Security\\ICrypto.',
			'properties' => [
				'encryptedCertificate' => ['type' => 'string', 'description' => 'PEM client certificate.'],
				'encryptedPrivateKey' => ['type' => 'string', 'description' => 'PEM private key.'],
				'encryptedPassphrase' => ['type' => 'string', 'description' => 'Optional private key passphrase.'],
				'encryptedCaBundle' => ['type' => 'string', 'description' => 'Optional PEM CA bundle to verify the peer against.'],
			],
		];

	}//end mtlsField()
}//end class
