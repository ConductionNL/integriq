<?php

/**
 * Integriq RegisterImportRun
 *
 * State of one register descriptor import: the output channel, the
 * descriptor as it is merged with its fragments, the fragment signature and
 * the version the import is tagged with.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCP\Migration\IOutput;

/**
 * Run object built by {@see InitializeRegister::run()}; never leaves that class.
 */
final class RegisterImportRun {

	/**
	 * The decoded register descriptor, merged with every fragment read so far.
	 *
	 * Untyped on purpose: it holds whatever the descriptor JSON decodes to.
	 *
	 * @var mixed
	 */
	public mixed $descriptor = null;

	/**
	 * Concatenated `<file>:<md5>;` entries of every merged fragment.
	 *
	 * @var string
	 */
	public string $fragmentSignature = '';

	/**
	 * The version the import is tagged with.
	 *
	 * @var string
	 */
	public string $version = '';

	/**
	 * Constructor.
	 *
	 * @param IOutput $output Progress/info channel piped to occ stdout.
	 */
	public function __construct(
		public readonly IOutput $output,
	) {

	}//end __construct()
}//end class
