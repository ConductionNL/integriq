<?php

/**
 * Contract of the ReferenceNumberRule.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see ReferenceNumberRule} that other components call.
 */
interface ReferenceNumberRuleInterface {
	/**
	 * Apply the referentienummer rule to the current data envelope.
	 *
	 * Reads `configuration.referentienummer` off the rule:
	 * ```
	 * {"scheme": "GEM-{year}-{uuid}", "targetField": "referentienummer"}
	 * ```
	 * `scheme` is optional. When absent the reference is a plain UUIDv4.
	 * When present, the tokens `{uuid}` and `{year}` are substituted (`{uuid}`
	 * with a fresh UUIDv4, `{year}` with the current 4-digit year); any other
	 * literal text in the scheme is preserved verbatim so a municipality can
	 * express e.g. `GEM-{year}-{uuid}`.
	 *
	 * @param ObjectEntity $rule The referentienummer rule configuration object.
	 * @param array $data The current rule data envelope (body/headers/parameters).
	 *
	 * @return array The updated $data with the reference stamped into the body.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	public function apply(ObjectEntity $rule, array $data): array;
}
