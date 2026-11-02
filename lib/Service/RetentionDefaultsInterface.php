<?php

/**
 * Contract of the RetentionDefaults.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

/**
 * Public operations of {@see RetentionDefaults} that other components call.
 */
interface RetentionDefaultsInterface {
	/**
	 * Call logs: 30 days.
	 *
	 * @var int
	 */
	public const CALL_LOG = 2592000000;
}
