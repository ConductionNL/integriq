<?php

/**
 * Integriq SynchronizationMessageGate.
 *
 * Checks a synchronization's source objects and target bodies against the
 * message schemas it declares (REQ-MSV-003).
 *
 * @category Service
 * @package  OCA\Integriq\Service\MessageValidation
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
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\MessageValidation;

use OCA\Integriq\Exception\MessageValidationRefusedException;
use Psr\Log\LoggerInterface;

/**
 * The synchronization side of message validation (design D2).
 *
 * A synchronization declares `sourceConfig.validation` and
 * `targetConfig.validation`, each `{mode, messageSchema, operationId?}`.
 * {@see inspect()} checks one message: in mode `refuse` a failing message
 * throws {@see MessageValidationRefusedException}, which the engine's per-item
 * isolation dead-letters; in mode `record` (the default) it answers the
 * finding for the run log and lets the message through. A declared validation
 * is never skipped: an unreadable message schema is a failure, not a pass.
 *
 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
 */
class SynchronizationMessageGate {

	/**
	 * A source object, checked before it is mapped.
	 */
	public const SOURCE = 'source';

	/**
	 * A target body, checked before it is sent.
	 */
	public const TARGET = 'target';

	/**
	 * How many errors a refusal or a finding keeps (design D3).
	 */
	private const KEPT_ERRORS = 20;

	/**
	 * Constructor.
	 *
	 * @param EndpointMessageGate $messages Reads the message schema and runs the checker.
	 * @param LoggerInterface     $logger   Records what record mode let through.
	 */
	public function __construct(
		private readonly EndpointMessageGate $messages,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether a config block declares a validation.
	 *
	 * @param array $config The sourceConfig or targetConfig.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
	 */
	public static function declares(array $config): bool {
		$declared = ($config['validation'] ?? null);

		return is_array($declared) === true && (string)($declared['messageSchema'] ?? '') !== '';
	}//end declares()

	/**
	 * Whether a config block refuses a failing message rather than recording it.
	 *
	 * @param array $config The sourceConfig or targetConfig.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
	 */
	public static function refuses(array $config): bool {
		return (($config['validation']['mode'] ?? 'record') === 'refuse');
	}//end refuses()

	/**
	 * Check one message for one side.
	 *
	 * @param array       $config   The sourceConfig or targetConfig.
	 * @param string      $side     self::SOURCE or self::TARGET.
	 * @param mixed       $message  The source object or the target body.
	 * @param string|null $originId The source object's origin id, for the finding.
	 *
	 * @return array|null Null when the message matches or nothing is declared;
	 *                    the finding when record mode let a failing message through.
	 *
	 * @throws MessageValidationRefusedException In mode refuse, when the message does not match.
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
	 */
	public function inspect(array $config, string $side, mixed $message, ?string $originId = null): ?array {
		if (self::declares(config: $config) === false) {
			return null;
		}

		$declared = $config['validation'];

		// A source object is an item of the source's answer; a target body is
		// the request the engine is about to send.
		$direction = EndpointMessageGate::REQUEST;
		if ($side === self::SOURCE) {
			$direction = EndpointMessageGate::RESPONSE;
		}

		$outcome = $this->messages->checkDeclared(declared: $declared, direction: $direction, body: $message);
		if ($outcome->isValid() === true) {
			return null;
		}

		$finding = [
			'side' => $side,
			'originId' => $originId,
			'messageSchema' => (string)$declared['messageSchema'],
			'errors' => $outcome->firstErrors(self::KEPT_ERRORS),
		];

		if (self::refuses(config: $config) === true) {
			throw new MessageValidationRefusedException(message: self::describe(finding: $finding));
		}

		$this->logger->warning(
			'[integriq] synchronization let a ' . $side . ' message through that does not match its message schema (mode record)',
			['finding' => $finding]
		);

		return $finding;
	}//end inspect()

	/**
	 * The refusal for a declared validation that has no gate to run it.
	 *
	 * @param array  $config The sourceConfig or targetConfig.
	 * @param string $side   self::SOURCE or self::TARGET.
	 *
	 * @return MessageValidationRefusedException
	 *
	 * @spec openspec/changes/mapping-message-schema-validation/specs/message-schema-validation/spec.md#requirement-a-synchronization-validates-source-objects-and-target-bodies-req-msv-003
	 */
	public static function unavailable(array $config, string $side): MessageValidationRefusedException {
		return new MessageValidationRefusedException(
			message: 'The ' . $side . ' message could not be checked against message schema '
			. (string)($config['validation']['messageSchema'] ?? '') . ': message validation is not available'
		);
	}//end unavailable()

	/**
	 * One line naming the side, the schema and the errors, for the dead-letter entry.
	 *
	 * @param array $finding The finding.
	 *
	 * @return string
	 */
	private static function describe(array $finding): string {
		$errors = [];
		foreach ($finding['errors'] as $error) {
			$errors[] = $error['path'] . ': ' . $error['message'];
		}

		return 'The ' . $finding['side'] . ' message does not match message schema ' . $finding['messageSchema']
			. ': ' . implode('; ', $errors);
	}//end describe()
}//end class
