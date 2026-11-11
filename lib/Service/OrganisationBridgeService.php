<?php

/**
 * Organisation bridge service.
 *
 * This service acts as a bridge to access the OrganisationService from the
 * OpenRegister app. It provides organization-related functionality for user
 * management including active organization retrieval, organization switching,
 * and user organization statistics.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Bridge service for accessing OpenRegister OrganisationService
 *
 * This service provides a safe way to access organization functionality from the OpenRegister app
 * without creating direct dependencies. It handles cases where OpenRegister is not installed
 * and provides fallback behavior.
 *
 * @psalm-suppress UnusedClass
 */
class OrganisationBridgeService {
	/**
	 * The stats answered when OpenRegister cannot give them.
	 *
	 * @var array{total: int, active: null, results: array, available: false}
	 */
	private const NO_STATS = [
		'total' => 0,
		'active' => null,
		'results' => [],
		'available' => false,
	];

	/**
	 * Constructor for the OrganisationBridgeService
	 *
	 * @param IAppManager $appManager The app manager to check if OpenRegister is installed
	 * @param ContainerInterface $container The container to access OpenRegister services
	 * @param LoggerInterface $logger The logger for error tracking
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the OpenRegister OrganisationService if available
	 *
	 * This method safely retrieves the OrganisationService from the OpenRegister app
	 * if it's installed and available in the container.
	 *
	 * @return \OCA\OpenRegister\Service\OrganisationService|null The OrganisationService or null if not available
	 *
	 * @psalm-return   \OCA\OpenRegister\Service\OrganisationService|null
	 * @phpstan-return \OCA\OpenRegister\Service\OrganisationService|null
	 *
	 * @spec openspec/specs/organisation-bridge/spec.md
	 */
	public function getOrganisationService(): ?\OCA\OpenRegister\Service\OrganisationService {
		// Check if OpenRegister app is installed.
		if (in_array(needle: 'openregister', haystack: $this->appManager->getEnabledApps()) === false) {
			return null;
		}

		return $this->serviceFromContainer();
	}//end getOrganisationService()

	/**
	 * OpenRegister's OrganisationService from the container, or null when it is not there.
	 *
	 * @return \OCA\OpenRegister\Service\OrganisationService|null The service.
	 */
	private function serviceFromContainer(): ?\OCA\OpenRegister\Service\OrganisationService {
		try {
			// Attempt to get the OrganisationService from the container.
			return $this->container->get('OCA\OpenRegister\Service\OrganisationService');
		} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
			// Log the error but don't fail the application.
			$this->logger->warning('OpenRegister OrganisationService not available', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end serviceFromContainer()

	/**
	 * Check if organization functionality is available
	 *
	 * @return bool True if OpenRegister is installed and OrganisationService is available
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/organisation-bridge/spec.md
	 */
	public function isOrganisationServiceAvailable(): bool {
		return $this->getOrganisationService() !== null;
	}//end isOrganisationServiceAvailable()

	/**
	 * Get user organization statistics
	 *
	 * This method retrieves organization statistics for the current user,
	 * including total organizations, active organization, and all user organizations.
	 *
	 * @return array Organization statistics or empty array if service not available
	 *
	 * @psalm-return   array
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/organisation-bridge/spec.md
	 */
	public function getUserOrganisationStats(): array {
		$organisationService = $this->getOrganisationService();

		if ($organisationService === null) {
			// Return empty stats if service not available.
			return self::NO_STATS;
		}

		try {
			$stats = $organisationService->getUserOrganisationStats();
			$stats['available'] = true;
			return $stats;
		} catch (\Exception $e) {
			$this->logFailure(message: 'Failed to get user organization stats', context: ['exception' => $e->getMessage()]);
			return self::NO_STATS + ['error' => 'Failed to retrieve organization data'];
		}
	}//end getUserOrganisationStats()

	/**
	 * Set the active organization for the current user.
	 *
	 * This method allows users to switch their active organization.
	 *
	 * @param string $organisationUuid The organization UUID to set as active.
	 *
	 * @return array Result with success status and message.
	 *
	 * @spec openspec/specs/organisation-bridge/spec.md
	 */
	public function setActiveOrganisation(string $organisationUuid): array {
		$organisationService = $this->getOrganisationService();

		if ($organisationService === null) {
			return [
				'success' => false,
				'message' => 'Organization service not available',
				'available' => false,
			];
		}

		try {
			return $this->activationAnswer(result: $organisationService->setActiveOrganisation(organisationUuid: $organisationUuid));
		} catch (\Exception $e) {
			return $this->activationFailed(organisationUuid: $organisationUuid, exception: $e);
		}
	}//end setActiveOrganisation()

	/**
	 * Log an activation OpenRegister failed and answer it.
	 *
	 * @param string     $organisationUuid The organisation asked for.
	 * @param \Exception $exception        Why it failed.
	 *
	 * @return array{success: false, message: string, available: true} The answer.
	 */
	private function activationFailed(string $organisationUuid, \Exception $exception): array {
		$this->logFailure(
			message: 'Failed to set active organization',
			context: ['organisationUuid' => $organisationUuid, 'exception' => $exception->getMessage()]
		);
		return ['success' => false, 'message' => $exception->getMessage(), 'available' => true];
	}//end activationFailed()

	/**
	 * The answer to an activation OpenRegister carried out or declined.
	 *
	 * @param bool $result Whether OpenRegister switched the active organisation.
	 *
	 * @return array{success: bool, message: string, available: true} The answer.
	 */
	private function activationAnswer(bool $result): array {
		$message = 'Failed to update active organization';
		if ($result === true) {
			$message = 'Active organization updated successfully';
		}

		return [
			'success' => $result,
			'message' => $message,
			'available' => true,
		];
	}//end activationAnswer()

	/**
	 * Get the active organization for the current user
	 *
	 * @return array|null Active organization data or null if not available
	 *
	 * @psalm-return   array|null
	 * @phpstan-return array|null
	 *
	 * @spec openspec/specs/organisation-bridge/spec.md
	 */
	public function getActiveOrganisation(): ?array {
		$organisationService = $this->getOrganisationService();

		if ($organisationService === null) {
			return null;
		}

		try {
			return $organisationService->getActiveOrganisation()?->jsonSerialize();
		} catch (\Exception $e) {
			$this->logFailure(message: 'Failed to get active organization', context: ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getActiveOrganisation()

	/**
	 * Get all organizations for the current user
	 *
	 * @return array Array of organization data or empty array if not available
	 *
	 * @psalm-return   array
	 * @phpstan-return array
	 *
	 * @spec openspec/specs/organisation-bridge/spec.md
	 */
	public function getUserOrganisations(): array {
		$organisationService = $this->getOrganisationService();

		if ($organisationService === null) {
			return [];
		}

		try {
			$organisations = $organisationService->getUserOrganisations();
			return array_map(fn ($org) => $org->jsonSerialize(), $organisations);
		} catch (\Exception $e) {
			$this->logFailure(message: 'Failed to get user organizations', context: ['exception' => $e->getMessage()]);
			return [];
		}
	}//end getUserOrganisations()

	/**
	 * Log a call OpenRegister failed; the bridge answers its empty value instead.
	 *
	 * @param string               $message What failed.
	 * @param array<string, mixed> $context The organisation asked for, if any, and the error.
	 *
	 * @return void
	 */
	private function logFailure(string $message, array $context): void {
		$this->logger->error($message, $context);
	}//end logFailure()
}//end class
