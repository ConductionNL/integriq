<?php

/**
 * Probe: integriq's credential bridge on an OpenRegister from before #4361.
 *
 * Runs in its own PHP process (the unit bootstrap has already loaded the
 * current OpenRegister classes, and a class cannot be unloaded). It defines
 * OpenRegister's AuthorizationService the way it looked before #4361: the
 * credential checks protected, no ConsumerSource interface. Then it calls
 * every bridge entry point and prints, as JSON, how each one ended.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Consumer
 * @license  EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service {

	/**
	 * OpenRegister's AuthorizationService before #4361 (checks protected).
	 */
	class AuthorizationService {

		/**
		 * The old check: not callable from integriq.
		 *
		 * @param string $authorization Header.
		 *
		 * @return void
		 */
		protected function authorizeJwt(string $authorization): void {
			throw new \LogicException('reached the old protected check');
		}

		/**
		 * The old API-key check: not callable from integriq.
		 *
		 * @param string $header Header.
		 * @param array  $keys   Keys.
		 *
		 * @return void
		 */
		protected function authorizeApiKey(string $header, array $keys): void {
			throw new \LogicException('reached the old protected check');
		}

		/**
		 * Public in the old service too.
		 *
		 * @param array $payload Claims.
		 *
		 * @return void
		 */
		public function validatePayload(array $payload): void {
		}
	}
}

namespace {

	use OCA\Integriq\Exception\AuthenticationException;
	use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
	use OCA\OpenRegister\Service\AuthorizationService;
	use OCA\OpenRegister\Service\ObjectService;
	use OCP\IUser;
	use OCP\IUserSession;

	$root = dirname(__DIR__, 5);
	$autoloader = require $root . '/vendor/autoload.php';
	$autoloader->addPsr4('OCP\\', $root . '/vendor/nextcloud/ocp/OCP/');
	$autoloader->addPsr4('OCA\\Integriq\\', $root . '/lib/');
	require_once $root . '/tests/stubs/OCA/OpenRegister/Db/ObjectEntity.php';
	require_once $root . '/tests/stubs/OCA/OpenRegister/Service/ObjectService.php';

	$session = new class implements IUserSession {
		/** @var array<int, mixed> */
		public array $volatileUsers = [];

		public function login($uid, $password) {
			return false;
		}

		public function logout() {
		}

		public function setUser($user) {
		}

		public function setVolatileActiveUser(?IUser $user): void {
			$this->volatileUsers[] = $user;
		}

		public function getUser() {
			return null;
		}

		public function isLoggedIn() {
			return true;
		}

		public function getImpersonatingUserID(): ?string {
			return null;
		}

		public function setImpersonatingUserID(bool $useCurrentUser = true): void {
		}
	};

	$bridge = new OpenRegisterCredentialBridge(
		authorization: new AuthorizationService(),
		objectService: (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor(),
		userSession: $session,
	);

	$calls = [
		'jwt' => static fn () => $bridge->authorizeJwt('Bearer a.b.c'),
		'apiKey' => static fn () => $bridge->authorizeApiKey('some-key', []),
		'basic' => static fn () => $bridge->authorizeBasic('Basic ' . base64_encode('u:p'), [], []),
		'oauth' => static fn () => $bridge->authorizeOAuth('Bearer x', [], []),
		'ncSession' => static fn () => $bridge->authorizeNcSession([], []),
		'payload' => static fn () => $bridge->validatePayload(['iat' => time()]),
	];

	$outcomes = [];
	foreach ($calls as $name => $call) {
		try {
			$call();
			$outcomes[$name] = 'ADMITTED';
		} catch (AuthenticationException $exception) {
			$outcomes[$name] = ['message' => $exception->getMessage(), 'details' => $exception->getDetails()];
		} catch (\Throwable $exception) {
			$outcomes[$name] = 'ERROR ' . get_class($exception) . ': ' . $exception->getMessage();
		}
	}

	echo json_encode(
		[
			'outcomes' => $outcomes,
			'volatileUsers' => count($session->volatileUsers),
			'consumerSourceLoaded' => class_exists('OCA\\Integriq\\Service\\Consumer\\IntegriqConsumerSource', false),
			'resolvedConsumer' => $bridge->getResolvedConsumer(),
		],
		JSON_THROW_ON_ERROR
	);
}
