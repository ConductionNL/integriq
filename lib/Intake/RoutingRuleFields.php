<?php

/**
 * Integriq RoutingRuleFields: the fields of a routing rule an administrator
 * saves, read and checked one by one.
 *
 * @category Intake
 * @package  OCA\Integriq\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Intake;

use OCA\Integriq\Exception\IntakeRoutingException;

/**
 * One routing rule as the administrator wrote it; built by
 * {@see IntakeRoutingService::validateRule()}.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */
final class RoutingRuleFields {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $rule The rule as the administrator wrote it.
	 */
	public function __construct(private readonly array $rule) {

	}//end __construct()

	/**
	 * Refuse a rule whose channel no adapter answers to.
	 *
	 * @param IntakeChannelRegistryInterface $registry The channels this instance has.
	 *
	 * @return void
	 *
	 * @throws IntakeRoutingException When the channel is unknown.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
	 */
	public function requireKnownChannel(IntakeChannelRegistryInterface $registry): void {
		$channelId = trim((string)($this->rule['channelId'] ?? ''));
		if ($registry->has($channelId) === false) {
			throw new IntakeRoutingException(
				message: 'No intake channel adapter answers to "' . $channelId . '".'
			);
		}

	}//end requireKnownChannel()

	/**
	 * The case type the rule opens.
	 *
	 * @return string The target schema.
	 *
	 * @throws IntakeRoutingException When the rule names none.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
	 */
	public function targetSchema(): string {
		$targetSchema = trim((string)($this->rule['targetSchema'] ?? ''));
		if ($targetSchema === '') {
			throw new IntakeRoutingException(message: 'A routing rule must name the case type it opens.');
		}

		return $targetSchema;

	}//end targetSchema()

	/**
	 * The target fields the rule writes: its mapped fields, then its location field.
	 *
	 * @return array<int,int|string> The field names.
	 *
	 * @throws IntakeRoutingException When the field mapping is not an object.
	 *
	 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
	 */
	public function targets(): array {
		$mapped = ($this->rule['fieldMapping'] ?? []);
		if (is_array($mapped) === false) {
			throw new IntakeRoutingException(message: 'The field mapping must be an object of target field to source.');
		}

		$targets = array_keys($mapped);
		$locationField = trim((string)($this->rule['locationField'] ?? ''));
		if ($locationField !== '') {
			$targets[] = $locationField;
		}

		return $targets;

	}//end targets()
}//end class
