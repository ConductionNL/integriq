<?php

/**
 * Registers the Objecten facade's services with their seams wired.
 *
 * Without this, the container autowires every facade service with its
 * callables left at null: no token is loaded, so every route answers 401, and
 * a handler with no reader answers an empty list. Each service here is built by
 * {@see OpenRegisterObjectenGateway}, which answers every seam and loads the
 * declared objecttypes and tokens.
 *
 * Kept out of Application so the registration and its test sit side by side.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * The facade's service registrations.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
class ObjectenWiring {

	/**
	 * Register one factory per facade service.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerService(
			ObjecttypeRegistry::class,
			static fn (ContainerInterface $c): ObjecttypeRegistry => self::gateway(container: $c)->registry()
		);
		$context->registerService(
			ObjectenTokenService::class,
			static fn (ContainerInterface $c): ObjectenTokenService => self::gateway(container: $c)->tokens()
		);
		$context->registerService(
			ObjecttypeEndpointHandler::class,
			static fn (ContainerInterface $c): ObjecttypeEndpointHandler => self::gateway(container: $c)->types()
		);
		$context->registerService(
			ObjectEndpointHandler::class,
			static fn (ContainerInterface $c): ObjectEndpointHandler => self::gateway(container: $c)->objects()
		);
		$context->registerService(
			ObjectWriteHandler::class,
			static fn (ContainerInterface $c): ObjectWriteHandler => self::gateway(container: $c)->writes()
		);
	}//end register()

	/**
	 * The shared gateway.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return OpenRegisterObjectenGateway The gateway.
	 */
	private static function gateway(ContainerInterface $container): OpenRegisterObjectenGateway {
		return $container->get(OpenRegisterObjectenGateway::class);
	}//end gateway()
}//end class
