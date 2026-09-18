<?php

/**
 * Integriq Document Generation Exception.
 *
 * Raised by a document generation binding when a render cannot be made or a
 * source is not configured to make one. It carries no credential material and
 * no vendor payload: the message is what an operator can act on.
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Exception;

use Exception;

/**
 * A document generation binding could not produce, or could not be reached.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md
 */
class DocumentGenerationException extends Exception {

	/**
	 * Whether the vendor could not be reached at all.
	 *
	 * The distinction is the point of this class. "The vendor refused this
	 * render" and "nobody could ask the vendor" look identical to a caller
	 * that only sees an exception, and they are not the same thing: the
	 * first is an answer, the second is the absence of one, and a document
	 * may well have been produced on the far side.
	 *
	 * @var boolean
	 */
	private bool $unreachable = false;

	/**
	 * Mark this failure as an unreachable vendor and return it.
	 *
	 * @return static
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function asUnreachable(): static {
		$this->unreachable = true;

		return $this;

	}//end asUnreachable()

	/**
	 * Whether the vendor could not be reached.
	 *
	 * @return boolean True when nobody got an answer from the vendor.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function isUnreachable(): bool {
		return $this->unreachable;

	}//end isUnreachable()
}//end class
