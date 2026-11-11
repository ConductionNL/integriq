<?php

/**
 * Integriq LtiLaunchRefusals: the checks a launch request fails before any
 * deployment or tool is looked up.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCP\IUserSession;

/**
 * The refusals of {@see LtiPlatformLoginService::initiateLogin()} that need
 * no lookup, in the order it applies them; built by that service.
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */
final class LtiLaunchRefusals {
	/**
	 * Constructor.
	 *
	 * @param IUserSession $userSession The signed-in user's session.
	 */
	public function __construct(private readonly IUserSession $userSession) {

	}//end __construct()

	/**
	 * The first refusal of a request: another user, another message type, or
	 * an unknown role; null when none applies.
	 *
	 * @param LtiLaunchRequestedEvent $event The request.
	 *
	 * @return array{code: string, reason: string}|null The refusal.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function refusalFor(LtiLaunchRequestedEvent $event): ?array {
		return $this->userRefusal(event: $event)
			?? $this->messageTypeRefusal(event: $event)
			?? $this->roleRefusal(event: $event);

	}//end refusalFor()

	/**
	 * The refusal of a launch that is not for the signed-in user, or null.
	 *
	 * @param LtiLaunchRequestedEvent $event The request.
	 *
	 * @return array{code: string, reason: string}|null
	 */
	private function userRefusal(LtiLaunchRequestedEvent $event): ?array {
		$sessionUid = $this->userSession->getUser()?->getUID();
		if ($sessionUid === null || $sessionUid !== $event->getUserId()) {
			return ['code' => 'user-mismatch', 'reason' => 'The launch is not for the signed-in user'];
		}

		return null;

	}//end userRefusal()

	/**
	 * The refusal of a message type other than a resource link launch, or null.
	 *
	 * @param LtiLaunchRequestedEvent $event The request.
	 *
	 * @return array{code: string, reason: string}|null
	 */
	private function messageTypeRefusal(LtiLaunchRequestedEvent $event): ?array {
		if ($event->getMessageType() !== LtiPlatformLoginService::MESSAGE_TYPE_RESOURCE_LINK) {
			return [
				'code' => 'message-type-unsupported',
				'reason' => 'Only ' . LtiPlatformLoginService::MESSAGE_TYPE_RESOURCE_LINK . ' launches are served; got ' . $event->getMessageType(),
			];
		}

		return null;

	}//end messageTypeRefusal()

	/**
	 * The refusal of a role other than Learner or Instructor, or null.
	 *
	 * @param LtiLaunchRequestedEvent $event The request.
	 *
	 * @return array{code: string, reason: string}|null
	 */
	private function roleRefusal(LtiLaunchRequestedEvent $event): ?array {
		if (isset(LtiPlatformLoginService::LIS_ROLES[$event->getRole()]) === false) {
			return ['code' => 'role-unknown', 'reason' => 'The role must be Learner or Instructor; got ' . $event->getRole()];
		}

		return null;

	}//end roleRefusal()
}//end class
