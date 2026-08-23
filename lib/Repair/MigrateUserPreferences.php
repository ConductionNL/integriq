<?php

/**
 * Integriq Migrate User Preferences Repair Step.
 *
 * Repair step that carries this app's per-user preferences across the
 * `openconnector` -> `integriq` app-id rename.
 *
 * WHY THIS EXISTS SEPARATELY FROM MigrateAppConfigKeys. `IAppConfig` and
 * `IConfig`'s user values are different stores: the former is `oc_appconfig`,
 * the latter `oc_preferences`. Both are namespaced by app id, so both are cut
 * off by the rename, but copying one does nothing for the other.
 *
 * WHAT ACTUALLY LIVES HERE. This app registers no `setUserValue()` call of its
 * own — its per-user state is written through OpenRegister's AppHost
 * {@see \OCA\OpenRegister\AppHost\Controller\GenericPreferencesController},
 * which is bound in {@see \OCA\Integriq\AppInfo\Application} with
 * `appName: Application::APP_ID` precisely so that the `pref_*` user-value
 * namespace stays scoped to this app (see the comment above the
 * `genericPreferences#*` routes in `appinfo/routes.php`). The shared
 * nextcloud-vue widgets read and write it: dismissed dialogs, collapsed
 * panels, per-user list preferences.
 *
 * WHY IT MATTERS MORE THAN IT LOOKS. Every reader of these preferences carries
 * a DEFAULT, so a lost value does not error — it reverts, silently, with no
 * log line to notice. A default-valued read turns missing data into WRONG
 * BEHAVIOUR rather than into an error, which is exactly why this needs a
 * migration rather than a release note. A user who dismissed a dialog gets it
 * back; a user who opted out of something is opted back in.
 *
 * WHY IT ENUMERATES BY USER RATHER THAN BY VALUE.
 * `IConfig::getUsersForUserValue(app, key, value)` needs both the key and the
 * value up front. That is exhaustive only for a closed set of known keys with
 * known values, and this app has neither: the key set is owned by the AppHost
 * engine and by shared Vue widgets that add `pref_*` keys without this app
 * knowing, and the values are open (arbitrary JSON blobs, timestamps, ids). A
 * value-enumerating implementation here would migrate NOTHING WHILE REPORTING
 * SUCCESS. Walking `IUserManager::callForSeenUsers()` and asking
 * `IConfig::getUserKeys()` what each user actually stored is exhaustive by
 * construction, for open and closed value sets alike, and — like
 * MigrateAppConfigKeys' use of `getKeys()` — cannot drift when a future
 * release or a shared widget adds a preference.
 *
 * `callForSeenUsers()` rather than `callForAllUsers()`: a stored preference is
 * written from the app's own UI, which requires a login, so a user with
 * something in `oc_preferences` for this app has necessarily been seen. The
 * seen-user walk reads the same table and avoids a full backend enumeration
 * (LDAP included) on every install.
 *
 * SAFETY. Idempotent and non-destructive, matching MigrateAppConfigKeys:
 *   - a value is copied only when the user has nothing stored under the new
 *     app id, so a preference changed after the rename is never clobbered and
 *     a second run is a no-op;
 *   - the old `openconnector` rows are never deleted, so a rollback still
 *     finds them;
 *   - every failure is logged and the loop continues, because one unreadable
 *     preference is not worth aborting an install over.
 *
 * NO CREDENTIAL MATERIAL PASSES THROUGH HERE. Source secrets live in the
 * OpenRegister credential broker behind a `credentialRef` (ADR-064), never in
 * `oc_preferences`, and the rename does not touch them.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml` alongside MigrateAppConfigKeys — see the ordering comment
 * there.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\AppInfo\Application;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy per-user preferences from the openconnector app id to integriq.
 */
class MigrateUserPreferences implements IRepairStep {
	/**
	 * The preferences namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id — see MigrateAppConfigKeys::OLD_APP_ID.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'openconnector';

	/**
	 * Number of preferences copied during this run.
	 *
	 * Held as state rather than passed around because the walk happens inside
	 * a closure handed to IUserManager::callForSeenUsers(), which returns
	 * nothing and cannot thread a running total back out.
	 *
	 * @var int
	 */
	private int $migrated = 0;

	/**
	 * Number of preferences already present under the new app id.
	 *
	 * @var int
	 */
	private int $alreadyPresent = 0;

	/**
	 * Constructor for MigrateUserPreferences.
	 *
	 * @param IConfig $config The user-value store to read and write
	 * @param IUserManager $userManager The user enumeration backend
	 * @param LoggerInterface $logger Logger for preferences that fail to copy
	 *
	 * @return void
	 */
	public function __construct(
		private IConfig $config,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec exclude One-off openconnector->integriq app-id rename plumbing; it
	 *       returns a human-readable label for `occ maintenance:repair` output
	 *       and adds no behaviour of its own.
	 */
	public function getName(): string {
		return 'Copy Integriq per-user preferences from the openconnector app id';
	}//end getName()

	/**
	 * Copy every stored per-user preference from the old app id to the new one.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec exclude One-off openconnector->integriq app-id rename plumbing: it
	 *       moves oc_preferences rows between namespaces and adds no behaviour
	 *       of its own. The preferences it preserves are specified where they
	 *       are read, not here.
	 */
	public function run(IOutput $output): void {
		$this->migrated = 0;
		$this->alreadyPresent = 0;

		try {
			// The callback returns null rather than void: IUserManager treats a
			// `false` return as "stop iterating", so the contract is
			// Closure(IUser): (bool|null) and null means "keep going".
			$this->userManager->callForSeenUsers(
				function (IUser $user): ?bool {
					$this->migrateUser(userId: $user->getUID());
					return null;
				}
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Integriq: could not enumerate users; per-user preferences were not migrated',
				['exception' => $e->getMessage()]
			);
			$output->warning(
				'MigrateUserPreferences: user enumeration failed; preferences left under the openconnector app id.'
			);
			return;
		}//end try

		if ($this->migrated === 0 && $this->alreadyPresent === 0) {
			$output->info(
				'MigrateUserPreferences: no stored openconnector user preferences on this install; nothing to do.'
			);
			return;
		}

		$output->info(
			'MigrateUserPreferences: migrated ' . $this->migrated . ' preference(s); '
			. $this->alreadyPresent . ' already set under integriq.'
		);
	}//end run()

	/**
	 * Copy one user's stored preferences from the old app id to the new one.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 */
	private function migrateUser(string $userId): void {
		foreach ($this->oldKeysFor(userId: $userId) as $key) {
			/* Both READS sit inside the try along with the write. A read that
			   throws from outside it propagates out of the callForSeenUsers()
			   closure and out of run() — and because this step also runs under
			   <install>, an app whose repair steps abort never enables at all.
			   One unreadable preference is not worth that. */
			try {
				$old = $this->config->getUserValue($userId, self::OLD_APP_ID, $key, '');
				if ($old === '') {
					continue;
				}

				$existing = $this->config->getUserValue($userId, Application::APP_ID, $key, '');
				if ($existing !== '') {
					$this->alreadyPresent++;
					continue;
				}

				$this->config->setUserValue($userId, Application::APP_ID, $key, $old);
				$this->migrated++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Integriq: could not migrate one user preference; leaving it under the old app id',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach
	}//end migrateUser()

	/**
	 * Every preference key this user has stored under the old app id.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return array<int, string> The stored key names, empty when unreadable
	 */
	private function oldKeysFor(string $userId): array {
		try {
			return $this->config->getUserKeys($userId, self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Integriq: could not enumerate openconnector preference keys for a user; skipping that user',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end oldKeysFor()
}//end class
