<?php

/**
 * Integriq LtiIdentityLinkService.
 *
 * Resolves (or, under an explicit per-platform opt-in, provisions) the
 * Nextcloud user a validated LTI launch's `sub` claim maps to. Runs strictly
 * after {@see LtiLaunchService::validateLaunch()} has already
 * cryptographically accepted a launch — it never influences that trust
 * decision, only what happens after it (REQ-LTI-012).
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use DateTime;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * `(ltiPlatformId, subject)` -> Nextcloud `userId` identity resolution and
 * (opt-in only) provisioning.
 *
 * Trust model (design.md D2): default `manualLinkOnly`, no email/name
 * matching ever, auto-provisioning is an explicit per-platform opt-in bound
 * to a named group. This service is read-mostly with respect to trust — it
 * can create a Nextcloud user as a side effect of an already-verified launch,
 * it can never make a launch appear more trustworthy than
 * `LtiLaunchService::validateLaunch()` already determined.
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
class LtiIdentityLinkService {

	/**
	 * Conservative default — never auto-creates a Nextcloud user or matches
	 * by email/name; an unlinked `sub` is reported as such.
	 *
	 * @var string
	 */
	public const POLICY_MANUAL_LINK_ONLY = 'manualLinkOnly';

	/**
	 * Explicit per-platform opt-in — a first-seen `sub` is provisioned into
	 * `defaultProvisionGroup`.
	 *
	 * @var string
	 */
	public const POLICY_AUTO_PROVISION_AS_ROLE = 'autoProvisionAsRole';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService OR ObjectService used for all register reads/writes.
	 * @param IUserManager $userManager Provisions a Nextcloud user under `autoProvisionAsRole`.
	 * @param IGroupManager $groupManager Adds an auto-provisioned user to `defaultProvisionGroup`.
	 * @param ISecureRandom $secureRandom Generates the auto-provisioned account's (never-used-interactively) password.
	 * @param LoggerInterface $logger Logger (never logs generated passwords or key material).
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ISecureRandom $secureRandom,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve a validated launch's `(ltiPlatformId, subject)` to a Nextcloud
	 * user, provisioning one only under an explicit `autoProvisionAsRole`
	 * policy.
	 *
	 * MUST only be called after the launch has already been
	 * cryptographically accepted by {@see LtiLaunchService::validateLaunch()}
	 * — this method performs no trust decision of its own.
	 *
	 * @param string $ltiPlatformId The `lti_platform` registration UUID that issued the verified `sub`.
	 * @param string $subject The verified `id_token`'s `sub` claim value.
	 *
	 * @return array{status: 'linked'|'unlinked', userId: ?string}
	 *
	 * @throws LtiValidationException When `$ltiPlatformId` does not resolve to a known `lti_platform`.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function resolveIdentity(string $ltiPlatformId, string $subject): array {
		$existingLink = $this->findLink(ltiPlatformId: $ltiPlatformId, subject: $subject);
		if ($existingLink !== null) {
			$linkData = $existingLink->getObject();
			return ['status' => 'linked', 'userId' => ($linkData['userId'] ?? null)];
		}

		$platformData = $this->findPlatformData(ltiPlatformId: $ltiPlatformId);
		$policy = ($platformData['identityPolicy'] ?? self::POLICY_MANUAL_LINK_ONLY);

		if ($policy !== self::POLICY_AUTO_PROVISION_AS_ROLE) {
			// ManualLinkOnly (default) — no user created, no email/name guessing.
			return ['status' => 'unlinked', 'userId' => null];
		}

		$groupId = ($platformData['defaultProvisionGroup'] ?? null);
		if (empty($groupId) === true) {
			// Conservative: a misconfigured autoProvisionAsRole (no group
			// named) never falls back to an unbounded provision — treated
			// as unlinked, same as manualLinkOnly.
			$this->logger->warning(
				'LtiIdentityLinkService: autoProvisionAsRole set without defaultProvisionGroup — refusing to auto-provision',
				['ltiPlatformId' => $ltiPlatformId]
			);

			return ['status' => 'unlinked', 'userId' => null];
		}

		$userId = $this->provisionUser(ltiPlatformId: $ltiPlatformId, subject: $subject, groupId: (string)$groupId);

		$link = $this->createLink(
			ltiPlatformId: $ltiPlatformId,
			subject: $subject,
			userId: $userId,
			provisioningMethod: 'auto',
			linkedByUserId: null
		);

		return ['status' => 'linked', 'userId' => ($link->getObject()['userId'] ?? $userId)];
	}//end resolveIdentity()

	/**
	 * Find an existing `lti_identity_link` row for `(ltiPlatformId, subject)`.
	 *
	 * @param string $ltiPlatformId The `lti_platform` registration UUID.
	 * @param string $subject The verified `sub` claim value.
	 *
	 * @return ObjectEntity|null The link, or null when this subject has never resolved before.
	 */
	private function findLink(string $ltiPlatformId, string $subject): ?ObjectEntity {
		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'openconnector',
					'schema' => 'lti_identity_link',
					'ltiPlatformId' => $ltiPlatformId,
					'subject' => $subject,
				],
			],
			_rbac: false,
			_multitenancy: false
		);
		$results = ($matches['results'] ?? $matches);

		return ($results[0] ?? null);
	}//end findLink()

	/**
	 * Load an `lti_platform` registration's data by UUID.
	 *
	 * Deliberately bypasses {@see LtiRegistrationResolverService}'s
	 * approved-only gate — by the time identity resolution runs, the launch
	 * has already been accepted under this exact registration (REQ-LTI-005),
	 * so its approval status is not re-checked here.
	 *
	 * @param string $ltiPlatformId The `lti_platform` registration UUID.
	 *
	 * @return array The registration's object data.
	 *
	 * @throws LtiValidationException When the registration does not exist.
	 */
	private function findPlatformData(string $ltiPlatformId): array {
		try {
			$platform = $this->orObjectService->find(
				id: $ltiPlatformId,
				register: 'openconnector',
				schema: 'lti_platform',
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			throw new LtiValidationException(
				message: 'Unknown lti_platform',
				details: ['ltiPlatformId' => $ltiPlatformId],
				httpStatus: 400
			);
		}

		return $platform->getObject();
	}//end findPlatformData()

	/**
	 * Provision (or reuse, if a previous racing call already created it) the
	 * Nextcloud user for a first-seen `(ltiPlatformId, subject)` pair under
	 * `autoProvisionAsRole`, and ensure group membership.
	 *
	 * The uid is derived deterministically from `(ltiPlatformId, subject)`
	 * (never from any unverified profile claim such as email/name — D2) so
	 * a concurrent duplicate provisioning attempt resolves to the same
	 * account rather than creating two.
	 *
	 * @param string $ltiPlatformId The `lti_platform` registration UUID.
	 * @param string $subject The verified `sub` claim value.
	 * @param string $groupId The configured `defaultProvisionGroup`.
	 *
	 * @return string The provisioned (or reused) Nextcloud `userId`.
	 */
	private function provisionUser(string $ltiPlatformId, string $subject, string $groupId): string {
		$uid = 'lti-' . substr(hash('sha256', $ltiPlatformId . ':' . $subject), 0, 24);
		$user = $this->userManager->get($uid);

		if ($user === null) {
			$password = $this->secureRandom->generate(4, ISecureRandom::CHAR_UPPER)
				. $this->secureRandom->generate(4, ISecureRandom::CHAR_LOWER)
				. $this->secureRandom->generate(2, ISecureRandom::CHAR_DIGITS)
				. $this->secureRandom->generate(2, '!@#$%^&*()-_=+');

			$user = $this->userManager->createUser($uid, $password);

			$this->logger->info(
				'LtiIdentityLinkService: auto-provisioned a Nextcloud user',
				['ltiPlatformId' => $ltiPlatformId, 'userId' => $uid, 'group' => $groupId]
			);
		}

		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			$group = $this->groupManager->createGroup($groupId);
		}

		if ($group !== null && $group->inGroup($user) === false) {
			$group->addUser($user);
		}

		return $user->getUID();
	}//end provisionUser()

	/**
	 * Create an `lti_identity_link` row.
	 *
	 * Tolerates a concurrent racing creation of the same
	 * `(ltiPlatformId, subject)` pair (OR's schema-level `configuration.unique`
	 * constraint on `lti_identity_link` rejects the loser) by re-reading the
	 * winner's row instead of surfacing the write failure.
	 *
	 * @param string $ltiPlatformId The `lti_platform` registration UUID.
	 * @param string $subject The verified `sub` claim value.
	 * @param string $userId The Nextcloud `userId` this pair resolves to.
	 * @param string $provisioningMethod `manual` or `auto`.
	 * @param string|null $linkedByUserId The linking admin's `userId` (manual only).
	 *
	 * @return ObjectEntity The created (or, on a losing race, the pre-existing) link.
	 */
	private function createLink(
		string $ltiPlatformId,
		string $subject,
		string $userId,
		string $provisioningMethod,
		?string $linkedByUserId,
	): ObjectEntity {
		$data = [
			'ltiPlatformId' => $ltiPlatformId,
			'subject' => $subject,
			'userId' => $userId,
			'provisioningMethod' => $provisioningMethod,
			'linkedByUserId' => $linkedByUserId,
			'linkedAt' => (new DateTime())->format('c'),
		];

		try {
			return $this->orObjectService->saveObject(
				object: $data,
				register: 'openconnector',
				schema: 'lti_identity_link',
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $exception) {
			$existingLink = $this->findLink(ltiPlatformId: $ltiPlatformId, subject: $subject);
			if ($existingLink !== null) {
				return $existingLink;
			}

			throw $exception;
		}

	}//end createLink()
}//end class
