<?php

/**
 * Contract of the DsoConnectionAlerts.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Portal;

use OCA\Integriq\Exception\DsoConnectionUnavailableException;

/**
 * Public operations of {@see \OCA\Integriq\Service\Dso\DsoConnectionAlerts} that other components call.
 */
interface PortalConnectionAlertsInterface {
	/**
	 * The notification subject.
	 *
	 * @var string
	 */
	public const SUBJECT = 'dso_connection_alert';

	/**
	 * Reason: OpenRegister refused a write anyway.
	 *
	 * @var string
	 */
	public const REASON_NOT_STORED = 'verzoek_not_stored';

	/**
	 * Reason: a bijlage job found no usable account.
	 *
	 * @var string
	 */
	public const REASON_JOB_ACCOUNT = 'job_account_unavailable';

	/**
	 * Reason: OpenRegister refused an Open Formulieren submission write anyway.
	 *
	 * @var string
	 */
	public const REASON_SUBMISSION_NOT_STORED = 'submission_not_stored';

	/**
	 * A signed webhook delivery whose work OpenRegister refused (503).
	 *
	 * @var string
	 */
	public const REASON_DELIVERY_NOT_STORED = 'delivery_not_stored';

	/**
	 * Reason: the migration created a connection without an account.
	 *
	 * @var string
	 */
	public const REASON_CHOOSE_ACCOUNT = 'choose_account';

	/**
	 * Notify the administrators, unless this reason was sent within the hour.
	 *
	 * The Open Formulieren intake uses the same alerts with its own channel, so
	 * the throttle and the rendered text are per intake.
	 *
	 * @param string $reason  The reason: a DsoConnectionUnavailableException reason or a REASON_* constant.
	 * @param string $channel The intake: DsoConnectionUnavailableException::CHANNEL_DSO or CHANNEL_OPEN_FORMULIEREN.
	 *
	 * @return bool True when a notification went out.
	 *
	 * @spec openspec/changes/dso-intake-through-an-integriq-connection/tasks.md#task-2
	 * @spec openspec/changes/openformulieren-intake-through-an-integriq-connection/design.md
	 */
	public function notify(string $reason, string $channel = DsoConnectionUnavailableException::CHANNEL_DSO): bool;
}
