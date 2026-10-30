<?php

/**
 * The OpenRegister credential checks integriq delegates to.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Consumer;

use OCA\OpenRegister\Service\Consumer\ConsumerSource;

/**
 * Decides whether the running OpenRegister offers the public credential
 * checks. The credential bridge refuses calls on it and the setup check
 * reports it, so an admin sees the same verdict a refused call would give.
 */
final class OpenRegisterEntryPoints {

	/**
	 * The entry points integriq needs; an OpenRegister lacking one is refused.
	 */
	public const REQUIRED = [
		'authorizeJwt',
		'authorizeApiKey',
		'authorizeBasic',
		'authorizeOAuth',
		'authorizeNcSession',
		'validatePayload',
		'getResolvedConsumer',
	];

	/**
	 * Whether the running OpenRegister offers the public credential checks.
	 *
	 * @param object $authorization OpenRegister's AuthorizationService (any version).
	 *
	 * @return bool True when every required entry point is callable and the ConsumerSource interface exists.
	 *
	 * @spec openspec/changes/consumer-auth-on-openregister/specs/authorization-jwt/spec.md#requirement-integriq-consumers-are-checked-by-openregister-req-006
	 */
	public static function available(object $authorization): bool {
		$available = interface_exists(ConsumerSource::class);
		foreach (self::REQUIRED as $method) {
			$available = ($available === true && is_callable([$authorization, $method]) === true);
		}

		return $available;
	}//end available()
}//end class
