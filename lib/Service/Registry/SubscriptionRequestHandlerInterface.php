<?php

/**
 * Contract of the SubscriptionRequestHandler.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registry;

/**
 * Public operations of {@see SubscriptionRequestHandler} that other components call.
 */
interface SubscriptionRequestHandlerInterface {
	/**
	 * Handle one subscription request.
	 *
	 * @param array<string,mixed> $request The request payload, carrying a registry id and an identity value.
	 *
	 * @return SubscriptionResult|null The result, or null when the request named a registry nothing answers to.
	 *
	 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-request-is-turned-into-a-live-subscription-req-rsc-002
	 */
	public function handle(array $request): ?SubscriptionResult;
}
