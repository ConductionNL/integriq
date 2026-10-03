<?php

/**
 * LtiLaunchRequestedEvent: a sibling app asks integriq to open an LTI tool.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * "Open this tool placement for this user", with a slot for the answer.
 *
 * ADR-041: a typed command with a result slot. The dispatch is synchronous,
 * so the requester (learniq's placement controller) reads the login
 * initiation, or the refusal, off the same instance. The login initiation is
 * a form `{formActionUrl, method, fields}` the requester renders and submits
 * in the learner's browser; it targets the tool's OIDC login URL, never the
 * tool's launch URL, because an LTI 1.3 tool starts every launch at its own
 * login endpoint (design.md D2).
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */
class LtiLaunchRequestedEvent extends Event {

	/**
	 * The login initiation form, set when integriq took the launch.
	 *
	 * @var array{formActionUrl: string, method: string, fields: array<string, string>}|null
	 */
	private ?array $loginInitiation = null;

	/**
	 * Why the launch was not taken, when it was not.
	 *
	 * @var array{code: string, reason: string}|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The app asking, normally learniq.
	 * @param string $placementId The tool placement; becomes the resource link id.
	 * @param string $deploymentUuid The `lti_deployment` naming the tool.
	 * @param string $userId The Nextcloud uid of the user the tool opens for.
	 * @param string $messageType `LtiResourceLinkRequest` (deep linking is not served yet).
	 * @param string $role `Learner` or `Instructor`.
	 * @param string $contextId The course id.
	 * @param string $contextTitle The course title.
	 * @param string $returnUrl Where the tool sends the user back.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $placementId,
		private readonly string $deploymentUuid,
		private readonly string $userId,
		private readonly string $messageType = 'LtiResourceLinkRequest',
		private readonly string $role = 'Learner',
		private readonly string $contextId = '',
		private readonly string $contextTitle = '',
		private readonly string $returnUrl = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Which app asked.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;

	}//end getSourceApp()

	/**
	 * The tool placement.
	 *
	 * @return string The placement id.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getPlacementId(): string {
		return $this->placementId;

	}//end getPlacementId()

	/**
	 * The deployment naming the tool.
	 *
	 * @return string The `lti_deployment` uuid.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getDeploymentUuid(): string {
		return $this->deploymentUuid;

	}//end getDeploymentUuid()

	/**
	 * The user the tool opens for.
	 *
	 * @return string The Nextcloud uid.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getUserId(): string {
		return $this->userId;

	}//end getUserId()

	/**
	 * The LTI message type.
	 *
	 * @return string The message type.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getMessageType(): string {
		return $this->messageType;

	}//end getMessageType()

	/**
	 * The user's role in the course.
	 *
	 * @return string `Learner` or `Instructor`.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getRole(): string {
		return $this->role;

	}//end getRole()

	/**
	 * The course id.
	 *
	 * @return string The context id.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getContextId(): string {
		return $this->contextId;

	}//end getContextId()

	/**
	 * The course title.
	 *
	 * @return string The context title.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getContextTitle(): string {
		return $this->contextTitle;

	}//end getContextTitle()

	/**
	 * Where the tool sends the user back.
	 *
	 * @return string The return URL.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getReturnUrl(): string {
		return $this->returnUrl;

	}//end getReturnUrl()

	/**
	 * Record the login initiation form.
	 *
	 * @param array{formActionUrl: string, method: string, fields: array<string, string>} $loginInitiation The form.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function setLoginInitiation(array $loginInitiation): void {
		$this->loginInitiation = $loginInitiation;

	}//end setLoginInitiation()

	/**
	 * The login initiation form, or null when the launch was not taken.
	 *
	 * @return array{formActionUrl: string, method: string, fields: array<string, string>}|null The form.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getLoginInitiation(): ?array {
		return $this->loginInitiation;

	}//end getLoginInitiation()

	/**
	 * Refuse the launch, saying why.
	 *
	 * @param string $code A machine-readable code (`deployment-unknown`, `tool-not-approved`, `user-mismatch`, ...).
	 * @param string $reason What an operator can act on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function refuse(string $code, string $reason): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];

	}//end refuse()

	/**
	 * The structured refusal, or null when the launch was not refused.
	 *
	 * @return array{code: string, reason: string}|null The refusal.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function getRefusal(): ?array {
		return $this->refusal;

	}//end getRefusal()

	/**
	 * Whether integriq answered: a login initiation or a refusal.
	 *
	 * @return bool True once either slot is set.
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function isHandled(): bool {
		return $this->loginInitiation !== null || $this->refusal !== null;

	}//end isHandled()
}//end class
