<?php

/**
 * The request a source-call step hands to `CallService`.
 *
 * Kept out of `SourceCallNode` so that class stays about calling and
 * applying a policy, and this one about what one request carries: query,
 * headers and body, rendered against the item.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use OCA\Integriq\Exception\FlowNodeException;
use OCP\IL10N;

/**
 * Builds and guards the request configuration of one source-call item.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowTemplate is the shared static
 * renderer every Integriq node renders item values through, as
 * SourceCallNode did with this same code before it moved here.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) FlowTemplate is the shared, stateless
 * renderer every Integriq node resolves item paths through; SourceCallNode,
 * where this code came from, carries the same exception in the baseline.
 *
 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-a-source-call-sends-a-mapped-object-whole-req-sdc-003
 */
final class SourceCallRequest {

	/**
	 * Build the request configuration handed to `CallService`.
	 *
	 * Nothing here sets an authentication header: `FlowConfigGuard` has
	 * already refused any attempt to.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $json The current item's record.
	 *
	 * @return array The request configuration.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function build(array $config, array $json): array {
		$requestConfig = [];

		$query = ($config['query'] ?? null);
		if (is_array($query) === true && $query !== []) {
			$requestConfig['query'] = FlowTemplate::renderValue(value: $query, json: $json);
		}

		$headers = ($config['headers'] ?? null);
		if (is_array($headers) === true && $headers !== []) {
			$requestConfig['headers'] = FlowTemplate::renderValue(value: $headers, json: $json);
		}

		return array_merge($requestConfig, self::body(config: $config, json: $json));
	}//end build()

	/**
	 * The body part of the request configuration.
	 *
	 * `bodyFrom` sends the object at that item path whole and as it is: it
	 * was already shaped by a mapping step, so rendering it again could only
	 * change it. Otherwise an array `body` travels as `json` (the Guzzle
	 * option that encodes it) and a string `body` as `body`, both rendered
	 * against the item.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $json The current item's record.
	 *
	 * @return array `['json' => ...]`, `['body' => ...]` or nothing.
	 *
	 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-a-source-call-sends-a-mapped-object-whole-req-sdc-003
	 */
	public static function body(array $config, array $json): array {
		$bodyFrom = trim((string)($config['bodyFrom'] ?? ''));
		if ($bodyFrom !== '') {
			return ['json' => (array)FlowTemplate::lookup(path: $bodyFrom, json: $json)];
		}

		$body = ($config['body'] ?? null);
		if (is_array($body) === true && $body !== []) {
			return ['json' => FlowTemplate::renderValue(value: $body, json: $json)];
		}

		if (is_string($body) === true && $body !== '') {
			return ['body' => FlowTemplate::renderString(template: $body, json: $json)];
		}

		return [];
	}//end body()

	/**
	 * Refuse the step before any call when `bodyFrom` names no object.
	 *
	 * A body that silently went out empty would create or overwrite a record
	 * in the outside system with nothing, and report success. Checked in the
	 * guard pass, with the endpoints, so no item of the page is sent when one
	 * of them has nothing to send.
	 *
	 * @param array $config The step's authored configuration.
	 * @param array $json The item's record.
	 * @param int $index The item's position in the page.
	 * @param IL10N $l10n Translations for the failure message.
	 *
	 * @return void
	 *
	 * @throws FlowNodeException When the path does not resolve to an object.
	 *
	 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-a-source-call-sends-a-mapped-object-whole-req-sdc-003
	 */
	public static function assertBodyFromResolves(array $config, array $json, int $index, IL10N $l10n): void {
		$path = trim((string)($config['bodyFrom'] ?? ''));
		if ($path === '') {
			return;
		}

		if (is_array(FlowTemplate::lookup(path: $path, json: $json)) === false) {
			throw new FlowNodeException(
				message: $l10n->t(
					'The "bodyFrom" path "%1$s" did not resolve to an object on item %2$s; nothing was sent.',
					[$path, (string)$index]
				),
				details: ['kind' => 'body', 'bodyFrom' => $path, 'item' => $index]
			);
		}

	}//end assertBodyFromResolves()
}//end class
