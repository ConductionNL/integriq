<?php

/**
 * Integriq OpenRegister autoload prelude
 *
 * Puts OpenRegister's PSR-4 prefix on the autoloader before
 * `Application::register()` references any `OCA\OpenRegister\…` class.
 *
 * @category AppInfo
 * @package  OCA\Integriq\AppInfo
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

namespace OCA\Integriq\AppInfo;

/**
 * Registers OpenRegister's autoload prefix before AppHost is referenced.
 *
 * ## Why this is needed (load-order hazard)
 *
 * `OC_App::getEnabledApps()` does `sort($apps)`, and
 * `Coordinator::registerApps()` walks THAT sorted list registering each app's
 * autoloader and then calling `$app->register()`, one app at a time. So every
 * app's `register()` runs BEFORE the PSR-4 prefix of every alphabetically-LATER
 * app exists. `integriq` sorts before `openregister`, so without this prelude
 * any AppHost reference in `Application::register()` — including a
 * `class_exists()` probe — answers FALSE on a perfectly healthy instance.
 *
 * Lives in its own class rather than inline in `Application::register()` (where
 * it used to be) because `Application` cannot be constructed without a
 * Nextcloud DI container, so an inline prelude is unreachable from a unit test.
 * Here the degraded-path contract — "this NEVER throws, whatever the instance
 * looks like" — is directly assertable, and it is asserted.
 *
 * @spec openspec/specs/apphost-adoption/spec.md
 */
final class OpenRegisterAutoloader {

	/**
	 * The app whose autoload prefix this prelude registers.
	 */
	private const OPENREGISTER_APP_ID = 'openregister';

	/**
	 * The PSR-4 namespace prefix OpenRegister's `lib/` serves.
	 */
	private const OPENREGISTER_NAMESPACE = 'OCA\\OpenRegister\\';

	/**
	 * The registered loader, or null when none is on the SPL chain.
	 *
	 * @var (\Closure(string): void)|null
	 */
	private static ?\Closure $loader = null;

	/**
	 * Register OpenRegister's PSR-4 prefix on the autoloader.
	 *
	 * MUST be called before any `OCA\OpenRegister\…` reference in
	 * `Application::register()`, including a `class_exists()` probe.
	 *
	 * ## Public API only (Nextcloud 35)
	 *
	 * This used to call `\OC_App::registerAutoloading()`. That is private API
	 * and Nextcloud 35 REMOVED it (it moved to the equally private
	 * `OC\App\AppManager::registerAutoloading()`). The `\Error` landed in the
	 * catch around it, and every OpenRegister / AppHost reference in `register()`
	 * then answered FALSE. For an app shipping `vendor/autoload.php` — which OpenRegister
	 * does — Nextcloud's own registration reduces to a PSR-4 prefix over `lib/`,
	 * so that is exactly what is registered here, with `spl_autoload_register()`
	 * and the public `IAppManager`. Same approach as keepiq#712.
	 *
	 * OpenRegister's `vendor/autoload.php` is deliberately NOT required: that
	 * would pull its whole dependency tree into this process, where versions
	 * differing from ours would win first-come.
	 *
	 * Deliberately NOT `IAppManager::loadApp('openregister')`: that marks
	 * OpenRegister loaded and calls `Coordinator::bootApp()`, booting it before
	 * its own `register()` has run.
	 *
	 * Idempotent: a second call while registered is a no-op.
	 *
	 * @param \OCP\App\IAppManager|null $appManager Injected for tests; resolved
	 *                                               from the server when null.
	 *
	 * @return bool True when the prefix is registered, false when OpenRegister
	 *              is absent, disabled, or otherwise unresolvable — in which
	 *              case the caller MUST fall through to its degraded path.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `\OCP\Server::get()` is the public
	 * service locator, and this runs at the composition root where no
	 * container is available to inject from.
	 *
	 * @spec openspec/specs/apphost-adoption/spec.md
	 */
	public static function register(?\OCP\App\IAppManager $appManager=null): bool {
		try {
			$appManager ??= \OCP\Server::get(\OCP\App\IAppManager::class);

			// Checked before the short-circuit: under a long-lived worker this
			// static outlives the request, and an OpenRegister disabled since
			// must not stay wired.
			if ($appManager->isEnabledForAnyone(self::OPENREGISTER_APP_ID) === false) {
				self::unregister();
				return false;
			}

			if (self::$loader !== null) {
				return true;
			}

			$path = rtrim($appManager->getAppPath(self::OPENREGISTER_APP_ID), '/');
			if (is_dir($path.'/lib') === false) {
				return false;
			}

			self::$loader = static function (string $class) use ($path): void {
				$file = self::classFile(appPath: $path, class: $class);
				if ($file !== null && is_file($file) === true) {
					require_once $file;
				}
			};
			spl_autoload_register(self::$loader);

			return true;
		} catch (\Throwable) {
			// OpenRegister absent, or the server container is not up (unit
			// tests). The caller's class_exists() guard then skips the
			// OpenRegister plumbing. Never rethrow: an exception escaping here
			// would abort the caller's entire register(), which is the exact
			// defect this prelude exists to prevent.
			return false;
		}//end try

	}//end register()

	/**
	 * Take the loader off the SPL chain (tests, and OpenRegister disabled).
	 *
	 * @return void
	 */
	public static function unregister(): void {
		if (self::$loader !== null) {
			spl_autoload_unregister(self::$loader);
			self::$loader = null;
		}

	}//end unregister()

	/**
	 * Map an `OCA\OpenRegister\…` class name to its file under `lib/`.
	 *
	 * Returns null for any class that is not OpenRegister's, so this loader
	 * never answers for (and shadows) names another loader owns.
	 *
	 * @param string $appPath Absolute path to the openregister app, no trailing slash.
	 * @param string $class   The fully qualified class name being resolved.
	 *
	 * @return string|null The candidate file, or null when not OpenRegister's.
	 */
	public static function classFile(string $appPath, string $class): ?string {
		if (str_starts_with($class, self::OPENREGISTER_NAMESPACE) === false) {
			return null;
		}

		$relative = substr($class, strlen(self::OPENREGISTER_NAMESPACE));
		if ($relative === '') {
			return null;
		}

		return $appPath.'/lib/'.str_replace('\\', '/', $relative).'.php';

	}//end classFile()
}//end class
