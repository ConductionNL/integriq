<?php

/**
 * Integriq DigitalPostAccountChange: one request to set the digital post
 * account, and the warnings its checks raise.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The chosen account, the connection it is saved on, and the non-blocking
 * warnings collected while checking it.
 *
 * @spec openspec/changes/digital-post-service-account-and-log-redaction/specs/digital-post-adapter/spec.md#requirement-digital-post-is-stored-as-its-service-account-req-dpa-007
 */
final class DigitalPostAccountChange {
	/**
	 * The non-blocking warnings, in the order they were raised.
	 *
	 * @var list<string>
	 */
	public array $warnings = [];

	/**
	 * Constructor.
	 *
	 * @param string            $userId   The chosen uid; empty clears the account.
	 * @param ObjectEntity|null $consumer The digital post connection, or null when there is none yet.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly ObjectEntity|null $consumer,
	) {

	}//end __construct()
}//end class
