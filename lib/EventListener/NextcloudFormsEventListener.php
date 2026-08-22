<?php

/**
 * Integriq Nextcloud Forms Submission EventListener.
 *
 * Normalizes the Forms app's submission-created event into the CloudEvents
 * `event` envelope, when the `forms` app is installed.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Forms\Events\FormSubmittedEvent;
use OCA\Integriq\Service\EventService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that normalizes a Forms submission-created event into a CloudEvent.
 *
 * Class names/accessors verified against the public `nextcloud/forms`
 * source (`lib/Events/{AbstractFormEvent,FormSubmittedEvent}.php`, `main`
 * branch, fetched during this change's implementation) rather than a live
 * installed instance (neither this repo's nor the checked server checkout
 * has the `forms` app present) — see `discovery.md`.
 * `Application::appEnabledForAnyone($appManager, 'forms')` gates registration in
 * `Application.php::register()` (REQ-004); this listener is never
 * constructed on an instance without the app.
 *
 * `FormSubmittedEvent` does not expose the submitted `Submission` entity via
 * a public getter — only `getForm()` (inherited from `AbstractFormEvent`,
 * always present) and, when available, `getWebhookSerializable()` (present
 * on BOTH of `AbstractFormEvent`'s conditional class bodies — the
 * `IWebhookCompatibleEvent`-implementing branch used on NC 30+ and the
 * plain-`Event` branch used on older NC — so it is effectively always
 * present regardless of NC version). This listener therefore reads
 * `getForm()` for `formId`/title, and defensively adds the submission
 * payload from `getWebhookSerializable()['submission']` when the method
 * exists.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
 */
class NextcloudFormsEventListener implements IEventListener {

	/**
	 * The CloudEvents `source` this producer stamps on every event.
	 *
	 * @var string
	 */
	private const SOURCE = '/nextcloud/forms';

	/**
	 * Constructor.
	 *
	 * @param EventService $eventService Service for managing CloudEvents.
	 * @param LoggerInterface $logger Logger instance.
	 */
	public function __construct(
		private readonly EventService $eventService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a fired Forms submission event by normalizing and forwarding it.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
	 */
	public function handle(Event $event): void {
		if ($event instanceof FormSubmittedEvent === false || method_exists($event, 'getForm') === false) {
			return;
		}

		try {
			// Firehose gate: no configured subscriptions anywhere on this
			// instance means the outbound-webhooks capability is unused — do
			// not pay a persistence cost for every form submission fleet-wide.
			if ($this->eventService->hasActiveSubscriptions() === false) {
				return;
			}

			$form = $event->getForm();
			$formId = (string)$form->getId();

			$submission = null;
			if (method_exists($event, 'getWebhookSerializable') === true) {
				$submission = ($event->getWebhookSerializable()['submission'] ?? null);
			}

			$formTitle = null;
			if (method_exists($form, 'getTitle') === true) {
				$formTitle = $form->getTitle();
			}

			$this->eventService->handleNextcloudEvent(
				type: 'com.nextcloud.forms.submission.created',
				payload: [
					'source' => self::SOURCE,
					'subject' => $formId,
					'data' => [
						'formId' => $formId,
						'formTitle' => $formTitle,
						'submission' => $submission,
					],
				]
			);
		} catch (\Throwable $e) {
			// Broad catch is deliberate: this listener runs synchronously
			// inside the Forms submission operation that triggered it.
			$this->logger->error(
				'Failed to process Nextcloud Forms submission event: ' . $e->getMessage(),
				[
					'exception' => $e,
					'event' => get_class($event),
				]
			);
		}//end try

	}//end handle()
}//end class
