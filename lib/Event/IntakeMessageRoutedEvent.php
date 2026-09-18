<?php

/**
 * Integriq IntakeMessageRouted Event.
 *
 * The ADR-041 cross-app command contract for channel intake: integriq matched
 * a routing rule and is asking whichever app owns this case type to open one.
 * The listener answers in the result slot with the object it created.
 * Integriq owns the way in; it owns no case type, and names none in code.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use InvalidArgumentException;
use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "a rule says this message opens one of yours".
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the ADR-041 contract is a flat readonly
 * envelope the consumer stubs mirror verbatim; folding it into an array would untype it.
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-routing-rule-maps-a-channel-and-a-payload-onto-a-case-type-req-ic-002
 */
class IntakeMessageRoutedEvent extends Event {

	/**
	 * The object reference the listener created.
	 *
	 * @var string|null
	 */
	private ?string $createdRef = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $message The normalised message as the `intake_message` schema holds it.
	 * @param string $targetSchema The case type the rule names.
	 * @param array<string,mixed> $targetPayload The mapped field values for the new object.
	 * @param array<int,array<string,mixed>> $files The attachments and media, bytes included.
	 * @param string $messageUuid The integriq `intake_message` uuid.
	 * @param string $ruleName The rule that matched.
	 */
	public function __construct(
		private readonly array $message,
		private readonly string $targetSchema,
		private readonly array $targetPayload,
		private readonly array $files = [],
		private readonly string $messageUuid = '',
		private readonly string $ruleName = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The normalised message.
	 *
	 * @return array<string,mixed> The message.
	 */
	public function getMessage(): array {
		return $this->message;

	}//end getMessage()

	/**
	 * The case type the rule names.
	 *
	 * @return string The target schema slug.
	 */
	public function getTargetSchema(): string {
		return $this->targetSchema;

	}//end getTargetSchema()

	/**
	 * The mapped field values.
	 *
	 * @return array<string,mixed> The payload.
	 */
	public function getTargetPayload(): array {
		return $this->targetPayload;

	}//end getTargetPayload()

	/**
	 * The attachments and media, bytes included.
	 *
	 * @return array<int,array<string,mixed>> The files.
	 */
	public function getFiles(): array {
		return $this->files;

	}//end getFiles()

	/**
	 * The integriq `intake_message` uuid.
	 *
	 * @return string The uuid.
	 */
	public function getMessageUuid(): string {
		return $this->messageUuid;

	}//end getMessageUuid()

	/**
	 * The rule that matched.
	 *
	 * @return string The rule name.
	 */
	public function getRuleName(): string {
		return $this->ruleName;

	}//end getRuleName()

	/**
	 * Answer with the object you opened.
	 *
	 * @param string $objectRef The created object's reference.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the reference is empty, which would read as
	 *                                  "created nothing, successfully" downstream.
	 */
	public function setCreatedRef(string $objectRef): void {
		if (trim($objectRef) === '') {
			throw new InvalidArgumentException('A created object reference cannot be empty.');
		}

		$this->createdRef = trim($objectRef);

	}//end setCreatedRef()

	/**
	 * The object the listener opened.
	 *
	 * @return string|null The reference, or null when nobody answered.
	 */
	public function getCreatedRef(): ?string {
		return $this->createdRef;

	}//end getCreatedRef()

}//end class
