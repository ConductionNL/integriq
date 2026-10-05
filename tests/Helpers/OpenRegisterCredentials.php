<?php

/**
 * OpenRegisterCredentials: integriq's credential bridge over OpenRegister's REAL AuthorizationService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Service\AuthorizationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use ReflectionClass;

/**
 * Builds the bridge the way Nextcloud's container does, with OpenRegister's
 * own AuthorizationService behind it (the installed one under CI's server
 * leg, the byte-for-byte copy under tests/stubs in pure-unit mode). Only the
 * ConsumerMapper is created without its constructor: integriq always passes
 * its own consumer source, so OpenRegister's table is never read.
 *
 * The argument order is the one integriq's retired AuthorizationService took,
 * so a test that built the old service builds this instead.
 */
class OpenRegisterCredentials {

	/**
	 * Build the bridge.
	 *
	 * @param IUserManager  $userManager   Nextcloud users.
	 * @param IUserSession  $userSession   Nextcloud session.
	 * @param ObjectService $objectService OpenRegister objects (integriq's consumers).
	 * @param IGroupManager $groupManager  Nextcloud groups.
	 * @param ICacheFactory $cacheFactory  Cache factory (jti replay store).
	 * @param IRequest      $request       The request.
	 *
	 * @return OpenRegisterCredentialBridge
	 */
	public static function bridge(
		IUserManager $userManager,
		IUserSession $userSession,
		ObjectService $objectService,
		IGroupManager $groupManager,
		ICacheFactory $cacheFactory,
		IRequest $request,
	): OpenRegisterCredentialBridge {
		$consumerMapper = (new ReflectionClass(ConsumerMapper::class))->newInstanceWithoutConstructor();

		return new OpenRegisterCredentialBridge(
			authorization: new AuthorizationService(
				userManager: $userManager,
				userSession: $userSession,
				consumerMapper: $consumerMapper,
				cacheFactory: $cacheFactory,
				groupManager: $groupManager,
				request: $request,
			),
			objectService: $objectService,
			userSession: $userSession,
		);
	}//end bridge()
}//end class
