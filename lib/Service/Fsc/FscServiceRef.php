<?php

/**
 * Integriq FSC service reference.
 *
 * The organisation and service a directory lookup resolves, as the
 * directory path and the lookup's refusals name them.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Fsc
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
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Fsc;

/**
 * One organisation's service in the FSC directory.
 *
 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
 */
final class FscServiceRef {

	/**
	 * Constructor.
	 *
	 * @param string $organisation The target organisation identifier.
	 * @param string $service      The target service identifier.
	 */
	public function __construct(
		public readonly string $organisation,
		public readonly string $service,
	) {

	}//end __construct()

	/**
	 * The directory path of this service.
	 *
	 * @return string The path, each segment URL-encoded.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
	 */
	public function directoryPath(): string {
		return '/organisations/' . rawurlencode($this->organisation) . '/services/' . rawurlencode($this->service);

	}//end directoryPath()

	/**
	 * How refusals name this service.
	 *
	 * @return string `organisation "<o>" and service "<s>"`.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-directory-resolution-req-002
	 */
	public function described(): string {
		return 'organisation "' . $this->organisation . '" and service "' . $this->service . '"';

	}//end described()
}//end class
