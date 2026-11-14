<?php

/**
 * Integriq RegistrationRefusal.
 *
 * Why a submission was not registered, with whether the caller may retry.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

use RuntimeException;

/**
 * A refusal of design D2: a code from REASONS and a retryable flag.
 *
 * Thrown inside integriq only; RegistrationService turns it into the answer
 * on the command, so the calling app never sees an exception.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
class RegistrationRefusal extends RuntimeException {

	public const UNKNOWN_TARGET = 'unknown-target';

	public const NOT_ALLOWED = 'not-allowed';

	public const INVALID_HANDOVER = 'invalid-handover';

	public const MAPPING_FAILED = 'mapping-failed';

	public const SOURCE_UNAVAILABLE = 'source-unavailable';

	public const OUTSIDE_REFUSED = 'outside-refused';

	/**
	 * Constructor.
	 *
	 * @param string   $reason    One of the codes above.
	 * @param bool     $retryable Whether a later hand-over of the same submission may succeed.
	 * @param string   $message   What went wrong, for the caller's log.
	 * @param int|null $status    The outside HTTP status, when an outside system answered.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly string $reason,
		private readonly bool $retryable,
		string $message,
		private readonly ?int $status = null,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The refusal for an outside answer that is not a success.
	 *
	 * A 5xx or a 429 means the outside system is not available now and the
	 * caller may retry; any other status is the outside system saying no.
	 *
	 * @param int    $status The outside HTTP status.
	 * @param string $detail What the outside system said.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public static function fromStatus(int $status, string $detail): self {
		if ($status >= 500 || $status === 429 || $status === 0) {
			return new self(
				reason: self::SOURCE_UNAVAILABLE,
				retryable: true,
				message: 'The outside system answered ' . $status . ': ' . $detail,
				status: $status
			);
		}

		return new self(
			reason: self::OUTSIDE_REFUSED,
			retryable: false,
			message: 'The outside system refused with ' . $status . ': ' . $detail,
			status: $status
		);
	}//end fromStatus()

	/**
	 * The refusal code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

	/**
	 * Whether the caller may retry.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function isRetryable(): bool {
		return $this->retryable;
	}//end isRetryable()

	/**
	 * The answer on the command for this refusal.
	 *
	 * @return array{ok:false,code:string,retryable:bool,message:string,status:int|null}
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function toAnswer(): array {
		return ['ok' => false, 'code' => $this->reason, 'retryable' => $this->retryable, 'message' => $this->getMessage(), 'status' => $this->status];
	}//end toAnswer()
}//end class
