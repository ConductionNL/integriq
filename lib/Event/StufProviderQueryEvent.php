<?php

/**
 * Integriq StUF Provider Query Event.
 *
 * The base of the four typed events integriq dispatches when a case system asks a
 * provider question (decision 199). The owning app answers in the synchronous slot;
 * the first answer wins. Silence is not an answer: the receiver turns it into a
 * StUF055 fault, never into an empty La01.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * A provider question with a synchronous answer slot.
 *
 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
 */
abstract class StufProviderQueryEvent extends Event {

	/**
	 * The request is understood and the owning app will not carry it out.
	 *
	 * @var string
	 */
	public const REFUSAL_REFUSED = 'refused';

	/**
	 * The case or document the request names does not exist.
	 *
	 * @var string
	 */
	public const REFUSAL_NOT_FOUND = 'not-found';

	/**
	 * The owning app does not let this source, or its service identity, do this.
	 *
	 * @var string
	 */
	public const REFUSAL_FORBIDDEN = 'forbidden';

	/**
	 * The request collides with what is there: the case already exists.
	 *
	 * @var string
	 */
	public const REFUSAL_CONFLICT = 'conflict';

	/**
	 * The app that answered, null while unanswered.
	 *
	 * @var string|null
	 */
	private ?string $answeredBy = null;

	/**
	 * The answer payload.
	 *
	 * @var array
	 */
	private array $answer = [];

	/**
	 * The refusal reason, null when not refused.
	 *
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * Why the request was refused.
	 *
	 * @var string
	 */
	private string $refusalKind = self::REFUSAL_REFUSED;

	/**
	 * Constructor.
	 *
	 * @param string $sourceId         The integriq StUF-ZKN source the request came in on.
	 * @param string $referentienummer The request's stuurgegevens referentienummer.
	 */
	public function __construct(
		private readonly string $sourceId,
		private readonly string $referentienummer,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The source the request came in on: an app answers only for sources it is configured with.
	 *
	 * @return string The source id.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getSourceId(): string {
		return $this->sourceId;

	}//end getSourceId()

	/**
	 * The request's referentienummer.
	 *
	 * @return string The referentienummer.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getReferentienummer(): string {
		return $this->referentienummer;

	}//end getReferentienummer()

	/**
	 * Refuse the request: the owning app looked and will not or cannot do it.
	 *
	 * @param string $appId  The answering app.
	 * @param string $reason A short reason, safe to return to the case system.
	 * @param string $kind   Why: one of the REFUSAL_* constants. An unknown kind counts as REFUSAL_REFUSED.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-refused-write-is-a-stuf-fault-with-a-refusing-status
	 */
	public function refuse(string $appId, string $reason, string $kind = self::REFUSAL_REFUSED): void {
		if ($this->answeredBy !== null) {
			return;
		}

		if (in_array($kind, [self::REFUSAL_NOT_FOUND, self::REFUSAL_FORBIDDEN, self::REFUSAL_CONFLICT], true) === false) {
			$kind = self::REFUSAL_REFUSED;
		}

		$this->answeredBy  = $appId;
		$this->refusal     = $reason;
		$this->refusalKind = $kind;
		$this->stopPropagation();

	}//end refuse()

	/**
	 * Whether an app answered or refused.
	 *
	 * @return bool True once answered.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function isAnswered(): bool {
		return $this->answeredBy !== null;

	}//end isAnswered()

	/**
	 * The app that answered.
	 *
	 * @return string|null The app id, null while unanswered.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getAnsweredBy(): ?string {
		return $this->answeredBy;

	}//end getAnsweredBy()

	/**
	 * The refusal reason.
	 *
	 * @return string|null The reason, null when not refused.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getRefusal(): ?string {
		return $this->refusal;

	}//end getRefusal()

	/**
	 * Why the request was refused.
	 *
	 * @return string One of the REFUSAL_* constants; REFUSAL_REFUSED when not refused.
	 *
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-a-refused-write-is-a-stuf-fault-with-a-refusing-status
	 */
	public function getRefusalKind(): string {
		return $this->refusalKind;

	}//end getRefusalKind()

	/**
	 * The answer payload.
	 *
	 * @return array The payload, [] while unanswered or refused.
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-each-query-reaches-the-owning-app-as-a-typed-event
	 */
	public function getAnswer(): array {
		return $this->answer;

	}//end getAnswer()

	/**
	 * Store an answer; the first answer wins.
	 *
	 * @param string $appId   The answering app.
	 * @param array  $payload The answer.
	 *
	 * @return void
	 */
	protected function setAnswer(string $appId, array $payload): void {
		if ($this->answeredBy !== null) {
			return;
		}

		$this->answeredBy = $appId;
		$this->answer     = $payload;
		$this->stopPropagation();

	}//end setAnswer()
}//end class
