<?php

/**
 * Integriq DSO Connection Unavailable Exception.
 *
 * Raised when a STAM push cannot be stored because the DSO connection is not
 * usable: no `dso-stam` consumer, no acting account, an account that does not
 * exist or is disabled, or an account without the rights to store a
 * `dso_verzoek`. The controller answers 503, because the Digikoppeling
 * Koppelvlakstandaard ebMS2 (5.11.2) treats a 503 as recoverable: DSO-LV keeps
 * the verzoek and delivers it again once an administrator fixed the connection.
 *
 * The message is secret-free: it names the reason and the account id, never
 * trust material.
 *
 * @category Exception
 * @package  OCA\Integriq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-stam-intake-acts-as-the-dso-connections-account-req-dso-070
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * Thrown when the DSO connection cannot give a push a usable identity.
 *
 * @spec openspec/changes/dso-intake-through-an-integriq-connection/specs/dso-omgevingsloket/spec.md#requirement-the-stam-intake-acts-as-the-dso-connections-account-req-dso-070
 */
class DsoConnectionUnavailableException extends Exception {

	public const NO_CONNECTION = 'no_connection';

	public const AMBIGUOUS_CONNECTION = 'ambiguous_connection';

	public const NO_ACCOUNT = 'no_account';

	public const ACCOUNT_UNKNOWN = 'account_unknown';

	public const ACCOUNT_DISABLED = 'account_disabled';

	public const ACCOUNT_LACKS_RIGHTS = 'account_lacks_rights';

	public const RIGHTS_UNVERIFIABLE = 'rights_unverifiable';

	/**
	 * The error code the STAM endpoint answers for each reason.
	 *
	 * @var array<string, string>
	 */
	private const ERROR_CODES = [
		self::NO_CONNECTION => 'connection_not_configured',
		self::AMBIGUOUS_CONNECTION => 'connection_not_configured',
		self::NO_ACCOUNT => 'account_unavailable',
		self::ACCOUNT_UNKNOWN => 'account_unavailable',
		self::ACCOUNT_DISABLED => 'account_unavailable',
		self::ACCOUNT_LACKS_RIGHTS => 'account_lacks_rights',
		self::RIGHTS_UNVERIFIABLE => 'account_lacks_rights',
	];

	/**
	 * The channel of the DSO STAM intake, the prefix of its error codes.
	 *
	 * @var string
	 */
	public const CHANNEL_DSO = 'dso';

	/**
	 * The channel of the Open Formulieren intake, the prefix of its error codes.
	 *
	 * @var string
	 */
	public const CHANNEL_OPEN_FORMULIEREN = 'openformulieren';

	/**
	 * Constructor.
	 *
	 * The same reasons serve every intake that runs as a consumer's account.
	 * `$channel` only prefixes the error code, so DSO-LV keeps answering
	 * `dso_account_unavailable` and Open Formulieren gets
	 * `openformulieren_account_unavailable`.
	 *
	 * @param string $reason  One of the reason constants.
	 * @param string $message A secret-free description for the log.
	 * @param string $channel The intake, one of the CHANNEL_* constants.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/design.md
	 */
	public function __construct(
		private readonly string $reason,
		string $message,
		private readonly string $channel = self::CHANNEL_DSO,
	) {
		parent::__construct(message: $message);

	}//end __construct()

	/**
	 * The machine reason, one of the reason constants.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/design.md
	 */
	public function getReason(): string {
		return $this->reason;

	}//end getReason()

	/**
	 * The error code the STAM endpoint answers with.
	 *
	 * @return string The error code, for example `dso_account_unavailable`.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/design.md
	 */
	public function getErrorCode(): string {
		return $this->channel . '_' . (self::ERROR_CODES[$this->reason] ?? 'connection_not_configured');

	}//end getErrorCode()

	/**
	 * The intake this refusal belongs to.
	 *
	 * @return string One of the CHANNEL_* constants.
	 *
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/design.md
	 */
	public function getChannel(): string {
		return $this->channel;

	}//end getChannel()
}//end class
