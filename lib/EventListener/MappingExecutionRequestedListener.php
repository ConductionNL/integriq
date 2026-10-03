<?php

/**
 * Integriq MappingExecutionRequestedListener.
 *
 * Runs an integriq mapping by slug for a sibling app the mapping lets in.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Event\MappingExecutionRequestedEvent;
use OCA\Integriq\Service\MappingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers MappingExecutionRequestedEvent: resolve, check callableBy, run.
 *
 * A mapping can call other mappings and read files (MappingExtension), so it
 * is not a free function of the instance: only an app the mapping names in
 * `callableBy` may run it. An empty or absent list lets no app in, which is
 * every mapping that existed before this listener.
 *
 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
 *
 * @template-implements IEventListener<Event>
 */
class MappingExecutionRequestedListener implements IEventListener {

	/**
	 * Mappings resolved in this request, by slug, so a sitemap that asks once
	 * per publication pays for the run and not the lookup.
	 *
	 * @var array<string,ObjectEntity|null>
	 */
	private array $resolved = [];

	/**
	 * Constructor.
	 *
	 * @param MappingService $mappingService Resolves and runs the mapping.
	 * @param LoggerInterface $logger Names a mapping that failed.
	 */
	public function __construct(
		private readonly MappingService $mappingService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run the mapping, or refuse with not-found, not-allowed or failed.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function handle(Event $event): void {
		if (($event instanceof MappingExecutionRequestedEvent) === false) {
			return;
		}

		$slug = $event->getMappingSlug();
		if (array_key_exists($slug, $this->resolved) === false) {
			$this->resolved[$slug] = $this->mappingService->findMapping(reference: $slug);
		}

		$mapping = $this->resolved[$slug];
		if ($mapping === null) {
			$event->refuse(reason: sprintf('No mapping has the slug "%s".', $slug), code: 'not-found');
			return;
		}

		$callableBy = (array)(((array)$mapping->getObject())['callableBy'] ?? []);
		if (in_array($event->getSourceApp(), $callableBy, true) === false) {
			$event->refuse(
				reason: sprintf('The mapping "%s" does not list "%s" in callableBy.', $slug, $event->getSourceApp()),
				code: 'not-allowed'
			);
			return;
		}

		try {
			$event->setOutput($this->mappingService->executeMapping(mapping: $mapping, input: $event->getInput()));
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[integriq] mapping "' . $slug . '" failed for ' . $event->getSourceApp()
				. ' (correlation ' . $event->getCorrelationId() . '): ' . $failure->getMessage()
			);
			$event->refuse(reason: sprintf('The mapping "%s" failed: %s', $slug, $failure->getMessage()), code: 'failed');
		}

	}//end handle()
}//end class
