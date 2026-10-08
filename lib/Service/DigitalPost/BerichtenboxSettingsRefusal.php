<?php

/**
 * Integriq — a Berichtenbox setting that is not right, with the field it belongs to.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

use RuntimeException;

/**
 * Carries the field name so the settings page can show the message next to it.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-the-transport-certificate-is-held-encrypted-and-named-by-reference-req-dpa-004
 */
class BerichtenboxSettingsRefusal extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $field The field that is wrong.
	 * @param string $message What is wrong with it.
	 */
	public function __construct(private readonly string $field, string $message) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The field that is wrong.
	 *
	 * @return string
	 */
	public function getField(): string {
		return $this->field;
	}//end getField()
}//end class
