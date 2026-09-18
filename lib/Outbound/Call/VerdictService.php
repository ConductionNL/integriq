<?php

/**
 * Integriq VerdictService.
 *
 * Records what an outside checker said about an object: pass, fail or
 * pending, with its source and its reason. Integriq stores it beside the
 * object and makes it readable by the owning app. It acts on nothing and
 * changes nothing: a verdict is a fact, and what that fact means is the
 * owning app's decision.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Stores and reads back external verdicts.
 *
 * @spec openspec/changes/outbound-call-delivery-and-replay/specs/outbound-call-log/spec.md#requirement-an-external-verdict-is-recorded-against-the-record-it-judges-req-ocd-006
 */
class VerdictService {

	/**
	 * The schema one verdict is stored under.
	 *
	 * @var string
	 */
	public const SCHEMA = 'verdict';

	/**
	 * The checker was satisfied.
	 *
	 * @var string
	 */
	public const PASS = 'pass';

	/**
	 * The checker was not.
	 *
	 * @var string
	 */
	public const FAIL = 'fail';

	/**
	 * The checker has not finished, which is a real answer and not a pass.
	 *
	 * @var string
	 */
	public const PENDING = 'pending';

	/**
	 * Every state a verdict may carry.
	 *
	 * @var array<int,string>
	 */
	public const STATES = [self::PASS, self::FAIL, self::PENDING];

	/**
	 * Constructor.
	 *
	 * @param ORObjectService $objectService Stores and reads the verdicts.
	 */
	public function __construct(private readonly ORObjectService $objectService) {

	}//end __construct()

	/**
	 * Record one verdict.
	 *
	 * @param string $objectRef The object this verdict is about.
	 * @param string $state One of {@see self::STATES}.
	 * @param string $source Which checker said it.
	 * @param string $reason Why, in the checker's own words.
	 * @param array<string,mixed> $payload What the checker sent.
	 *
	 * @return ObjectEntity The stored verdict.
	 *
	 * @throws InvalidArgumentException When the object or the state is missing or unknown. A
	 *                                  verdict nobody can attach to an object is not a verdict.
	 */
	public function record(
		string $objectRef,
		string $state,
		string $source,
		string $reason = '',
		array $payload = [],
	): ObjectEntity {
		if (trim($objectRef) === '') {
			throw new InvalidArgumentException('A verdict must name the object it judges.');
		}

		$normalised = strtolower(trim($state));
		if (in_array($normalised, self::STATES, true) === false) {
			throw new InvalidArgumentException(
				'Unknown verdict state "' . $state . '": a verdict is pass, fail or pending.'
			);
		}

		if (trim($source) === '') {
			throw new InvalidArgumentException('A verdict must name the checker that gave it.');
		}

		return $this->objectService->saveObject(
			object: [
				'objectRef' => trim($objectRef),
				'state' => $normalised,
				'source' => trim($source),
				'reason' => $reason,
				'payload' => $payload,
				'receivedAt' => (new DateTimeImmutable())->format('c'),
			],
			register: MessageRecorder::REGISTER,
			schema: self::SCHEMA,
		);

	}//end record()

	/**
	 * The verdicts recorded against one object, newest first.
	 *
	 * @param string $objectRef The object.
	 *
	 * @return array<int,array<string,mixed>> The verdicts.
	 */
	public function forObject(string $objectRef): array {
		$matches = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => MessageRecorder::REGISTER,
					'schema' => self::SCHEMA,
					'objectRef' => trim($objectRef),
				],
			]
		);

		$results = ($matches['results'] ?? $matches);
		if (is_array($results) === false) {
			return [];
		}

		$verdicts = [];
		foreach ($results as $row) {
			if (($row instanceof ObjectEntity) === false) {
				continue;
			}

			$verdict = $row->getObject();
			if ((string)($verdict['objectRef'] ?? '') !== trim($objectRef)) {
				continue;
			}

			$verdict['id'] = (string)$row->getUuid();
			$verdicts[] = $verdict;
		}

		usort(
			$verdicts,
			static fn (array $left, array $right): int => (
				strtotime((string)($right['receivedAt'] ?? '')) <=> strtotime((string)($left['receivedAt'] ?? ''))
			)
		);

		return $verdicts;

	}//end forObject()

}//end class
