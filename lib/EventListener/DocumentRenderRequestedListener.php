<?php

/**
 * Integriq Document Render Requested Listener.
 *
 * Turns filinq's typed render command into a tracked job, and answers the
 * command's result slot with the job id or a structured refusal.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\DocumentRenderRequestedEvent;
use OCA\Integriq\Exception\DocumentGenerationException;
use OCA\Integriq\Service\DocumentGeneration\DocumentGenerationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The entry point of a render, from filinq's side.
 *
 * A refusal is structured and named, never an empty result slot: filinq has
 * to be able to tell "integriq did not take this" from "integriq took it and
 * is working on it", and an unanswered slot says neither.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
 */
class DocumentRenderRequestedListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param DocumentGenerationService $service The render service.
	 * @param LoggerInterface $logger Logger for credential-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentGenerationService $service,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle one render command.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof DocumentRenderRequestedEvent) === false) {
			return;
		}

		try {
			$job = $this->service->requestRender(
				sourceId: $event->getSourceId(),
				templateId: $event->getTemplateId(),
				data: $event->getData(),
				requestedBy: $event->getRequestedBy(),
				requestedByApp: $event->getRequestedByApp()
			);
		} catch (DocumentGenerationException $exception) {
			$event->refuse(reason: $exception->getMessage(), code: 'not-configured');
			return;
		} catch (Throwable $exception) {
			$this->logger->error(
				'[integriq] a document render could not be taken: ' . $exception->getMessage(),
				['exception' => $exception, 'sourceId' => $event->getSourceId()]
			);
			$event->refuse(
				reason: 'The render could not be taken: ' . $exception->getMessage(),
				code: 'render-not-taken'
			);
			return;
		}//end try

		$event->setJobId(jobId: $job->getUuid());

	}//end handle()
}//end class
