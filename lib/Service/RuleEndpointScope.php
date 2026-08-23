<?php

/**
 * The object scope an Endpoint lends the Rules attached to it.
 *
 * A Rule record carries no register, no schema and no HTTP method — it is a step
 * in an endpoint's request pipeline, and the endpoint is what says which objects
 * that pipeline touches. `openregister.trigger-object` needs all three, and
 * refuses a partial trigger, so this class is where "which object event does
 * this endpoint produce, on which register and schema, and does it even run this
 * rule" is answered.
 *
 * Split out of {@see \OCA\Integriq\Service\RuleToFlowGenerator} because it
 * is a question about ENDPOINTS, answerable and testable without a flow document
 * in sight.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Derives an object trigger's event, register and schema from an endpoint.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
final class RuleEndpointScope {

	/**
	 * The only endpoint target kind an object trigger can be derived from.
	 *
	 * @var string
	 */
	public const SUPPORTED_TARGET_TYPE = 'register/schema';

	/**
	 * HTTP method → the object event that writing through it produces.
	 *
	 * GET, HEAD and OPTIONS are deliberately absent: a read changes no object,
	 * so there is no event for an object trigger to wait for.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_FOR_METHOD = [
		'POST' => 'object.created',
		'PUT' => 'object.updated',
		'PATCH' => 'object.updated',
		'DELETE' => 'object.deleted',
	];

	/**
	 * Constructor.
	 *
	 * @param MigrationSubject $subject Reads the identifiers a rule can be listed under.
	 */
	public function __construct(
		private readonly MigrationSubject $subject,
	) {

	}//end __construct()

	/**
	 * The endpoint's HTTP method, upper-cased.
	 *
	 * @param array $endpoint The endpoint's serialised record.
	 *
	 * @return string The method.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function methodOf(array $endpoint): string {
		return strtoupper(trim((string)($endpoint['method'] ?? '')));
	}//end methodOf()

	/**
	 * The endpoint's declared target kind.
	 *
	 * @param array $endpoint The endpoint's serialised record.
	 *
	 * @return string The target type.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function targetTypeOf(array $endpoint): string {
		return trim((string)($endpoint['targetType'] ?? ''));
	}//end targetTypeOf()

	/**
	 * The object event this endpoint's method produces, or null when none does.
	 *
	 * @param array $endpoint The endpoint's serialised record.
	 *
	 * @return string|null The event name.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function eventOf(array $endpoint): ?string {
		return (self::EVENT_FOR_METHOD[$this->methodOf(endpoint: $endpoint)] ?? null);
	}//end eventOf()

	/**
	 * The methods that do produce an object event, for naming them in a refusal.
	 *
	 * @return array<int, string> The methods.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function writeMethods(): array {
		return array_keys(self::EVENT_FOR_METHOD);
	}//end writeMethods()

	/**
	 * Split the endpoint's overloaded `targetId` into register and schema.
	 *
	 * One expression rather than three checks, so "two halves" and "neither half
	 * is empty" cannot drift apart: a `register/schema` pair is exactly two
	 * non-empty, slash-free halves.
	 *
	 * @param array $endpoint The endpoint's serialised record.
	 *
	 * @return array{0: string, 1: string} The register and schema; both empty when unusable.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function targetPairOf(array $endpoint): array {
		$halves = [];
		if (preg_match('#^([^/]+)/([^/]+)$#', trim((string)($endpoint['targetId'] ?? '')), $halves) !== 1) {
			return ['', ''];
		}

		return [$halves[1], $halves[2]];
	}//end targetPairOf()

	/**
	 * Whether this endpoint actually lists this rule.
	 *
	 * A rule that is not listed never runs there, so deriving a trigger from
	 * that endpoint would scope the flow by something the rule has nothing to
	 * do with.
	 *
	 * @param array $rule The rule's serialised record.
	 * @param array $endpoint The endpoint's serialised record.
	 *
	 * @return boolean Whether the rule runs on the endpoint.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function runsRule(array $rule, array $endpoint): bool {
		$listed = [];
		foreach ((array)($endpoint['rules'] ?? []) as $entry) {
			if (is_scalar($entry) === true) {
				$listed[] = trim((string)$entry);
			}
		}

		return (array_intersect($this->subject->identifiersOf(entity: $rule), $listed) !== []);
	}//end runsRule()
}//end class
