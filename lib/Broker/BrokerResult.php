<?php

/**
 * Integriq BrokerResult.
 *
 * Three states, not two: published, refused, and not configured. The third is
 * the one that keeps a deployment with no broker from accumulating delivered
 * messages nothing ever received.
 *
 * @category Broker
 * @package  OCA\Integriq\Broker
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
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker;

/**
 * What happened to one broker publish.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
 */
final class BrokerResult {

	/**
	 * The broker took the message and routed it.
	 *
	 * @var string
	 */
	public const STATUS_PUBLISHED = 'published';

	/**
	 * The broker did not take the message, or took it and routed it nowhere.
	 *
	 * @var string
	 */
	public const STATUS_REFUSED = 'refused';

	/**
	 * No broker is configured, so nothing was attempted.
	 *
	 * @var string
	 */
	public const STATUS_UNCONFIGURED = 'not configured';

	/**
	 * Constructor.
	 *
	 * @param string $status One of the three states.
	 * @param string $brokerId The transport the publish was meant for.
	 * @param string|null $reference The broker's own id for the published message, when it gives one.
	 * @param string|null $detail Why it was refused, or what is not configured.
	 * @param integer|null $statusCode The HTTP status the broker answered with, when there was one.
	 */
	private function __construct(
		private readonly string $status,
		private readonly string $brokerId,
		private readonly ?string $reference = null,
		private readonly ?string $detail = null,
		private readonly ?int $statusCode = null,
	) {

	}//end __construct()

	/**
	 * The broker took the message.
	 *
	 * @param string $brokerId The transport.
	 * @param string|null $reference The broker's id for the message.
	 *
	 * @return self The result.
	 */
	public static function published(string $brokerId, ?string $reference = null): self {
		return new self(self::STATUS_PUBLISHED, $brokerId, $reference);

	}//end published()

	/**
	 * The broker did not take the message.
	 *
	 * @param string $brokerId The transport.
	 * @param string $detail Why.
	 * @param integer|null $statusCode The status the broker answered with.
	 *
	 * @return self The result.
	 */
	public static function refused(string $brokerId, string $detail, ?int $statusCode = null): self {
		return new self(self::STATUS_REFUSED, $brokerId, null, $detail, $statusCode);

	}//end refused()

	/**
	 * No broker is configured.
	 *
	 * @param string $brokerId The transport.
	 * @param string $detail What is missing.
	 *
	 * @return self The result.
	 */
	public static function unconfigured(string $brokerId, string $detail): self {
		return new self(self::STATUS_UNCONFIGURED, $brokerId, null, $detail);

	}//end unconfigured()

	/**
	 * The status.
	 *
	 * @return string One of the three states.
	 */
	public function getStatus(): string {
		return $this->status;

	}//end getStatus()

	/**
	 * The transport the publish was meant for.
	 *
	 * @return string The broker id.
	 */
	public function getBrokerId(): string {
		return $this->brokerId;

	}//end getBrokerId()

	/**
	 * The broker's own id for the published message.
	 *
	 * @return string|null The reference, or null.
	 */
	public function getReference(): ?string {
		return $this->reference;

	}//end getReference()

	/**
	 * Why it was refused, or what is not configured.
	 *
	 * @return string|null The detail, or null.
	 */
	public function getDetail(): ?string {
		return $this->detail;

	}//end getDetail()

	/**
	 * The status the broker answered with.
	 *
	 * @return integer|null The status code, or null.
	 */
	public function getStatusCode(): ?int {
		return $this->statusCode;

	}//end getStatusCode()

	/**
	 * Whether the message actually reached the broker and was routed.
	 *
	 * @return boolean True when it was published.
	 */
	public function isPublished(): bool {
		return $this->status === self::STATUS_PUBLISHED;

	}//end isPublished()

	/**
	 * The result as JSON.
	 *
	 * @return array<string,mixed> The result.
	 */
	public function toArray(): array {
		return [
			'status' => $this->status,
			'brokerId' => $this->brokerId,
			'reference' => $this->reference,
			'detail' => $this->detail,
			'statusCode' => $this->statusCode,
		];

	}//end toArray()

}//end class
