<?php

/**
 * A VNG problem answer of the Catalogi facade.
 *
 * The body every refusal of the facade carries: `type`, `code`, `title` and
 * `status`, with the status as the HTTP status.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalogi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Catalogi;

/**
 * One problem answer.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */
final class CatalogiProblem {

	/**
	 * Constructor.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $code   The problem code.
	 * @param string $title  The title.
	 */
	public function __construct(
		private readonly int $status,
		private readonly string $code,
		private readonly string $title,
	) {

	}//end __construct()

	/**
	 * The answer: status and body.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function answer(): array {
		return [
			'status' => $this->status,
			'body' => ['type' => 'about:blank', 'code' => $this->code, 'title' => $this->title, 'status' => $this->status],
		];
	}//end answer()

}//end class
