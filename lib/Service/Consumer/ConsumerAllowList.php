<?php

/**
 * Integriq ConsumerAllowList: whether a resolved consumer is one an endpoint's
 * rule names, by name or id.
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

use OCA\Integriq\Exception\AuthenticationException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Checks a consumer against a rule's list of allowed consumers.
 *
 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
 */
class ConsumerAllowList {

	/**
	 * Refuse a consumer the endpoint does not allow; an empty list allows any.
	 *
	 * @param ObjectEntity $consumer  The certificate's consumer.
	 * @param array        $consumers Consumers allowed on the endpoint, by name or uuid.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException When the consumer is not on a non-empty list.
	 *
	 * @spec openspec/changes/access-consumer-credentials/specs/authorization-jwt/spec.md#requirement-an-endpoint-can-require-a-client-certificate-req-cred-004
	 */
	public function assertAllowed(ObjectEntity $consumer, array $consumers): void {
		$data    = $consumer->getObject();
		$allowed = array_values(array_filter($consumers, static fn ($entry): bool => is_string($entry) === true && $entry !== ''));
		if ($allowed !== [] && self::isNamedIn(consumer: $consumer, data: $data, allowed: $allowed) === false) {
			throw new AuthenticationException(
				message: 'The consumer of this client certificate may not call this endpoint',
				details: ['consumer' => (string)($data['name'] ?? '')]
			);
		}
	}//end assertAllowed()

	/**
	 * Whether the consumer's name or id is on the list.
	 *
	 * @param ObjectEntity         $consumer The consumer.
	 * @param array<string, mixed> $data     Its data.
	 * @param list<string>         $allowed  The allowed names and uuids.
	 *
	 * @return bool
	 */
	private static function isNamedIn(ObjectEntity $consumer, array $data, array $allowed): bool {
		return in_array(($data['name'] ?? null), $allowed, true) === true
			|| in_array(($data['id'] ?? $consumer->getUuid()), $allowed, true) === true;
	}//end isNamedIn()
}//end class
