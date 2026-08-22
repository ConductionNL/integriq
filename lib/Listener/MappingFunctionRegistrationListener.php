<?php

/**
 * Contributes Integriq's mapping functions to OpenRegister's engine.
 *
 * Mapping evaluation is OpenRegister's, and every pure transformation function
 * moved there with it. Three cannot move, because they need services only
 * Integriq has:
 *
 * - `callSource` — CallService, the governed outbound HTTP client
 * - `getTargetIdByOriginId` / `getOriginIdByTargetId` — the synchronisation
 *   contract store, which maps an id in a source system to its counterpart here
 *
 * Importing those into OpenRegister would invert the dependency: OpenRegister is
 * the foundation app and must load on an instance where Integriq is absent.
 * So the engine stays there and these three are contributed, exactly as
 * Integriq already contributes flow nodes through RegisterFlowNodesEvent.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Integriq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Listener;

use OCA\Integriq\Twig\MappingRuntime;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Twig\TwigFunction;

/**
 * Registers the mapping functions that need Integriq's own services.
 *
 * @template-implements IEventListener<Event>
 */
class MappingFunctionRegistrationListener implements IEventListener {
	/**
	 * Contribute the functions OpenRegister cannot provide for itself.
	 *
	 * No class_exists() guard, matching how FlowNodeListener is registered:
	 * addServiceListener is lazy, so this class is only constructed when the
	 * event actually fires — which can only happen if OpenRegister is present
	 * and dispatching it. On an older OpenRegister nothing dispatches and this
	 * stays inert.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authentication-twig/spec.md#requirement-twig-mapping-runtime-encoding-mapping-execution-file-lookup-slug-req-005
	 */
	public function handle(Event $event): void {
		if (($event instanceof \OCA\OpenRegister\Service\RegisterMappingFunctionsEvent) === false) {
			return;
		}

		$event->registerFunction(
			new TwigFunction(name: 'callSource', callable: [MappingRuntime::class, 'callSource'])
		);
		$event->registerFunction(
			new TwigFunction(
				name: 'getTargetIdByOriginId',
				callable: [MappingRuntime::class, 'getTargetIdByOriginId']
			)
		);
		$event->registerFunction(
			new TwigFunction(
				name: 'getOriginIdByTargetId',
				callable: [MappingRuntime::class, 'getOriginIdByTargetId']
			)
		);

	}//end handle()
}//end class
