<?php

/**
 * Integriq roster-import client (live Zermelo).
 *
 * Reads the lessons of next week from the school's own Zermelo portal,
 * `GET /api/v3/appointments`, through the `roster-zermelo` source row, so
 * the school's address and brokered token come from configuration. Bound
 * only when `roster.import.feature_flag` is on; the mock stays bound
 * otherwise. A system other than Zermelo is refused, never answered from
 * the mock, so a canned lesson cannot pass for a real one.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Roster
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
 * @spec openspec/changes/connectors-education-live-clients/specs/rostering-planninq-target/spec.md#requirement-a-live-rostering-client-reads-lessons-from-the-schools-scheduling-package-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Roster;

use DateTimeImmutable;
use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Adapters\Support\SupplierSourceCall;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Live Zermelo lessons for next week.
 *
 * @spec openspec/changes/connectors-education-live-clients/specs/rostering-planninq-target/spec.md#requirement-a-live-rostering-client-reads-lessons-from-the-schools-scheduling-package-req-007
 */
final class RosterImportClientZermelo extends RosterImportClient {

	/**
	 * The source slug of a school's Zermelo portal.
	 *
	 * @var string
	 */
	public const SYSTEM_ID = 'roster-zermelo';

	/**
	 * The appointments endpoint of the Zermelo REST API.
	 *
	 * @var string
	 */
	public const ENDPOINT = '/api/v3/appointments';

	/**
	 * The appointment fields the Zermelo mapping preset reads.
	 *
	 * @var string
	 */
	public const FIELDS = 'appointmentInstance,start,end,subjects,groups,teachers,locations,cancelled';

	/**
	 * Constructor.
	 *
	 * @param SupplierSourceCall $calls Calls the school's Zermelo source.
	 * @param ITimeFactory       $time  Gives today, to compute next week.
	 */
	public function __construct(
		private readonly SupplierSourceCall $calls,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The transport flavour.
	 *
	 * @return string Always `zermelo`.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/rostering-planninq-target/spec.md#requirement-a-live-rostering-client-reads-lessons-from-the-schools-scheduling-package-req-007
	 */
	public function flavour(): string {
		return 'zermelo';
	}//end flavour()

	/**
	 * The valid appointments of next week, Monday to Monday, in Zermelo's own field names.
	 *
	 * @param string $systemId The rostering source; only `roster-zermelo` has a live client.
	 *
	 * @return array<int,array<string,mixed>> The appointments.
	 *
	 * @throws SupplierCallException When the system has no live client, or Zermelo refuses or fails the call.
	 *
	 * @spec openspec/changes/connectors-education-live-clients/specs/rostering-planninq-target/spec.md#requirement-a-live-rostering-client-reads-lessons-from-the-schools-scheduling-package-req-007
	 */
	public function fetchLessons(string $systemId): array {
		if ($systemId !== self::SYSTEM_ID) {
			throw new SupplierCallException(
				message: sprintf('No live rostering client exists for %s; only %s is live.', $systemId, self::SYSTEM_ID)
			);
		}

		[$start, $end] = $this->nextWeek();
		$answer = $this->calls->json(
			slug: self::SYSTEM_ID,
			endpoint: self::ENDPOINT,
			config: ['query' => ['start' => $start, 'end' => $end, 'valid' => 'true', 'fields' => self::FIELDS]]
		);

		$data = ($answer['response']['data'] ?? null);
		if (is_array($data) === false) {
			throw new SupplierCallException(message: 'Zermelo answered without response.data.');
		}

		return array_values(array_filter($data, 'is_array'));
	}//end fetchLessons()

	/**
	 * Unix start and end of next week: Monday 00:00 to the Monday after, in the server's time zone.
	 *
	 * @return array{0:int,1:int}
	 */
	private function nextWeek(): array {
		$today = DateTimeImmutable::createFromInterface($this->time->now());
		$monday = $today->modify('monday next week')->setTime(0, 0);

		return [$monday->getTimestamp(), $monday->modify('+7 days')->getTimestamp()];
	}//end nextWeek()
}//end class
