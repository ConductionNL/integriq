<?php

/**
 * Registers the Catalogi facade's services with their seams wired.
 *
 * Without this the container would autowire the handlers' callables with
 * nothing to call. Every seam is answered by {@see CatalogiOpenRegisterAccess}.
 * Kept out of the service bindings so the registration and its test sit side
 * by side, as {@see \OCA\Integriq\Service\Objecten\ObjectenWiring} does.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalogi
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
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Catalogi;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * The facade's service registrations.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */
class CatalogiWiring {

	/**
	 * Register one factory per facade service.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerService(
			CatalogiGate::class,
			static fn (ContainerInterface $c): CatalogiGate => new CatalogiGate(
				bindingReader: self::access(container: $c)->binding(...),
				tokens: $c->get(CatalogiTokenService::class)
			)
		);
		$context->registerService(
			InformatieobjecttypeEndpointHandler::class,
			static fn (ContainerInterface $c): InformatieobjecttypeEndpointHandler => new InformatieobjecttypeEndpointHandler(
				gate: $c->get(CatalogiGate::class),
				typesReader: self::access(container: $c)->types(...)
			)
		);
		$context->registerService(
			WooStarterSet::class,
			static fn (ContainerInterface $c): WooStarterSet => new WooStarterSet(
				bindingReader: self::access(container: $c)->binding(...),
				typesReader: self::access(container: $c)->types(...),
				creator: self::access(container: $c)->createType(...),
				categoriesReader: self::access(container: $c)->wooCategories(...)
			)
		);
	}//end register()

	/**
	 * The shared OpenRegister access.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return CatalogiOpenRegisterAccess
	 */
	private static function access(ContainerInterface $container): CatalogiOpenRegisterAccess {
		return $container->get(CatalogiOpenRegisterAccess::class);
	}//end access()

}//end class
