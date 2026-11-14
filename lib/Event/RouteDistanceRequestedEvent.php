<?php

/**
 * Integriq RouteDistanceRequested Event.
 *
 * The ADR-041 cross-app command for the road distance between two Dutch
 * postcodes: integriq places both through PDOK, asks the route planner the
 * app's route planner connection is linked to, and writes the one-way
 * distance back. A consumer MUST guard the dispatch with class_exists() and
 * treat an unhandled event as "no distance", for example by manual entry.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "how far by road from this postcode to that one".
 *
 * The result slot carries either the distance or a structured refusal, never both.
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
 */
class RouteDistanceRequestedEvent extends Event {

	/**
	 * Whether an integriq listener handled the request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The distance, once answered.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $result = null;

	/**
	 * The structured refusal, when the request was refused.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp     The calling app id, for example `humaniq`.
	 * @param string $fromPostcode  The postcode the route starts at, for example `2611 AB`.
	 * @param string $toPostcode    The postcode the route ends at.
	 * @param string $mode          `car`, `bicycle`, `e-bike` or `walking`.
	 * @param string $connectionKey The app's declared route planner connection.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $fromPostcode,
		private readonly string $toPostcode,
		private readonly string $mode = 'car',
		private readonly string $connectionKey = 'route-planner',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The calling app id.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The postcode the route starts at.
	 *
	 * @return string The postcode as given.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function getFromPostcode(): string {
		return $this->fromPostcode;
	}//end getFromPostcode()

	/**
	 * The postcode the route ends at.
	 *
	 * @return string The postcode as given.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function getToPostcode(): string {
		return $this->toPostcode;
	}//end getToPostcode()

	/**
	 * The transport mode.
	 *
	 * @return string The mode.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function getMode(): string {
		return $this->mode;
	}//end getMode()

	/**
	 * The route planner connection key.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function getConnectionKey(): string {
		return $this->connectionKey;
	}//end getConnectionKey()

	/**
	 * Record the distance and mark the request handled.
	 *
	 * @param array<string,mixed> $result `distanceKmOneWay`, `routeProvider`, `fromMunicipality`, `toMunicipality`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function setResult(array $result): void {
		$this->handled = true;
		$this->refusal = null;
		$this->result  = $result;
	}//end setResult()

	/**
	 * The distance, when answered.
	 *
	 * @return array<string,mixed>|null The result.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Record a refusal and mark the request handled.
	 *
	 * @param string $code   A machine-readable code, such as `unknown-postcode`.
	 * @param string $reason A human-readable reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function refuse(string $code, string $reason): void {
		$this->handled = true;
		$this->result  = null;
		$this->refusal = ['code' => $code, 'reason' => $reason];
	}//end refuse()

	/**
	 * The structured refusal, when there was one.
	 *
	 * @return array<string,string>|null The refusal.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()

	/**
	 * Whether an integriq listener handled the request.
	 *
	 * @return bool True when handled.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()
}//end class
