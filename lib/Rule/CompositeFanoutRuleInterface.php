<?php

/**
 * Contract of the CompositeFanoutRule.
 *
 * @category Contract
 * @package  OCA\Integriq\Rule
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

namespace OCA\Integriq\Rule;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see CompositeFanoutRule} that other components call.
 */
interface CompositeFanoutRuleInterface {
	/**
	 * Apply the composite fan-out rule.
	 *
	 * Reads `configuration.compositeFanout` off the rule:
	 * ```
	 * {
	 *   "parent": {"bodyKey": "klantcontact", "register": "pipelinq", "schema": "ticket"},
	 *   "children": [
	 *     {"bodyKey": "involvedParty", "register": "pipelinq", "schema": "contact", "parentField": "klantcontact", "required": false},
	 *     {"bodyKey": "digitaalAdres", "register": "pipelinq", "schema": "digitaalAdres", "parentField": "klantcontact", "required": false}
	 *   ]
	 * }
	 * ```
	 * The parent is written first, then every present child, each stamped
	 * with `parentField` pointing at the created parent's uuid. Any exception
	 * during a child write rolls back (deletes) every object created so far —
	 * parent included — and re-throws a single Exception so the caller's
	 * standard rule-pipeline error handling (HTTP 500, one error body)
	 * applies unchanged.
	 *
	 * @param ObjectEntity $rule The composite-fanout rule configuration object.
	 * @param array $data The current rule data envelope (body/headers/parameters).
	 *
	 * @return array The updated $data with the created parent merged into the body.
	 *
	 * @throws Exception When the parent config is missing or any write fails after rollback.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	public function apply(ObjectEntity $rule, array $data): array;
}
