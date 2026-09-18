<?php

/**
 * Integriq Render Outcome.
 *
 * The normalised outcome of one render or status lookup against a document
 * generation binding.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DocumentGeneration
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

namespace OCA\Integriq\Service\DocumentGeneration;

/**
 * What a vendor answered, in one shape.
 *
 * Four statuses, and the fourth is the one worth arguing about.
 *
 * - `queued`: the vendor took the render and is working on it.
 * - `rendered`: the vendor produced a document and handed over a reference.
 * - `failed`: the vendor answered, and the answer was no. A refusal is an
 *   answer: the template is wrong, the data does not fit it, the licence has
 *   expired. Nothing was produced, and asking again unchanged will not help.
 * - `unreachable`: nobody got an answer. The vendor may have rendered the
 *   document, may have queued it, may never have seen the request. Reporting
 *   that as `failed` tells an operator the vendor said no, which is a
 *   sentence nobody said, and it invites a retry that can produce a second
 *   beschikking. This is reported as what it is.
 *
 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md
 */
final class RenderOutcome {

	/**
	 * The recognised lifecycle statuses.
	 *
	 * @var string[]
	 */
	public const STATUSES = ['queued', 'rendered', 'failed', 'unreachable'];

	/**
	 * Constructor.
	 *
	 * @param string $status One of {@see self::STATUSES}.
	 * @param string $providerJobId The vendor's own job or render id, '' when it assigned none.
	 * @param string $fileReference The reference to the produced document, '' unless rendered.
	 * @param string $detail A sentence an operator can act on. Never a credential, never a payload.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $providerJobId = '',
		public readonly string $fileReference = '',
		public readonly string $detail = '',
	) {

	}//end __construct()

	/**
	 * The vendor took the render and is working on it.
	 *
	 * @param string $providerJobId The vendor's job id.
	 * @param string $detail Optional detail.
	 *
	 * @return self
	 */
	public static function queued(string $providerJobId, string $detail = ''): self {
		return new self(status: 'queued', providerJobId: $providerJobId, detail: $detail);

	}//end queued()

	/**
	 * The vendor produced a document.
	 *
	 * @param string $providerJobId The vendor's job id.
	 * @param string $fileReference The reference to the produced document.
	 * @param string $detail Optional detail.
	 *
	 * @return self
	 */
	public static function rendered(string $providerJobId, string $fileReference, string $detail = ''): self {
		return new self(
			status: 'rendered',
			providerJobId: $providerJobId,
			fileReference: $fileReference,
			detail: $detail
		);

	}//end rendered()

	/**
	 * The vendor answered, and the answer was no.
	 *
	 * @param string $detail Why the vendor refused.
	 * @param string $providerJobId The vendor's job id, when it named one.
	 *
	 * @return self
	 */
	public static function failed(string $detail, string $providerJobId = ''): self {
		return new self(status: 'failed', providerJobId: $providerJobId, detail: $detail);

	}//end failed()

	/**
	 * Nobody got an answer from the vendor.
	 *
	 * @param string $detail What was attempted, and against which source.
	 * @param string $providerJobId The vendor's job id, when one was already known.
	 *
	 * @return self
	 */
	public static function unreachable(string $detail, string $providerJobId = ''): self {
		return new self(status: 'unreachable', providerJobId: $providerJobId, detail: $detail);

	}//end unreachable()

	/**
	 * Whether this outcome is the end of the render.
	 *
	 * `unreachable` is deliberately NOT terminal. The render is unfinished
	 * business: something has to ask the vendor again before anybody can say
	 * what happened to it.
	 *
	 * @return boolean True for `rendered` and `failed`.
	 *
	 * @spec openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002
	 */
	public function isTerminal(): bool {
		return in_array($this->status, ['rendered', 'failed'], true);

	}//end isTerminal()

	/**
	 * The plain array shape persisted onto a `documentGenerationJob`.
	 *
	 * @return array{status: string, providerJobId: string, fileReference: string, detail: string}
	 */
	public function toArray(): array {
		return [
			'status' => $this->status,
			'providerJobId' => $this->providerJobId,
			'fileReference' => $this->fileReference,
			'detail' => $this->detail,
		];

	}//end toArray()
}//end class
