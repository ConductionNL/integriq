<?php
/**
 * Unit tests for NextcloudFormsEventListener.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\EventListener;

use OCA\Forms\Db\Form;
use OCA\Forms\Db\Submission;
use OCA\Forms\Events\FormSubmittedEvent;
use OCA\OpenConnector\EventListener\NextcloudFormsEventListener;
use OCA\OpenConnector\Service\EventService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Class names/accessors verified against the public `nextcloud/forms`
 * source (not a live installed instance — see discovery.md).
 *
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
 */
class NextcloudFormsEventListenerTest extends TestCase
{


    /**
     * TC-7: a FormSubmittedEvent produces a matching
     * `com.nextcloud.forms.submission.created` CloudEvent carrying `formId`.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
     */
    public function testFormSubmittedEventProducesMatchingCloudEvent(): void
    {
        $form       = new Form(id: 12, title: 'Feedback');
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
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
     */
    public function testUnrelatedEventIsIgnored(): void
    {
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
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-forms-submission-events-must-be-normalized-to-cloudevents-when-the-forms-app-is-installed-req-004
     */
    public function testSkipsWhenNoActiveSubscriptions(): void
    {
        $form       = new Form(id: 1);
        $submission = new Submission(id: 1, formId: 1);

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(false);
        $eventService->expects($this->never())->method('handleNextcloudEvent');

        $listener = new NextcloudFormsEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new FormSubmittedEvent($form, $submission));
    }//end testSkipsWhenNoActiveSubscriptions()
}//end class
