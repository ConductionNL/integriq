<?php

/**
 * Integriq — privileged consumer operations need more than a valid consumer key.
 *
 * SCIM provisioning creates, lists, disables and re-addresses Nextcloud
 * accounts, and an EUDI credential offer is signed with the municipality's
 * issuer key. Both used to accept any consumer that authenticated, so a key
 * issued for an unrelated read-only or callback consumer carried identity-admin
 * and credential-issuing power (WOO-487 review, iq-a-f5).
 *
 * A consumer may use such an operation only when:
 *  - its source allow-list (`ips` / `domains`) admits the request, the same
 *    check the endpoint runtime applies; and
 *  - an administrator granted it the operation's scope, by listing it in
 *    `authorizationConfiguration.scopes` (the consumer schema is admin-only),
 *    or the Nextcloud account the consumer acts as (`userId`) is an
 *    administrator.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Consumer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Consumer;

use OCA\Integriq\Service\ConsumerScopeService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Decides whether an authenticated consumer may use a privileged operation.
 */
class ConsumerPrivilegeGate {

	/**
	 * Scope a consumer needs for SCIM provisioning.
	 *
	 * @var string
	 */
	public const SCOPE_SCIM = 'scim';

	/**
	 * Scope a consumer needs to create or revoke EUDI credential offers.
	 *
	 * @var string
	 */
	public const SCOPE_EUDI_ISSUE = 'eudi.issue';

	/**
	 * Constructor.
	 *
	 * @param ConsumerScopeService $scopeService The consumer source allow-list check.
	 * @param IGroupManager        $groupManager Decides whether the consumer's account is an administrator.
	 * @param LoggerInterface      $logger       Records refusals.
	 */
	public function __construct(
		private readonly ConsumerScopeService $scopeService,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the consumer may use the operation behind the given scope.
	 *
	 * @param ObjectEntity $consumer The consumer resolved during authentication.
	 * @param string       $scope    The scope the operation requires.
	 * @param IRequest     $request  The inbound request.
	 *
	 * @return bool True when allowed; false means answer 403.
	 *
	 * @spec openspec/specs/consumer-management/spec.md
	 */
	public function mayUse(ObjectEntity $consumer, string $scope, IRequest $request): bool {
		$label = (string)($consumer->getUuid() ?? '');

		if ($this->scopeService->isAllowed(consumer: $consumer, request: $request) === false) {
			$this->logger->warning('[ConsumerPrivilegeGate] consumer ' . $label . ' refused: request source outside its allow-list');
			return false;
		}

		$data = $consumer->getObject();
		$scopes = ($data['authorizationConfiguration']['scopes'] ?? []);
		if (is_array($scopes) === true && in_array($scope, $scopes, true) === true) {
			return true;
		}

		$userId = ($data['userId'] ?? null);
		if (is_string($userId) === true && $userId !== '' && $this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$this->logger->warning('[ConsumerPrivilegeGate] consumer ' . $label . ' refused: no ' . $scope . ' scope and not acting as an administrator');

		return false;
	}//end mayUse()
}//end class
