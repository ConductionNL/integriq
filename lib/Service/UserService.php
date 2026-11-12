<?php

/**
 * UserService
 *
 * This service handles all user-related business logic including user data
 * retrieval, updates, and profile management. It centralizes user operations
 * and provides a clean interface for controllers and other services.
 *
 * @category Service
 * @package  Integriq
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version 1.0.0
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Service class for handling user-related operations
 *
 * This service provides methods for retrieving and updating user information,
 * including standard NextCloud user properties and custom profile fields.
 * It abstracts the complexity of working with different user data sources
 * and provides a consistent interface for user operations.
 *
 * @psalm-suppress UnusedClass
 */
class UserService implements UserServiceInterface {

	/**
	 * The permission-guarded user properties the API may update, in this order: the
	 * request key, the backend permission method and the setter. canChangeMailAddress()
	 * is not part of IUser, so only backends that implement it are asked.
	 *
	 * @var array<string, array{permission: string, setter: string}>
	 */
	private const STANDARD_PROPERTY_SETTERS = [
		'displayName' => ['permission' => 'canChangeDisplayName', 'setter' => 'setDisplayName'],
		'email' => ['permission' => 'canChangeMailAddress', 'setter' => 'setEMailAddress'],
		'password' => ['permission' => 'canChangePassword', 'setter' => 'setPassword'],
	];

	/**
	 * The standard profile fields the API may update via the AccountManager:
	 * the request key and the account property.
	 *
	 * @var array<string, string>
	 */
	private const ACCOUNT_PROFILE_FIELDS = [
		'phone' => IAccountManager::PROPERTY_PHONE,
		'address' => IAccountManager::PROPERTY_ADDRESS,
		'website' => IAccountManager::PROPERTY_WEBSITE,
		'fediverse' => IAccountManager::PROPERTY_FEDIVERSE,
		'organisation' => IAccountManager::PROPERTY_ORGANISATION,
		'role' => IAccountManager::PROPERTY_ROLE,
		'headline' => IAccountManager::PROPERTY_HEADLINE,
		'biography' => IAccountManager::PROPERTY_BIOGRAPHY,
	];
	/**
	 * UserService constructor
	 *
	 * @param IUserSession $userSession The user session service
	 * @param IConfig $config The configuration service
	 * @param IGroupManager $groupManager The group manager service
	 * @param IAccountManager $accountManager The account manager service
	 * @param LoggerInterface $logger The logger interface
	 * @param OrganisationBridgeService $organisationBridgeService The organization bridge service
	 * @param IDBConnection $db The database connection used for the memory-safe quota lookup
	 * @param IFactory $l10nFactory The l10n factory used to resolve a fallback language
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IConfig $config,
		private readonly IGroupManager $groupManager,
		private readonly IAccountManager $accountManager,
		private readonly LoggerInterface $logger,
		private readonly OrganisationBridgeService $organisationBridgeService,
		private readonly IDBConnection $db,
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * Get current authenticated user
	 *
	 * This method retrieves the currently authenticated user from the session
	 * and returns null if no user is authenticated.
	 *
	 * @return IUser|null The current user or null if not authenticated
	 *
	 * @psalm-return   IUser|null
	 * @phpstan-return IUser|null
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function getCurrentUser(): ?IUser {
		return $this->userSession->getUser();
	}//end getCurrentUser()

	/**
	 * Build comprehensive user data array
	 *
	 * This method constructs a complete user data array including all standard
	 * NextCloud user properties, custom profile fields, quota information,
	 * group memberships, and additional profile data from AccountManager.
	 *
	 * @param IUser $user The user object to build data for
	 *
	 * @return array The comprehensive user data array
	 *
	 * @psalm-param    IUser $user
	 * @psalm-return   array
	 * @phpstan-param  IUser $user
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function buildUserDataArray(IUser $user): array {
		// Get user groups from group manager.
		$userGroups = $this->groupManager->getUserGroups($user);
		$groupNames = array_values(array_map(fn ($group) => $group->getGID(), $userGroups));

		// Build quota information safely.
		$quota = $this->buildQuotaInformation(user: $user);

		// Get language and locale with proper fallbacks.
		[$language, $locale] = $this->getLanguageAndLocale(user: $user);

		// Get additional profile information from AccountManager and custom fields.
		$additionalInfo = $this->getAdditionalProfileInfo(user: $user);

		// Build comprehensive user data array with all available information.
		// Subadmin info would require an additional service, so it is left empty.
		$result = [
			'uid' => $user->getUID(),
			'displayName' => $user->getDisplayName(),
			'email' => $user->getEMailAddress(),
			'emailVerified' => $this->emailVerifiedOf(user: $user),
			'enabled' => $user->isEnabled(),
			'quota' => $quota,
			'avatarScope' => $this->avatarScopeOf(user: $user),
			'lastLogin' => $user->getLastLogin(),
			'backend' => $user->getBackendClassName(),
			'subadmin' => [],
			'groups' => $groupNames,
			'language' => $language,
			'locale' => $locale,
			'backendCapabilities' => $this->backendCapabilitiesOf(user: $user),
		];

		return $this->withProfileAndOrganisations(result: $result, additionalInfo: $additionalInfo);
	}//end buildUserDataArray()

	/**
	 * Whether the user's email address is verified, when the backend says.
	 *
	 * Nextcloud backends differ in which IUser methods they implement, so this
	 * one is feature-detected: it is absent from the interface.
	 *
	 * @param IUser $user The user object
	 *
	 * @return mixed The backend's answer, or null when it has none.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function emailVerifiedOf(IUser $user): mixed {
		if (method_exists($user, 'getEmailVerified') === true) {
			return $user->getEmailVerified();
		}

		return null;
	}//end emailVerifiedOf()

	/**
	 * The user's avatar scope, feature-detected like emailVerifiedOf().
	 *
	 * @param IUser $user The user object
	 *
	 * @return mixed The backend's answer, or `contacts` when it has none.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function avatarScopeOf(IUser $user): mixed {
		if (method_exists($user, 'getAvatarScope') === true) {
			return $user->getAvatarScope();
		}

		return 'contacts';
	}//end avatarScopeOf()

	/**
	 * What the user's backend lets them change.
	 *
	 * OCP\IUser declares getLastLogin(), getBackendClassName(),
	 * canChangeDisplayName(), canChangePassword() and canChangeAvatar(), so
	 * the method_exists() probes that used to wrap these could never be
	 * false and their else-branch defaults were unreachable. The two probes
	 * that remain (getAvatarScope in avatarScopeOf(), canChangeMailAddress
	 * here) are NOT decoration: those methods are absent from the interface
	 * and only present on some implementations.
	 *
	 * @param IUser $user The user object
	 *
	 * @return array<string, mixed> The backend capabilities.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function backendCapabilitiesOf(IUser $user): array {
		$canChangeMailAddress = false;
		if (method_exists($user, 'canChangeMailAddress') === true) {
			$canChangeMailAddress = $user->canChangeMailAddress();
		}

		return [
			'displayName' => $user->canChangeDisplayName(),
			'email' => $canChangeMailAddress,
			'password' => $user->canChangePassword(),
			'avatar' => $user->canChangeAvatar(),
		];
	}//end backendCapabilitiesOf()

	/**
	 * Merge the profile information in, make sure the name fields are present
	 * and add the user's organisations.
	 *
	 * @param array<string, mixed> $result         The user data so far.
	 * @param array<string, mixed> $additionalInfo The additional profile information.
	 *
	 * @return array<string, mixed> The complete user data.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function withProfileAndOrganisations(array $result, array $additionalInfo): array {
		// Merge additional profile information with result.
		$result = array_merge($result, $additionalInfo);

		// Ensure name fields are always present (even if null).
		$result['firstName'] = $result['firstName'] ?? null;
		$result['lastName'] = $result['lastName'] ?? null;
		$result['middleName'] = $result['middleName'] ?? null;

		// Add organization information if available.
		// Guard against the OpenRegister-side OrganisationService being absent on
		// single-tenant instances — the bridge service exposes
		// isOrganisationServiceAvailable() exactly for this gating call.
		$result['organisations'] = [];
		if ($this->organisationBridgeService->isOrganisationServiceAvailable() === true) {
			$result['organisations'] = $this->organisationBridgeService->getUserOrganisationStats();
		}

		return $result;
	}//end withProfileAndOrganisations()

	/**
	 * Update user properties based on provided data
	 *
	 * This method safely updates user properties that are allowed to be modified
	 * through the API, including standard user properties and custom profile fields.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return array Result of the update operation including organization changes
	 *
	 * @psalm-param    IUser $user
	 * @psalm-param    array $data
	 * @psalm-return   array
	 * @phpstan-param  IUser $user
	 * @phpstan-param  array $data
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function updateUserProperties(IUser $user, array $data): array {
		$result = [
			'success' => true,
			'message' => 'User properties updated successfully',
			'organisation_updated' => false,
		];

		// Handle organization switching if requested.
		if (isset($data['activeOrganisation']) === true && is_string($data['activeOrganisation']) === true) {
			$organisationResult = $this->organisationBridgeService->setActiveOrganisation($data['activeOrganisation']);
			$result['organisation_updated'] = $organisationResult['success'];
			$result['organisation_message'] = $organisationResult['message'];

			// Remove the organization field from data to prevent it from being processed as a user property.
			unset($data['activeOrganisation']);
		}

		// Update standard user properties.
		$this->updateStandardUserProperties(user: $user, data: $data);

		// Update profile fields via AccountManager and custom fields.
		$this->updateProfileProperties(user: $user, data: $data);

		return $result;
	}//end updateUserProperties()

	/**
	 * Get custom name fields for a user (accessible to other apps)
	 *
	 * This method retrieves the custom name fields (firstName, lastName, middleName)
	 * for a given user. These fields are stored in the 'core' namespace making them
	 * accessible to other NextCloud apps.
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Array containing name fields
	 *
	 * @psalm-param    IUser $user
	 * @psalm-return   array{firstName: string|null, lastName: string|null, middleName: string|null}
	 * @phpstan-param  IUser $user
	 * @phpstan-return array{firstName: string|null, lastName: string|null, middleName: string|null}
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function getCustomNameFields(IUser $user): array {
		$userId = $user->getUID();

		$firstName = $this->config->getUserValue($userId, 'core', 'firstName', '');
		$lastName = $this->config->getUserValue($userId, 'core', 'lastName', '');
		$middleName = $this->config->getUserValue($userId, 'core', 'middleName', '');

		if ($firstName === '') {
			$firstName = null;
		}

		if ($lastName === '') {
			$lastName = null;
		}

		if ($middleName === '') {
			$middleName = null;
		}

		return [
			'firstName' => $firstName,
			'lastName' => $lastName,
			'middleName' => $middleName,
		];
	}//end getCustomNameFields()

	/**
	 * Set custom name fields for a user (accessible to other apps)
	 *
	 * This method stores the custom name fields in the 'core' namespace making them
	 * accessible to other NextCloud apps while maintaining consistency.
	 *
	 * @param IUser $user The user object
	 * @param array $nameFields Array containing name field values
	 *
	 * @return void
	 *
	 * @psalm-param    IUser $user
	 * @psalm-param    array $nameFields
	 * @psalm-return   void
	 * @phpstan-param  IUser $user
	 * @phpstan-param  array $nameFields
	 * @phpstan-return void
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	public function setCustomNameFields(IUser $user, array $nameFields): void {
		$userId = $user->getUID();
		$allowedFields = ['firstName', 'lastName', 'middleName'];

		foreach ($allowedFields as $field) {
			if (isset($nameFields[$field]) === true) {
				$value = (string)$nameFields[$field];
				$this->config->setUserValue($userId, 'core', $field, $value);
			}
		}
	}//end setCustomNameFields()

	/**
	 * Build quota information for a user
	 *
	 * This method safely builds quota information including used space, free space,
	 * total quota, and relative usage percentage with proper fallbacks.
	 *
	 * MEMORY OPTIMIZATION: This method now uses NextCloud's built-in quota calculation
	 * methods instead of recursively calculating folder sizes to prevent memory issues.
	 *
	 * @param IUser $user The user object
	 *
	 * @return array The quota information array
	 *
	 * @psalm-param    IUser $user
	 * @psalm-return   array
	 * @phpstan-param  IUser $user
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function buildQuotaInformation(IUser $user): array {
		try {
			// OCP\IUser declares getQuota(), so the 'none' default was dead.
			$userQuota = $user->getQuota();
			$usedSpace = $this->usedSpaceOf(user: $user);

			return [
				'free' => $userQuota,
				'used' => $usedSpace,
				'total' => $userQuota,
				'relative' => $this->relativeUse(quota: $userQuota, usedSpace: $usedSpace),
			];
		} catch (\Exception $e) {
			// Log error and return fallback quota information.
			$this->logger->warning(
				'Failed to build quota information for user: ' . $user->getUID(),
				[
					'exception' => $e->getMessage(),
				]
			);

			return [
				'free' => 'none',
				'used' => 0,
				'total' => 'none',
				'relative' => 0,
			];
		}//end try
	}//end buildQuotaInformation()

	/**
	 * The space the user uses: from the user object when its backend counts
	 * it, else (or when counting fails) the memory-safe lookup.
	 *
	 * MEMORY FIX: Use NextCloud's built-in quota system instead of recursive folder size calculation.
	 * This prevents memory exhaustion when users have large amounts of data.
	 *
	 * @param IUser $user The user object
	 *
	 * @return mixed The used space in bytes.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function usedSpaceOf(IUser $user): mixed {
		$userId = $user->getUID();

		// Try to get used space from NextCloud's user object first.
		try {
			// Fallback 1: Try user object method if available.
			if (method_exists($user, 'getUsedSpace') === true) {
				return $user->getUsedSpace();
			}

			// Fallback 2: Use a memory-safe approach with timeout protection.
			return $this->getUsedSpaceMemorySafe(userId: $userId);
		} catch (\Exception $quotaException) {
			// If quota calculation fails, use memory-safe approach.
			$this->logger->debug(
				'User quota calculation failed for user: ' . $userId,
				[
					'exception' => $quotaException->getMessage(),
				]
			);

			return $this->getUsedSpaceMemorySafe(userId: $userId);
		}
	}//end usedSpaceOf()

	/**
	 * The used space as a percentage of a numeric, non-zero quota; 0 otherwise.
	 *
	 * @param mixed $quota     The user's quota.
	 * @param mixed $usedSpace The used space in bytes.
	 *
	 * @return int|float The percentage, rounded to two decimals.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function relativeUse(mixed $quota, mixed $usedSpace): int|float {
		if ($quota === 'none' || $quota === 'unlimited' || is_numeric($quota) === false) {
			return 0;
		}

		$totalBytes = (int)$quota;
		if ($totalBytes <= 0) {
			return 0;
		}

		return round((($usedSpace / $totalBytes) * 100), 2);
	}//end relativeUse()

	/**
	 * Get used space in a memory-safe way
	 *
	 * This method attempts to get used space without causing memory exhaustion
	 * by using database queries or cache lookups instead of recursive folder scanning.
	 *
	 * @param string $userId The user ID
	 *
	 * @return int The used space in bytes or 0 if cannot be determined safely
	 *
	 * @psalm-param    string $userId
	 * @psalm-return   int
	 * @phpstan-param  string $userId
	 * @phpstan-return int
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function getUsedSpaceMemorySafe(string $userId): int {
		try {
			if ($this->memoryTooHighForQuota(userId: $userId) === true) {
				return 0;
			}

			$size = $this->storedStorageSize(userId: $userId);
			if ($size !== null) {
				return $size;
			}

			// If database lookup fails, return 0 to prevent memory issues.
			// The quota display will show 0 used space rather than causing OOM.
			$this->logger->info('Using fallback quota calculation for user: ' . $userId);
			return 0;
		} catch (\Exception $e) {
			// Log error and return 0 to prevent memory issues.
			$this->logger->warning(
				'Memory-safe quota calculation failed for user: ' . $userId,
				[
					'exception' => $e->getMessage(),
				]
			);
			return 0;
		}//end try
	}//end getUsedSpaceMemorySafe()

	/**
	 * Whether this process already uses too much memory to compute a quota.
	 *
	 * @param string $userId The user ID, for the warning.
	 *
	 * @return bool True above the 128MB threshold, after logging it.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function memoryTooHighForQuota(string $userId): bool {
		// Check current memory usage for safety.
		$currentMemoryUsage = memory_get_usage(true);

		// If we're already using too much memory, return 0 to prevent OOM.
		if ($currentMemoryUsage > 128 * 1024 * 1024) {
			// 128MB threshold.
			$this->logger->warning(
				'Memory usage too high for quota calculation',
				[
					'user' => $userId,
					'memory_usage' => $currentMemoryUsage,
				]
			);
			return true;
		}

		return false;
	}//end memoryTooHighForQuota()

	/**
	 * The storage size NextCloud has cached for the user's mount, when numeric.
	 *
	 * @param string $userId The user ID.
	 *
	 * @return int|null The size in bytes, or null when there is no usable row.
	 *
	 * @throws \Exception When the query fails.
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function storedStorageSize(string $userId): ?int {
		// Try to get size from database if available (NextCloud stores this).
		$query = $this->db->getQueryBuilder();

		// Check if NextCloud has cached storage stats.
		$query->select('size')
			->from('storages')
			->join('storages', 'mounts', 'm', 'storages.id = m.storage_id')
			->where($query->expr()->eq('m.user_id', $query->createNamedParameter($userId)))
			->setMaxResults(1);

		$result = $query->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row !== false && isset($row['size']) === true && is_numeric($row['size']) === true) {
			return (int)$row['size'];
		}

		return null;
	}//end storedStorageSize()

	/**
	 * Get language and locale with proper fallbacks
	 *
	 * This method retrieves the user's language and locale settings with
	 * intelligent fallbacks to system defaults when user preferences are not set.
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Array containing language and locale
	 *
	 * @psalm-param    IUser $user
	 * @psalm-return   array{0: string, 1: string}
	 * @phpstan-param  IUser $user
	 * @phpstan-return array{0: string, 1: string}
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function getLanguageAndLocale(IUser $user): array {
		$language = $this->userLanguage(user: $user);

		return [$language, $this->userLocale(user: $user, language: $language)];
	}//end getLanguageAndLocale()

	/**
	 * The user's language, or the browser/system default when it is empty;
	 * '' for a user without a language.
	 *
	 * @param IUser $user The user object.
	 *
	 * @return mixed The language.
	 */
	private function userLanguage(IUser $user): mixed {
		if (method_exists($user, 'getLanguage') === false) {
			return '';
		}

		$language = $user->getLanguage();
		if (empty($language) === true) {
			return $this->l10nFactory->findLanguage();
		}

		return $language;
	}//end userLanguage()

	/**
	 * The user's locale, or one derived from the language when it is empty;
	 * '' for a user without a locale.
	 *
	 * @param IUser $user     The user object.
	 * @param mixed $language The resolved language.
	 *
	 * @return mixed The locale.
	 */
	private function userLocale(IUser $user, mixed $language): mixed {
		if (method_exists($user, 'getLocale') === false) {
			return '';
		}

		$locale = $user->getLocale();
		if (empty($locale) === false || empty($language) === true) {
			return $locale;
		}

		return $this->localeForLanguage(language: $language);
	}//end userLocale()

	/**
	 * The default locale of a language: en_US for English, xx_XX otherwise.
	 *
	 * @param string $language The language.
	 *
	 * @return string The locale.
	 */
	private function localeForLanguage(string $language): string {
		if ($language === 'en') {
			return 'en_US';
		}

		return $language . '_' . strtoupper($language);
	}//end localeForLanguage()

	/**
	 * Get additional profile information from various sources
	 *
	 * This method retrieves profile information from AccountManager and custom
	 * field storage, with proper fallbacks and error handling.
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Additional profile information
	 *
	 * @psalm-param    IUser $user
	 * @psalm-return   array
	 * @phpstan-param  IUser $user
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function getAdditionalProfileInfo(IUser $user): array {
		$additionalInfo = [];

		try {
			// MEMORY FIX: Use selective property loading instead of getAllProperties().
			// This prevents loading unnecessary data and reduces memory usage.
			$additionalInfo = $this->getAccountManagerPropertiesSelectively(user: $user);
		} catch (\Exception $e) {
			// If AccountManager fails, try to get some info from user config.
			$this->logger->warning(
				'AccountManager failed for user: ' . $user->getUID(),
				[
					'exception' => $e->getMessage(),
				]
			);

			$userId = $user->getUID();

			// Try to get some basic profile info from user preferences.
			$phone = $this->config->getUserValue($userId, 'settings', 'phone', '');
			if (empty($phone) === false) {
				$additionalInfo['phone'] = $phone;
			}

			$website = $this->config->getUserValue($userId, 'settings', 'website', '');
			if (empty($website) === false) {
				$additionalInfo['website'] = $website;
			}
		}//end try

		// Always get custom name fields from core namespace (accessible to other apps).
		$customNameFields = $this->getCustomNameFields(user: $user);
		$additionalInfo = array_merge($additionalInfo, $customNameFields);

		// Get organization UUID from core namespace (set by SoftwareCatalog).
		$userId = $user->getUID();
		$organizationUuid = $this->config->getUserValue($userId, 'core', 'organisation', '');
		if (empty($organizationUuid) === false) {
			$additionalInfo['organisation'] = $organizationUuid;
		}

		return $additionalInfo;
	}//end getAdditionalProfileInfo()

	/**
	 * Get AccountManager properties selectively to reduce memory usage
	 *
	 * This method loads only the specific properties we need instead of all properties,
	 * which significantly reduces memory usage when the user has many account properties.
	 *
	 * @param IUser $user The user object
	 *
	 * @return array Profile information from AccountManager
	 *
	 * @psalm-param    IUser $user
	 * @psalm-return   array
	 * @phpstan-param  IUser $user
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function getAccountManagerPropertiesSelectively(IUser $user): array {
		$additionalInfo = [];

		// Get user account data from AccountManager.
		$account = $this->accountManager->getAccount($user);

		// Define the properties we actually need (selective loading).
		$neededProperties = [
			IAccountManager::PROPERTY_PHONE => 'phone',
			IAccountManager::PROPERTY_ADDRESS => 'address',
			IAccountManager::PROPERTY_WEBSITE => 'website',
			IAccountManager::PROPERTY_FEDIVERSE => 'fediverse',
			IAccountManager::PROPERTY_ORGANISATION => 'organisation',
			IAccountManager::PROPERTY_ROLE => 'role',
			IAccountManager::PROPERTY_HEADLINE => 'headline',
			IAccountManager::PROPERTY_BIOGRAPHY => 'biography',
		];

		// Load only the properties we need.
		foreach ($neededProperties as $propertyName => $apiField) {
			try {
				// No `!== null` check: getProperty() throws rather than returning
				// null, and the surrounding catch already handles that.
				$value = $account->getProperty($propertyName)->getValue();
				if (empty($value) === false) {
					$additionalInfo[$apiField] = $value;
				}
			} catch (\Exception $e) {
				// If a specific property fails, log it but continue with others.
				$this->logger->debug(
					'Failed to load account property: ' . $propertyName,
					[
						'user' => $user->getUID(),
						'exception' => $e->getMessage(),
					]
				);
			}
		}

		return $additionalInfo;
	}//end getAccountManagerPropertiesSelectively()

	/**
	 * Update standard user properties
	 *
	 * This method updates the standard NextCloud user properties like display name,
	 * email, password, language, and locale based on backend capabilities.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 *
	 * @psalm-param    IUser $user
	 * @psalm-param    array $data
	 * @psalm-return   void
	 * @phpstan-param  IUser $user
	 * @phpstan-param  array $data
	 * @phpstan-return void
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function updateStandardUserProperties(IUser $user, array $data): void {
		foreach (self::STANDARD_PROPERTY_SETTERS as $key => $methods) {
			if (isset($data[$key]) === true && $this->mayChange(user: $user, permission: $methods['permission']) === true) {
				$setter = $methods['setter'];
				$user->$setter($data[$key]);
			}
		}

		$this->updateLanguageAndLocale(user: $user, data: $data);
	}//end updateStandardUserProperties()

	/**
	 * Whether the user's backend implements this permission method and it answers true.
	 *
	 * @param IUser  $user       The user object to update.
	 * @param string $permission The permission method, such as `canChangeDisplayName`.
	 *
	 * @return bool
	 */
	private function mayChange(IUser $user, string $permission): bool {
		return method_exists($user, $permission) === true && $user->$permission() === true;
	}//end mayChange()

	/**
	 * Update the language and the locale, where the backend can store them.
	 *
	 * @param IUser $user The user object to update.
	 * @param array $data The data array containing updates.
	 *
	 * @return void
	 */
	private function updateLanguageAndLocale(IUser $user, array $data): void {
		// Update language if provided.
		if (isset($data['language']) === true && method_exists($user, 'setLanguage') === true) {
			$user->setLanguage($data['language']);
		}

		// Update locale if provided.
		if (isset($data['locale']) === true && method_exists($user, 'setLocale') === true) {
			$user->setLocale($data['locale']);
		}
	}//end updateLanguageAndLocale()

	/**
	 * Update profile properties via AccountManager and custom fields
	 *
	 * This method updates profile properties stored in AccountManager and
	 * custom name fields stored in user configuration.
	 *
	 * @param IUser $user The user object to update
	 * @param array $data The data array containing updates
	 *
	 * @return void
	 *
	 * @psalm-param    IUser $user
	 * @psalm-param    array $data
	 * @psalm-return   void
	 * @phpstan-param  IUser $user
	 * @phpstan-param  array $data
	 * @phpstan-return void
	 *
	 * @spec openspec/specs/user-management-and-login/spec.md
	 */
	private function updateProfileProperties(IUser $user, array $data): void {
		try {
			$this->updateAccountProperties(user: $user, data: $data);
		} catch (\Exception $e) {
			// Log error but don't fail the entire update.
			$this->logAccountFailure(user: $user, exception: $e);
		}

		// Handle custom name fields separately (accessible to other apps via 'core' namespace).
		$nameFields = $this->customNameFieldsIn(data: $data);
		if (empty($nameFields) === false) {
			$this->setCustomNameFields(user: $user, nameFields: $nameFields);
		}
	}//end updateProfileProperties()

	/**
	 * Update the standard profile fields held by the AccountManager and save
	 * the account once when any of them changed.
	 *
	 * @param IUser $user The user object to update.
	 * @param array $data The data array containing updates.
	 *
	 * @return void
	 */
	private function updateAccountProperties(IUser $user, array $data): void {
		$account = $this->accountManager->getAccount($user);
		if ($this->applyAccountChanges(account: $account, data: $data) === true) {
			$this->accountManager->updateAccount($account);
		}
	}//end updateAccountProperties()

	/**
	 * Set every standard profile field the data carries on the account.
	 *
	 * @param IAccount $account The user's account.
	 * @param array    $data    The data array containing updates.
	 *
	 * @return bool True when any property changed.
	 */
	private function applyAccountChanges(IAccount $account, array $data): bool {
		$accountUpdated = false;
		foreach (self::ACCOUNT_PROFILE_FIELDS as $apiField => $accountProperty) {
			if (isset($data[$apiField]) === true
				&& $this->setAccountProperty(value: (string)$data[$apiField], property: $account->getProperty($accountProperty)) === true
			) {
				$accountUpdated = true;
			}
		}

		return $accountUpdated;
	}//end applyAccountChanges()

	/**
	 * Set one account property when its value differs.
	 *
	 * @param string           $value    The new value.
	 * @param IAccountProperty $property The property (getProperty() always returns non-null).
	 *
	 * @return bool True when the value changed.
	 */
	private function setAccountProperty(string $value, IAccountProperty $property): bool {
		if ($property->getValue() === $value) {
			return false;
		}

		$property->setValue($value);

		return true;
	}//end setAccountProperty()

	/**
	 * Log an AccountManager failure without failing the whole update.
	 *
	 * @param IUser      $user      The user object.
	 * @param \Exception $exception The failure.
	 *
	 * @return void
	 */
	private function logAccountFailure(IUser $user, \Exception $exception): void {
		$this->logger->warning(
			'Failed to update AccountManager properties for user: ' . $user->getUID(),
			[
				'exception' => $exception->getMessage(),
			]
		);
	}//end logAccountFailure()

	/**
	 * The custom name fields the data carries (null values excluded).
	 *
	 * @param array $data The data array containing updates.
	 *
	 * @return array<string,mixed> The name fields, in firstName/lastName/middleName order.
	 */
	private function customNameFieldsIn(array $data): array {
		$nameFields = [];
		foreach (['firstName', 'lastName', 'middleName'] as $field) {
			if (isset($data[$field]) === true) {
				$nameFields[$field] = $data[$field];
			}
		}

		return $nameFields;
	}//end customNameFieldsIn()
}//end class
