<?php
/**
 * OpenConnector user service.
 *
 * This service handles all user-related business logic including user data
 * retrieval, updates, and profile management. It centralizes user operations
 * and provides a clean interface for controllers and other services.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class UserService
{
    /**
     * UserService constructor.
     *
     * @param IUserSession              $userSession               The user session service.
     * @param IConfig                   $config                    The configuration service.
     * @param IGroupManager             $groupManager              The group manager service.
     * @param IAccountManager           $accountManager            The account manager service.
     * @param LoggerInterface           $logger                    The logger interface.
     * @param OrganisationBridgeService $organisationBridgeService The organization bridge service.
     * @param IDBConnection             $dbConnection              The database connection (injected).
     * @param IFactory                  $l10nFactory               The L10N factory (injected).
     *
     * @return void
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IConfig $config,
        private readonly IGroupManager $groupManager,
        private readonly IAccountManager $accountManager,
        private readonly LoggerInterface $logger,
        private readonly OrganisationBridgeService $organisationBridgeService,
        private readonly IDBConnection $dbConnection,
        private readonly IFactory $l10nFactory
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
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    public function getCurrentUser(): ?IUser
    {
        return $this->userSession->getUser();
    }//end getCurrentUser()

    /**
     * Build comprehensive user data array
     *
     * This method constructs a complete user data array including all standard
     * NextCloud user properties, custom profile fields, quota information,
     * group memberships, and additional profile data from AccountManager.
     *
     * @param IUser $user The user object to build data for.
     *
     * @return array The comprehensive user data array.
     *
     * @psalm-param    IUser $user
     * @psalm-return   array
     * @phpstan-param  IUser $user
     * @phpstan-return array
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    public function buildUserDataArray(IUser $user): array
    {
        // Get user groups from group manager.
        $userGroups = $this->groupManager->getUserGroups($user);
        $groupNames = array_values(array_map(fn($group) => $group->getGID(), $userGroups));

        // Build quota information safely.
        $quota = $this->buildQuotaInformation(user: $user);

        // Get language and locale with proper fallbacks.
        [$language, $locale] = $this->getLanguageAndLocale(user: $user);

        // Get additional profile information from AccountManager and custom fields.
        $additionalInfo = $this->getAdditionalProfileInfo(user: $user);

        // Resolve optional user-object methods with explicit fallbacks (Nextcloud
        // backends differ in which IUser methods they implement, so feature-detect).
        if (method_exists($user, 'getEmailVerified') === true) {
            $emailVerified = $user->getEmailVerified();
        } else {
            $emailVerified = null;
        }

        if (method_exists($user, 'getAvatarScope') === true) {
            $avatarScope = $user->getAvatarScope();
        } else {
            $avatarScope = 'contacts';
        }

        if (method_exists($user, 'getLastLogin') === true) {
            $lastLogin = $user->getLastLogin();
        } else {
            $lastLogin = 0;
        }

        if (method_exists($user, 'getBackendClassName') === true) {
            $backend = $user->getBackendClassName();
        } else {
            $backend = 'unknown';
        }

        if (method_exists($user, 'canChangeDisplayName') === true) {
            $canChangeDisplayName = $user->canChangeDisplayName();
        } else {
            $canChangeDisplayName = false;
        }

        if (method_exists($user, 'canChangeMailAddress') === true) {
            $canChangeMailAddress = $user->canChangeMailAddress();
        } else {
            $canChangeMailAddress = false;
        }

        if (method_exists($user, 'canChangePassword') === true) {
            $canChangePassword = $user->canChangePassword();
        } else {
            $canChangePassword = false;
        }

        if (method_exists($user, 'canChangeAvatar') === true) {
            $canChangeAvatar = $user->canChangeAvatar();
        } else {
            $canChangeAvatar = false;
        }

        // Build comprehensive user data array with all available information.
        $result = [
            'uid'                 => $user->getUID(),
            'displayName'         => $user->getDisplayName(),
            'email'               => $user->getEMailAddress(),
            'emailVerified'       => $emailVerified,
            'enabled'             => $user->isEnabled(),
            'quota'               => $quota,
            'avatarScope'         => $avatarScope,
            'lastLogin'           => $lastLogin,
            'backend'             => $backend,
            // Subadmin info would require additional service.
            'subadmin'            => [],
            'groups'              => $groupNames,
            'language'            => $language,
            'locale'              => $locale,
            'backendCapabilities' => [
                'displayName' => $canChangeDisplayName,
                'email'       => $canChangeMailAddress,
                'password'    => $canChangePassword,
                'avatar'      => $canChangeAvatar,
            ],
        ];

        // Merge additional profile information with result.
        $result = array_merge($result, $additionalInfo);

        // Ensure name fields are always present (even if null).
        $result['firstName']  = ($result['firstName'] ?? null);
        $result['lastName']   = ($result['lastName'] ?? null);
        $result['middleName'] = ($result['middleName'] ?? null);

        // Add organization information if available.
        if ($this->organisationBridgeService->isOrganisationServiceAvailable() === true) {
            $organisationStats = $this->organisationBridgeService->getUserOrganisationStats();
        } else {
            $organisationStats = [];
        }

        $result['organisations'] = $organisationStats;

        return $result;
    }//end buildUserDataArray()

    /**
     * Update user properties based on provided data
     *
     * This method safely updates user properties that are allowed to be modified
     * through the API, including standard user properties and custom profile fields.
     *
     * @param IUser $user The user object to update.
     * @param array $data The data array containing updates.
     *
     * @return array Result of the update operation including organization changes.
     *
     * @psalm-param    IUser $user
     * @psalm-param    array $data
     * @psalm-return   array
     * @phpstan-param  IUser $user
     * @phpstan-param  array $data
     * @phpstan-return array
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    public function updateUserProperties(IUser $user, array $data): array
    {
        $result = [
            'success'              => true,
            'message'              => 'User properties updated successfully',
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
     * @param IUser $user The user object.
     *
     * @return array Array containing name fields.
     *
     * @psalm-param    IUser $user
     * @psalm-return   array{firstName: string|null, lastName: string|null, middleName: string|null}
     * @phpstan-param  IUser $user
     * @phpstan-return array{firstName: string|null, lastName: string|null, middleName: string|null}
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    public function getCustomNameFields(IUser $user): array
    {
        $userId = $user->getUID();

        $firstName  = $this->config->getUserValue($userId, 'core', 'firstName', '');
        $lastName   = $this->config->getUserValue($userId, 'core', 'lastName', '');
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
            'firstName'  => $firstName,
            'lastName'   => $lastName,
            'middleName' => $middleName,
        ];
    }//end getCustomNameFields()

    /**
     * Set custom name fields for a user (accessible to other apps)
     *
     * This method stores the custom name fields in the 'core' namespace making them
     * accessible to other NextCloud apps while maintaining consistency.
     *
     * @param IUser $user       The user object.
     * @param array $nameFields Array containing name field values.
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
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    public function setCustomNameFields(IUser $user, array $nameFields): void
    {
        $userId        = $user->getUID();
        $allowedFields = ['firstName', 'lastName', 'middleName'];

        foreach ($allowedFields as $field) {
            if (isset($nameFields[$field]) === true) {
                $value = (string) $nameFields[$field];
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
     * @param IUser $user The user object.
     *
     * @return array The quota information array.
     *
     * @psalm-param    IUser $user
     * @psalm-return   array
     * @phpstan-param  IUser $user
     * @phpstan-return array
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    private function buildQuotaInformation(IUser $user): array
    {
        try {
            if (method_exists($user, 'getQuota') === true) {
                $userQuota = $user->getQuota();
            } else {
                $userQuota = 'none';
            }

            $usedSpace = 0;

            // MEMORY FIX: Use NextCloud's built-in quota system instead of recursive folder size calculation.
            // This prevents memory exhaustion when users have large amounts of data.
            $userId = $user->getUID();

            // Try to get used space from NextCloud's user object first.
            try {
                // Fallback 1: Try user object method if available.
                // Fallback 2: Use a memory-safe approach with timeout protection.
                $usedSpace = $this->getUsedSpaceMemorySafe(userId: $userId);
                if (method_exists($user, 'getUsedSpace') === true) {
                    $usedSpace = $user->getUsedSpace();
                }
            } catch (\Exception $quotaException) {
                // If quota calculation fails, use memory-safe approach.
                $this->logger->debug(
                        'User quota calculation failed for user: '.$userId,
                        [
                            'exception' => $quotaException->getMessage(),
                        ]
                        );

                $usedSpace = $this->getUsedSpaceMemorySafe(userId: $userId);
            }

            $quota = [
                'free'     => $userQuota,
                'used'     => $usedSpace,
                'total'    => $userQuota,
                'relative' => 0,
            ];

            // Calculate relative percentage if quota is not unlimited.
            if ($userQuota !== 'none' && $userQuota !== 'unlimited' && is_numeric($userQuota) === true) {
                $totalBytes = (int) $userQuota;
                if ($totalBytes > 0) {
                    $quota['relative'] = round((($usedSpace / $totalBytes) * 100), 2);
                }
            }

            return $quota;
        } catch (\Exception $e) {
            // Log error and return fallback quota information.
            $this->logger->warning(
                    'Failed to build quota information for user: '.$user->getUID(),
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );

            return [
                'free'     => 'none',
                'used'     => 0,
                'total'    => 'none',
                'relative' => 0,
            ];
        }//end try
    }//end buildQuotaInformation()

    /**
     * Get used space in a memory-safe way
     *
     * This method attempts to get used space without causing memory exhaustion
     * by using database queries or cache lookups instead of recursive folder scanning.
     *
     * @param string $userId The user ID.
     *
     * @return int The used space in bytes or 0 if cannot be determined safely.
     *
     * @psalm-param    string $userId
     * @psalm-return   int
     * @phpstan-param  string $userId
     * @phpstan-return int
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    private function getUsedSpaceMemorySafe(string $userId): int
    {
        try {
            // Set memory limit and timeout for safety.
            $originalMemoryLimit = ini_get('memory_limit');
            $currentMemoryUsage  = memory_get_usage(true);

            // If we're already using too much memory, return 0 to prevent OOM.
            if ($currentMemoryUsage > (128 * 1024 * 1024)) {
                // 128MB threshold.
                $this->logger->warning(
                        'Memory usage too high for quota calculation',
                        [
                            'user'         => $userId,
                            'memory_usage' => $currentMemoryUsage,
                        ]
                        );
                return 0;
            }

            // Try to get size from database if available (NextCloud stores this).
            $connection = $this->dbConnection;
            $query      = $connection->getQueryBuilder();

            // Check if NextCloud has cached storage stats.
            $query->select('size')
                ->from('storages')
                ->join('storages', 'mounts', 'm', 'storages.id = m.storage_id')
                ->where($query->expr()->eq('m.user_id', $query->createNamedParameter($userId)))
                ->setMaxResults(1);

            $result = $query->execute();
            $row    = $result->fetch();
            $result->closeCursor();

            if ($row !== false && isset($row['size']) === true && is_numeric($row['size']) === true) {
                return (int) $row['size'];
            }

            // If database lookup fails, return 0 to prevent memory issues.
            // The quota display will show 0 used space rather than causing OOM.
            $this->logger->info('Using fallback quota calculation for user: '.$userId);
            return 0;
        } catch (\Exception $e) {
            // Log error and return 0 to prevent memory issues.
            $this->logger->warning(
                    'Memory-safe quota calculation failed for user: '.$userId,
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
            return 0;
        }//end try
    }//end getUsedSpaceMemorySafe()

    /**
     * Get language and locale with proper fallbacks
     *
     * This method retrieves the user's language and locale settings with
     * intelligent fallbacks to system defaults when user preferences are not set.
     *
     * @param IUser $user The user object.
     *
     * @return array Array containing language and locale.
     *
     * @psalm-param    IUser $user
     * @psalm-return   array{0: string, 1: string}
     * @phpstan-param  IUser $user
     * @phpstan-return array{0: string, 1: string}
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    private function getLanguageAndLocale(IUser $user): array
    {
        $language = '';
        $locale   = '';

        if (method_exists($user, 'getLanguage') === true) {
            $language = $user->getLanguage();
            // If empty, try to get from browser or system default.
            if (empty($language) === true) {
                $language = $this->l10nFactory->findLanguage();
            }
        }

        if (method_exists($user, 'getLocale') === true) {
            $locale = $user->getLocale();
            // If empty, try to get from language.
            if (empty($locale) === true && empty($language) === false) {
                if ($language === 'en') {
                    $locale = 'en_US';
                } else {
                    $locale = $language.'_'.strtoupper($language);
                }
            }
        }

        return [$language, $locale];
    }//end getLanguageAndLocale()

    /**
     * Get additional profile information from various sources
     *
     * This method retrieves profile information from AccountManager and custom
     * field storage, with proper fallbacks and error handling.
     *
     * @param IUser $user The user object.
     *
     * @return array Additional profile information.
     *
     * @psalm-param    IUser $user
     * @psalm-return   array
     * @phpstan-param  IUser $user
     * @phpstan-return array
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    private function getAdditionalProfileInfo(IUser $user): array
    {
        $additionalInfo = [];

        try {
            // MEMORY FIX: Use selective property loading instead of getAllProperties().
            // This prevents loading unnecessary data and reduces memory usage.
            $additionalInfo = $this->getAccountManagerPropertiesSelectively(user: $user);
        } catch (\Exception $e) {
            // If AccountManager fails, try to get some info from user config.
            $this->logger->warning(
                    'AccountManager failed for user: '.$user->getUID(),
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

            $twitter = $this->config->getUserValue($userId, 'settings', 'twitter', '');
            if (empty($twitter) === false) {
                $additionalInfo['twitter'] = $twitter;
            }
        }//end try

        // Always get custom name fields from core namespace (accessible to other apps).
        $customNameFields = $this->getCustomNameFields(user: $user);
        $additionalInfo   = array_merge($additionalInfo, $customNameFields);

        // Get organization UUID from core namespace (set by SoftwareCatalog).
        $userId           = $user->getUID();
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
     * @param IUser $user The user object.
     *
     * @return array Profile information from AccountManager.
     *
     * @psalm-param    IUser $user
     * @psalm-return   array
     * @phpstan-param  IUser $user
     * @phpstan-return array
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    private function getAccountManagerPropertiesSelectively(IUser $user): array
    {
        $additionalInfo = [];

        // Get user account data from AccountManager.
        $account = $this->accountManager->getAccount($user);

        // Define the properties we actually need (selective loading).
        $neededProperties = [
            IAccountManager::PROPERTY_PHONE        => 'phone',
            IAccountManager::PROPERTY_ADDRESS      => 'address',
            IAccountManager::PROPERTY_WEBSITE      => 'website',
            IAccountManager::PROPERTY_TWITTER      => 'twitter',
            IAccountManager::PROPERTY_FEDIVERSE    => 'fediverse',
            IAccountManager::PROPERTY_ORGANISATION => 'organisation',
            IAccountManager::PROPERTY_ROLE         => 'role',
            IAccountManager::PROPERTY_HEADLINE     => 'headline',
            IAccountManager::PROPERTY_BIOGRAPHY    => 'biography',
        ];

        // Load only the properties we need.
        foreach ($neededProperties as $propertyName => $apiField) {
            try {
                $property = $account->getProperty($propertyName);
                if ($property !== null) {
                    $value = $property->getValue();
                    if (empty($value) === false) {
                        $additionalInfo[$apiField] = $value;
                    }
                }
            } catch (\Exception $e) {
                // If a specific property fails, log it but continue with others.
                $this->logger->debug(
                        'Failed to load account property: '.$propertyName,
                        [
                            'user'      => $user->getUID(),
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
     * @param IUser $user The user object to update.
     * @param array $data The data array containing updates.
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
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    private function updateStandardUserProperties(IUser $user, array $data): void
    {
        // Update display name if provided and user can change it.
        if (isset($data['displayName']) === true
            && method_exists($user, 'canChangeDisplayName') === true
            && $user->canChangeDisplayName() === true
        ) {
            $user->setDisplayName($data['displayName']);
        }

        // Update email address if provided and user can change it.
        if (isset($data['email']) === true
            && method_exists($user, 'canChangeMailAddress') === true
            && $user->canChangeMailAddress() === true
        ) {
            $user->setEMailAddress($data['email']);
        }

        // Update password if provided and user can change it.
        if (isset($data['password']) === true
            && method_exists($user, 'canChangePassword') === true
            && $user->canChangePassword() === true
        ) {
            $user->setPassword($data['password']);
        }

        // Update language if provided.
        if (isset($data['language']) === true && method_exists($user, 'setLanguage') === true) {
            $user->setLanguage($data['language']);
        }

        // Update locale if provided.
        if (isset($data['locale']) === true && method_exists($user, 'setLocale') === true) {
            $user->setLocale($data['locale']);
        }
    }//end updateStandardUserProperties()

    /**
     * Update profile properties via AccountManager and custom fields
     *
     * This method updates profile properties stored in AccountManager and
     * custom name fields stored in user configuration.
     *
     * @param IUser $user The user object to update.
     * @param array $data The data array containing updates.
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
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    private function updateProfileProperties(IUser $user, array $data): void
    {
        try {
            // Get the user's account from AccountManager.
            $account        = $this->accountManager->getAccount($user);
            $accountUpdated = false;

            // Define the standard profile fields we can update via AccountManager.
            $standardFields = [
                'phone'        => IAccountManager::PROPERTY_PHONE,
                'address'      => IAccountManager::PROPERTY_ADDRESS,
                'website'      => IAccountManager::PROPERTY_WEBSITE,
                'twitter'      => IAccountManager::PROPERTY_TWITTER,
                'fediverse'    => IAccountManager::PROPERTY_FEDIVERSE,
                'organisation' => IAccountManager::PROPERTY_ORGANISATION,
                'role'         => IAccountManager::PROPERTY_ROLE,
                'headline'     => IAccountManager::PROPERTY_HEADLINE,
                'biography'    => IAccountManager::PROPERTY_BIOGRAPHY,
            ];

            // Update standard AccountManager fields.
            foreach ($standardFields as $apiField => $accountProperty) {
                if (isset($data[$apiField]) === true) {
                    $value = (string) $data[$apiField];

                    // Create or update the account property.
                    if ($account->getProperty($accountProperty) === null) {
                        // Create new property with appropriate scope and verification.
                        $scope    = $this->getDefaultPropertyScope(propertyName: $accountProperty);
                        $verified = IAccountManager::NOT_VERIFIED;

                        $account->setProperty(
                            $accountProperty,
                            $value,
                            $scope,
                            $verified
                        );
                        $accountUpdated = true;
                        continue;
                    }

                    // Update existing property.
                    $property = $account->getProperty($accountProperty);
                    if ($property->getValue() !== $value) {
                        $property->setValue($value);
                        $accountUpdated = true;
                    }
                }//end if
            }//end foreach

            // Save the account if any properties were updated.
            if ($accountUpdated === true) {
                $this->accountManager->updateAccount($account);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the entire update.
            $this->logger->warning(
                    'Failed to update AccountManager properties for user: '.$user->getUID(),
                    [
                        'exception' => $e->getMessage(),
                    ]
                    );
        }//end try

        // Handle custom name fields separately (accessible to other apps via 'core' namespace).
        $customFields = ['firstName', 'lastName', 'middleName'];
        $nameFields   = [];

        foreach ($customFields as $field) {
            if (isset($data[$field]) === true) {
                $nameFields[$field] = $data[$field];
            }
        }

        if (empty($nameFields) === false) {
            $this->setCustomNameFields(user: $user, nameFields: $nameFields);
        }
    }//end updateProfileProperties()

    /**
     * Get default property scope for account properties
     *
     * This method returns appropriate default visibility scopes for different
     * types of account properties to ensure proper privacy settings.
     *
     * @param string $propertyName The property name.
     *
     * @return string The default scope for the property.
     *
     * @psalm-param    string $propertyName
     * @psalm-return   string
     * @phpstan-param  string $propertyName
     * @phpstan-return string
     *
     * @spec openspec/changes/retrofit-2026-05-25-user-management-and-login/tasks.md#task-1
     */
    private function getDefaultPropertyScope(string $propertyName): string
    {
        // Define default scopes for different property types.
        $scopeMap = [
            IAccountManager::PROPERTY_PHONE        => IAccountManager::SCOPE_PRIVATE,
            IAccountManager::PROPERTY_ADDRESS      => IAccountManager::SCOPE_PRIVATE,
            IAccountManager::PROPERTY_WEBSITE      => IAccountManager::SCOPE_PUBLISHED,
            IAccountManager::PROPERTY_TWITTER      => IAccountManager::SCOPE_PUBLISHED,
            IAccountManager::PROPERTY_FEDIVERSE    => IAccountManager::SCOPE_PUBLISHED,
            IAccountManager::PROPERTY_ORGANISATION => IAccountManager::SCOPE_LOCAL,
            IAccountManager::PROPERTY_ROLE         => IAccountManager::SCOPE_LOCAL,
            IAccountManager::PROPERTY_HEADLINE     => IAccountManager::SCOPE_LOCAL,
            IAccountManager::PROPERTY_BIOGRAPHY    => IAccountManager::SCOPE_LOCAL,
        ];

        return $scopeMap[$propertyName] ?? IAccountManager::SCOPE_PRIVATE;
    }//end getDefaultPropertyScope()
}//end class
