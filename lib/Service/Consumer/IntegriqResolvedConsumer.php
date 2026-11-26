<?php

/**
 * IntegriqResolvedConsumer: builds OpenRegister's resolved-consumer value for an integriq consumer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Consumer;

use OCA\Integriq\Service\OAuth\IssuerKeyService;
use OCA\Integriq\Service\OAuth\TokenIssuer;
use OCA\OpenRegister\Service\Consumer\ConsumerAuthorization;
use OCA\OpenRegister\Service\Consumer\ConsumerIdentity;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;

/**
 * Builds a ResolvedConsumer from an identity and an authorization, with integriq as its source.
 *
 * @spec openspec/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
 */
final class IntegriqResolvedConsumer {

	/**
	 * The resolved consumer of one integriq consumer object.
	 *
	 * @param array{uuid: string|null, name: string, userId: string|null} $who               The consumer's uuid and name, and the
	 *                                                                     Nextcloud user it acts as, if any.
	 * @param string|null                                                 $authorizationType How it authenticates.
	 * @param array<string, mixed>                                        $configuration     The configuration OpenRegister verifies against.
	 * @param mixed                                                       $record            The consumer object itself.
	 *
	 * @return ResolvedConsumer
	 *
	 * @spec openspec/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public function fromRecord(array $who, ?string $authorizationType, array $configuration, mixed $record): ResolvedConsumer {
		return new ResolvedConsumer(
			identity: new ConsumerIdentity(source: IntegriqConsumerSource::SOURCE, uuid: $who['uuid'], name: $who['name']),
			authorization: new ConsumerAuthorization(
				userId: $who['userId'],
				authorizationType: $authorizationType,
				configuration: $configuration
			),
			record: $record,
		);
	}//end fromRecord()

	/**
	 * The resolved consumer of a token integriq's own issuer minted: verified against the issuer's public key.
	 *
	 * @param array{uuid: string|null, name: string, userId: string|null} $who       The consumer's uuid and name, and the
	 *                                                                     Nextcloud user it acts as, if any.
	 * @param string                                                      $publicPem The issuer's public key.
	 * @param mixed                                                       $record    The consumer object itself.
	 *
	 * @return ResolvedConsumer
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function ownToken(array $who, string $publicPem, mixed $record): ResolvedConsumer {
		return $this->fromRecord(
			who: $who,
			authorizationType: TokenIssuer::CLIENT_CREDENTIALS,
			configuration: ['algorithm' => IssuerKeyService::ALGORITHM, 'publicKey' => $publicPem],
			record: $record
		);
	}//end ownToken()
}//end class
