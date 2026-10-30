<?php

/**
 * Contract of the AvgBsnPolicyRule.
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
 * Public operations of {@see AvgBsnPolicyRule} that other components call.
 */
interface AvgBsnPolicyRuleInterface {
	/**
	 * Apply the AVG BSN policy for the given pipeline timing.
	 *
	 * "before": validates (11-proef) and SHA-256-hashes an inbound BSN value
	 * before it reaches storage; an invalid BSN throws (rejecting the write).
	 * "after": defence-in-depth — if the value at the configured path still
	 * looks like a raw, valid BSN (i.e. it was never hashed, for instance
	 * because a mapping bug copied a stored raw value through), the raw value
	 * is stripped from the outbound representation rather than rendered.
	 *
	 * @param ObjectEntity $rule The AVG BSN policy rule configuration object.
	 * @param array $data The current rule data envelope (body/headers/parameters).
	 * @param string $timing Either "before" (inbound hash) or "after" (outbound guard).
	 *
	 * @return array The updated $data.
	 *
	 * @throws Exception When an inbound BSN fails the 11-proef checksum.
	 *
	 * @spec openspec/specs/vng-klantinteracties-adapter/spec.md
	 */
	public function apply(ObjectEntity $rule, array $data, string $timing = 'before'): array;
}
