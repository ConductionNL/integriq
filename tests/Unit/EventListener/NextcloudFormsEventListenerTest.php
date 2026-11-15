<?php

/**
 * Unit tests for NextcloudFormsEventListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Forms\Db\Form;
use OCA\Forms\Db\Submission;
use OCA\Forms\Events\FormSubmittedEvent;
use OCA\Integriq\EventListener\NextcloudFormsEventListener;
use OCA\Integriq\Service\EventService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Class names/accessors verified against the public `nextcloud/forms`
 * source (not a live installed instance — see discovery.md).
 *
 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
 */
class NextcloudFormsEventListenerTest extends TestCase {

	/**
	 * TC-7: a FormSubmittedEvent produces a matching
	 * `com.nextcloud.forms.submission.created` CloudEvent carrying `formId`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
	 */
	public function testFormSubmittedEventProducesMatchingCloudEvent(): void {
		$form = new Form(id: 12, title: 'Feedback');
		$submission = new Submission(id: 99, formId: 12, userId: 'bob');

		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->expects($this->once())
			->method('handleNextcloudEvent')
			->with(
				'com.nextcloud.forms.submission.created',
				$this->callback(function (array $payload) {
					return $payload['source'] === '/nextcloud/forms'
						&& $payload['subject'] === '12'
						&& $payload['data']['formId'] === '12'
						&& $payload['data']['formTitle'] === 'Feedback'
						&& ($payload['data']['submission']['id'] ?? null) === 99;
				})
			);

		$listener = new NextcloudFormsEventListener($eventService, $this->createMock(LoggerInterface::class));
		$listener->handle(new FormSubmittedEvent($form, $submission));
	}//end testFormSubmittedEventProducesMatchingCloudEvent()

	/**
	 * TC-5-style: an unrelated event type is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
	 */
	public function testUnrelatedEventIsIgnored(): void {
		$eventService = $this->createMock(EventService::class);
		$eventService->expects($this->never())->method('handleNextcloudEvent');

		$listener = new NextcloudFormsEventListener($eventService, $this->createMock(LoggerInterface::class));
		$listener->handle(new Event());
	}//end testUnrelatedEventIsIgnored()

	/**
	 * The firehose gate mirrors every other nextcloud-event-hub listener.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
	 */
	public function testSkipsWhenNoActiveSubscriptions(): void {
		$form = new Form(id: 1);
		$submission = new Submission(id: 1, formId: 1);

		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(false);
		$eventService->expects($this->never())->method('handleNextcloudEvent');

		$listener = new NextcloudFormsEventListener($eventService, $this->createMock(LoggerInterface::class));
		$listener->handle(new FormSubmittedEvent($form, $submission));
	}//end testSkipsWhenNoActiveSubscriptions()

	/**
	 * A submission sends exactly this payload, and a failure is logged with
	 * the event's class and never thrown.
	 *
	 * @return void
	 */
	public function testThePayloadAndTheLoggedFailure(): void {
		$form = new Form(id: 12, title: 'Feedback');
		$submission = new Submission(id: 99, formId: 12, userId: 'bob');
		$event = new FormSubmittedEvent($form, $submission);
		$sent = [];
		$failure = new \RuntimeException('store down');
		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->method('handleNextcloudEvent')->willReturnCallback(function (string $type, array $payload) use (&$sent, $failure): array {
			$sent[] = [$type, $payload];
			if (count($sent) === 2) {
				throw $failure;
			}

			return [];
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Failed to process Nextcloud Forms submission event: store down', ['exception' => $failure, 'event' => FormSubmittedEvent::class]);
		$listener = new NextcloudFormsEventListener($eventService, $logger);

		$listener->handle($event);
		$listener->handle($event);

		$expected = [
			'source' => '/nextcloud/forms',
			'subject' => '12',
			'data' => ['formId' => '12', 'formTitle' => 'Feedback', 'submission' => $submission->read()],
		];
		$this->assertSame([['com.nextcloud.forms.submission.created', $expected], ['com.nextcloud.forms.submission.created', $expected]], $sent);

	}//end testThePayloadAndTheLoggedFailure()
}//end class
