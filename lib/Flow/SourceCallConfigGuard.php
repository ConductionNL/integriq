<?php

/**
 * Integriq Source Call config guard.
 *
 * The save-time rules that are specific to `openconnector.source-call`: which
 * HTTP method it may name, which statuses it may accept, what shape its
 * request parts may take, which output keys it may claim, and which `onError`
 * policy it may mirror.
 *
 * WHY THESE ARE NOT IN {@see FlowConfigGuard}
 * -------------------------------------------
 * That class holds the rules BOTH contributed node types must apply
 * byte-identically, because a divergence between them would be a security
 * defect. These rules are the opposite: they describe one node's own
 * vocabulary, and a second node with different verbs would rightly disagree
 * with every one of them.
 *
 * WHY THEY ARE NOT IN {@see SourceCallNode} EITHER
 * ------------------------------------------------
 * They were, and the node grew past `ExcessiveClassLength` carrying them. They
 * are a genuine seam rather than a convenient one: every method here reads the
 * authored configuration and a translator, and nothing else — no Source, no
 * CallService, no concurrency, no item. Save-time shape-checking and run-time
 * dispatch are two jobs, and only one of them needs to know what an HTTP call
 * is.
 *
 * Rejection is at flow-SAVE time and it is a rejection, never a silent ignore
 * — the same contract as FlowConfigGuard, for the same reason: an author who
 * wrote something unusable must be told, not left believing it is in use.
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

use OCP\IL10N;
use UnexpectedValueException;

/**
 * Save-time configuration rules owned by the Source Call node.
 *
 * @spec openspec/changes/integriq-flow-nodes/tasks.md#task-3-explicit-failure-fail-closed-attribution-validation-and-scope
 */
final class SourceCallConfigGuard {

	/**
	 * HTTP methods a source-call step may name.
	 *
	 * @var array<int, string>
	 */
	public const SUPPORTED_METHODS = [
		'GET',
		'POST',
		'PUT',
		'PATCH',
		'DELETE',
		'HEAD',
		'OPTIONS',
	];

	/**
	 * Reject an unsupported HTTP method.
	 *
	 * @param array $config The step's authored configuration.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the method is unsupported.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function assertMethod(array $config, IL10N $l10n): void {
		$method = strtoupper(trim((string)($config['method'] ?? '')));
		if ($method === '') {
			throw new UnexpectedValueException(
				$l10n->t(
					'The "method" field must name an HTTP method (%1$s).',
					[implode(', ', self::SUPPORTED_METHODS)]
				)
			);
		}

		if (in_array($method, self::SUPPORTED_METHODS, true) === false) {
			throw new UnexpectedValueException(
				$l10n->t(
					'The "method" field names an unsupported HTTP method "%1$s"; supported methods are %2$s.',
					[$method, implode(', ', self::SUPPORTED_METHODS)]
				)
			);
		}

	}//end assertMethod()

	/**
	 * Reject a malformed `acceptStatuses`.
	 *
	 * @param array $config The step's authored configuration.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the value is not a list of statuses.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function assertAcceptStatuses(array $config, IL10N $l10n): void {
		if (array_key_exists('acceptStatuses', $config) === false) {
			return;
		}

		$accepted = $config['acceptStatuses'];
		if (is_array($accepted) === false || array_is_list($accepted) === false) {
			throw new UnexpectedValueException(
				$l10n->t('The "acceptStatuses" field must be a list of HTTP status codes.')
			);
		}

		foreach ($accepted as $status) {
			if (is_int($status) === false || $status < 100 || $status > 599) {
				throw new UnexpectedValueException(
					$l10n->t('The "acceptStatuses" field must contain only HTTP status codes between 100 and 599.')
				);
			}
		}

	}//end assertAcceptStatuses()

	/**
	 * Reject a malformed `query`, `body` or `headers`.
	 *
	 * @param array $config The step's authored configuration.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When a request part has the wrong shape.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function assertRequestParts(array $config, IL10N $l10n): void {
		foreach (['query', 'headers'] as $field) {
			if (array_key_exists($field, $config) === false) {
				continue;
			}

			if (is_array($config[$field]) === false) {
				throw new UnexpectedValueException(
					$l10n->t('The "%1$s" field must be an object of name/value pairs.', [$field])
				);
			}
		}

		if (array_key_exists('body', $config) === true
			&& is_array($config['body']) === false
			&& is_string($config['body']) === false
		) {
			throw new UnexpectedValueException(
				$l10n->t('The "body" field must be an object or a string.')
			);
		}

		if (array_key_exists('responseMapping', $config) === true && is_array($config['responseMapping']) === false) {
			throw new UnexpectedValueException(
				$l10n->t('The "responseMapping" field must be an object of target key to selector.')
			);
		}

	}//end assertRequestParts()

	/**
	 * Reject an unknown `onError` policy mirrored into node configuration.
	 *
	 * @param array $config The step's authored configuration.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the policy is unknown.
	 *
	 * @spec openspec/changes/integriq-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function assertOnError(array $config, IL10N $l10n): void {
		if (array_key_exists('onError', $config) === false) {
			return;
		}

		$policy = strtolower(trim((string)$config['onError']));
		if (in_array($policy, FlowNodeSupport::ON_ERROR_POLICIES, true) === false) {
			throw new UnexpectedValueException(
				$l10n->t(
					'The "onError" field must be one of %1$s.',
					[implode(', ', FlowNodeSupport::ON_ERROR_POLICIES)]
				)
			);
		}

	}//end assertOnError()
}//end class
