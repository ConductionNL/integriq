<?php

/**
 * LtiLaunchRequestedListener: answers a sibling app's platform launch request.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
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

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\LtiLaunchRequestedEvent;
use OCA\Integriq\Service\Lti\LtiPlatformLoginService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The entry point of a platform launch, from the sibling app's side.
 *
 * The answer is always on the event: a login initiation form or a named
 * refusal. An unanswered slot would leave learniq unable to tell "integriq
 * refused" from "integriq is not installed", so an unexpected failure is
 * turned into a refusal too, and never thrown into the sender.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
 */
class LtiLaunchRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LtiPlatformLoginService $loginService Builds the login initiation.
	 * @param LoggerInterface $logger Logger for key-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LtiPlatformLoginService $loginService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle one launch request.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-sibling-app-starts-a-platform-launch-with-a-typed-event-req-ltil-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof LtiLaunchRequestedEvent) === false) {
			return;
		}

		try {
			$this->loginService->initiateLogin(event: $event);
		} catch (Throwable $exception) {
			$this->logger->error(
				'LtiLaunchRequestedListener: launch initiation failed (' . $exception::class . ')',
				['deployment' => $event->getDeploymentUuid(), 'sourceApp' => $event->getSourceApp()]
			);
			$event->refuse(code: 'launch-failed', reason: 'The launch could not be started; see the server log');
		}
	}//end handle()
}//end class
