<?php

/**
 * Field ownership on a mapping, for two-way synchronisation.
 *
 * A mapping that feeds a two-way exchange declares, per output field, who
 * owns it: `source` (the outside system, such as a service desk) or the name
 * of the local app (such as `stackiq`). The `apply-mapping` step uses that
 * declaration to decide what an UPDATE may carry:
 *
 *  - inbound (outside system to the local app): only the fields `source` owns;
 *  - outbound (local app to the outside system): only the fields `source`
 *    does NOT own.
 *
 * A CREATE carries every field, because there is nothing on the writing side
 * yet that could be overwritten. Combined with a patching write, the outside
 * system wins for the fields it owns and a local-only field is never
 * overwritten. The rule is decided by the mapping, not by timestamps.
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
 * @spec openspec/changes/connectors-service-desk-templates/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

use Adbar\Dot;
use OCA\Integriq\Exception\FlowNodeException;
use OCP\IL10N;
use UnexpectedValueException;

/**
 * Reads and enforces the `ownership` declaration of a mapping.
 *
 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-every-mapped-field-has-an-owner-and-an-update-never-overwrites-the-other-sides-fields-req-sdc-002
 */
class MappingOwnership {

	/**
	 * The owner value that names the outside system.
	 *
	 * @var string
	 */
	public const SOURCE = 'source';

	/**
	 * The two directions an `apply-mapping` step can enforce.
	 *
	 * @var array<int, string>
	 */
	public const MODES = ['inbound', 'outbound'];

	/**
	 * Reject an `ownership`/`exists` step configuration that cannot work.
	 *
	 * @param array $config The step's authored configuration.
	 * @param IL10N $l10n Translations for the rejection message.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the configuration is unusable.
	 *
	 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-every-mapped-field-has-an-owner-and-an-update-never-overwrites-the-other-sides-fields-req-sdc-002
	 */
	public static function assertConfig(array $config, IL10N $l10n): void {
		$mode = $config['ownership'] ?? null;
		if ($mode === null || $mode === '') {
			if (array_key_exists('exists', $config) === true) {
				throw new UnexpectedValueException(
					$l10n->t('The "exists" field only applies together with "ownership".')
				);
			}

			return;
		}

		if (is_string($mode) === false || in_array($mode, self::MODES, true) === false) {
			throw new UnexpectedValueException(
				$l10n->t('The "ownership" field must be inbound or outbound.')
			);
		}

		$exists = $config['exists'] ?? null;
		if (is_string($exists) === false || trim($exists) === '') {
			throw new UnexpectedValueException(
				$l10n->t('The "ownership" field needs "exists": the dot-path of the record id on the writing side.')
			);
		}

	}//end assertConfig()

	/**
	 * The owners a mapping declares, after checking every mapped field has one.
	 *
	 * @param array $definition The mapping object (its `mapping` and `ownership`).
	 * @param string $reference The authored mapping reference, for the message.
	 * @param IL10N $l10n Translations for the failure message.
	 *
	 * @return array<string, string> Output field to owner.
	 *
	 * @throws FlowNodeException When a mapped field has no owner.
	 *
	 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-every-mapped-field-has-an-owner-and-an-update-never-overwrites-the-other-sides-fields-req-sdc-002
	 */
	public static function ownersOf(array $definition, string $reference, IL10N $l10n): array {
		$unowned = self::unownedFields(definition: $definition);
		if ($unowned !== []) {
			throw new FlowNodeException(
				message: $l10n->t(
					'The mapping "%1$s" does not say who owns %2$s, so an update could overwrite them. Add them to its ownership.',
					[$reference, implode(', ', $unowned)]
				),
				details: ['kind' => 'mapping', 'mapping' => $reference, 'unowned' => $unowned]
			);
		}

		$owners = [];
		foreach ((array)($definition['ownership'] ?? []) as $field => $owner) {
			$owners[(string)$field] = (string)$owner;
		}

		return $owners;
	}//end ownersOf()

	/**
	 * The mapped fields that have no non-empty owner.
	 *
	 * @param array $definition The mapping object (its `mapping` and `ownership`).
	 *
	 * @return array<int, string> The unowned output fields, in mapping order.
	 *
	 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-every-mapped-field-has-an-owner-and-an-update-never-overwrites-the-other-sides-fields-req-sdc-002
	 */
	public static function unownedFields(array $definition): array {
		$ownership = $definition['ownership'] ?? [];
		if (is_array($ownership) === false) {
			$ownership = [];
		}

		$unowned = [];
		foreach (array_keys((array)($definition['mapping'] ?? [])) as $field) {
			$owner = $ownership[$field] ?? null;
			if (is_string($owner) === false || trim($owner) === '') {
				$unowned[] = (string)$field;
			}
		}

		return $unowned;
	}//end unownedFields()

	/**
	 * Whether an `exists` value means "no record on the writing side yet".
	 *
	 * @param mixed $value The value found at the `exists` path.
	 *
	 * @return boolean True for null, an empty string or an empty list.
	 *
	 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-every-mapped-field-has-an-owner-and-an-update-never-overwrites-the-other-sides-fields-req-sdc-002
	 */
	public static function isEmpty(mixed $value): bool {
		if ($value === null || $value === [] || $value === false) {
			return true;
		}

		return is_string($value) === true && trim($value) === '';
	}//end isEmpty()

	/**
	 * Narrow an update to the fields the writing side may change.
	 *
	 * Inbound keeps the fields `source` owns; outbound keeps every other
	 * owned field. A field in the result that the mapping does not list (a
	 * pass-through key) is dropped: nobody declared it, so an update must not
	 * carry it.
	 *
	 * @param array $mapped The full mapped result.
	 * @param array<string, string> $ownership Output field to owner.
	 * @param string $mode `inbound` or `outbound`.
	 *
	 * @return array The mapped result with only the writing side's fields.
	 *
	 * @spec openspec/changes/connectors-service-desk-templates/specs/service-desk-connectors/spec.md#requirement-every-mapped-field-has-an-owner-and-an-update-never-overwrites-the-other-sides-fields-req-sdc-002
	 */
	public static function keepWriterFields(array $mapped, array $ownership, string $mode): array {
		$source = new Dot($mapped);
		$kept = new Dot();

		foreach ($ownership as $field => $owner) {
			$ownedBySource = ($owner === self::SOURCE);
			if ($ownedBySource !== ($mode === 'inbound')) {
				continue;
			}

			if ($source->has($field) === true) {
				$kept->set($field, $source->get($field));
			}
		}

		return $kept->all();
	}//end keepWriterFields()
}//end class
