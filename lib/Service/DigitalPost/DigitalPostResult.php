<?php

/**
 * What a digital post provider answered.
 *
 * @category ValueObject
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

/**
 * A send either left, or it did not and the refusal says why. There is no
 * third state, and in particular there is no "simulated delivered" that an
 * operator could mistake for a letter that arrived.
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002
 */
final class DigitalPostResult {
	/**
	 * Waiting to be sent.
	 */
	public const STATUS_QUEUED = 'queued';

	/**
	 * Handed to the provider.
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * The provider says it arrived.
	 */
	public const STATUS_DELIVERED = 'delivered';

	/**
	 * The recipient opened it.
	 */
	public const STATUS_READ = 'read';

	/**
	 * It did not leave, or it came back.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Every status a message may be in.
	 *
	 * @var array<int,string>
	 */
	public const STATUSES = [
		self::STATUS_QUEUED,
		self::STATUS_SENT,
		self::STATUS_DELIVERED,
		self::STATUS_READ,
		self::STATUS_FAILED,
	];

	/**
	 * Constructor.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @param string|null $providerReference The provider's own reference for this message.
	 * @param string $error The provider's reason, when it refused.
	 * @param bool $simulated Whether this went to a binding that sends nothing.
	 */
	public function __construct(
		private readonly string $status,
		private readonly ?string $providerReference = null,
		private readonly string $error = '',
		private readonly bool $simulated = false,
	) {
	}//end __construct()

	/**
	 * The provider took it.
	 *
	 * @param string $status The status it reported.
	 * @param string|null $providerReference The provider's reference.
	 * @param bool $simulated Whether the binding sends nothing.
	 *
	 * @return self An accepted result.
	 */
	public static function accepted(string $status, ?string $providerReference = null, bool $simulated = false): self {
		return new self($status, $providerReference, '', $simulated);
	}//end accepted()

	/**
	 * The send was refused, in whoever's words refused it.
	 *
	 * @param string $error The reason.
	 *
	 * @return self A failed result.
	 */
	public static function refused(string $error): self {
		return new self(self::STATUS_FAILED, null, $error);
	}//end refused()

	/**
	 * The status this message is in.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * The provider's own reference.
	 *
	 * @return string|null The reference.
	 */
	public function getProviderReference(): ?string {
		return $this->providerReference;
	}//end getProviderReference()

	/**
	 * Why the send was refused.
	 *
	 * @return string The reason, empty when nothing was refused.
	 */
	public function getError(): string {
		return $this->error;
	}//end getError()

	/**
	 * Whether this went to a binding that sends nothing.
	 *
	 * @return bool True when the send was simulated.
	 */
	public function isSimulated(): bool {
		return $this->simulated;
	}//end isSimulated()

	/**
	 * Whether the send was refused.
	 *
	 * @return bool True when it failed.
	 */
	public function isRefused(): bool {
		return $this->status === self::STATUS_FAILED;
	}//end isRefused()

	/**
	 * The result as it is written onto the message.
	 *
	 * @return array<string,mixed> Serialisable result.
	 */
	public function toArray(): array {
		return [
			'status' => $this->status,
			'providerReference' => $this->providerReference,
			'lastError' => $this->error,
			'simulated' => $this->simulated,
		];
	}//end toArray()
}//end class
