<?php

/**
 * Referentienummer generation rule.
 *
 * Generic gateway mechanic added by the vng-klantinteracties-adapter change:
 * stamps a unique message reference (`referentienummer`) on an emitted
 * resource. Defaults to a UUIDv4; a configured numbering scheme may override
 * it. Dialect-agnostic — any Endpoint can attach this rule.
 *
 * @category Rule
 * @package  OCA\Integriq\Rule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Rule;

use OCA\OpenRegister\Db\ObjectEntity;
use Symfony\Component\Uid\Uuid;

/**
 * Referentienummer rule handler.
 *
 * @spec openspec/specs/rule-pipeline/spec.md
 */
class ReferenceNumberRule {
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
	public function apply(ObjectEntity $rule, array $data): array {
		$configuration = $rule->getObject()['configuration']['referentienummer'] ?? [];
		$targetField = $configuration['targetField'] ?? 'referentienummer';

		$data['body'][$targetField] = $this->generate(scheme: $configuration['scheme'] ?? null);

		return $data;
	}//end apply()

	/**
	 * Generate a referentienummer, optionally following a configured scheme.
	 *
	 * @param string|null $scheme Optional scheme string carrying `{uuid}`/`{year}` tokens.
	 *
	 * @return string The generated referentienummer.
	 *
	 * @spec openspec/specs/rule-pipeline/spec.md
	 */
	public function generate(?string $scheme = null): string {
		$uuid = Uuid::v4()->toRfc4122();

		if ($scheme === null || $scheme === '') {
			return $uuid;
		}

		return str_replace(
			['{uuid}', '{year}'],
			[$uuid, date('Y')],
			$scheme
		);
	}//end generate()
}//end class
