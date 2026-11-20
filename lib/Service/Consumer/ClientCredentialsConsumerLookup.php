<?php

/**
 * ClientCredentialsConsumerLookup: the client credentials consumer a token of
 * integriq's own issuer names as its subject (access-oauth-and-token-validation).
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

use Exception;
use OCA\Integriq\Service\OAuth\TokenIssuer;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Finds a client credentials consumer by id.
 *
 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
 */
class ClientCredentialsConsumerLookup {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Reads the consumer.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The client credentials consumer a token of integriq's own issuer names as its subject.
	 *
	 * @param mixed $subject The token's `sub` claim.
	 *
	 * @return ObjectEntity|null The consumer, or null when the subject names no client credentials consumer.
	 *
	 * @spec openspec/changes/access-oauth-and-token-validation/specs/authorization-jwt/spec.md#requirement-integriq-issues-client-credentials-tokens-to-consumers-req-tokv-003
	 */
	public function find(mixed $subject): ?ObjectEntity {
		if (is_string($subject) === false || $subject === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(
				id: $subject,
				options: new ObjectFindOptions(register: 'integriq', schema: 'consumer'),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Exception) {
			// OpenRegister throws when no object has this id: not a known consumer.
			return null;
		}

		if (($object instanceof ObjectEntity) === false
			|| strtolower((string)($object->getObject()['authorizationType'] ?? null)) !== TokenIssuer::CLIENT_CREDENTIALS
		) {
			return null;
		}

		return $object;
	}//end find()
}//end class
