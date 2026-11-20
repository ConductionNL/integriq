<?php

/**
 * Integriq DestinationSubmitterInterface.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenFormulieren
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenFormulieren;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;

/**
 * Submit one Open Formulieren submission into the object its form mapping names.
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
 */
interface DestinationSubmitterInterface {

	/**
	 * Map the submission and create its destination object in this request.
	 *
	 * The submission id is the idempotency key, so a redelivered submission
	 * answers with the first object's reference and creates no second one.
	 *
	 * @param string               $formSlug     The Open Formulieren form slug.
	 * @param string               $submissionId The Open Formulieren submission id.
	 * @param array<string, mixed> $values       The submitted values.
	 * @param string               $payloadHash  The sha256 of the received body, for the audit log.
	 *
	 * @return array<string, mixed> OpenRegister's answer: reference, id, receivedAt, confirmation, objects.
	 *
	 * @throws FormSubmitRefusedException 422 with findings when there is no usable mapping or the destination
	 *                                    refuses the payload; other statuses as OpenRegister answers.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/specs/open-formulieren-intake/spec.md#requirement-an-open-formulieren-submit-must-create-its-case-in-the-inbound-request
	 */
	public function submit(string $formSlug, string $submissionId, array $values, string $payloadHash): array;
}//end interface
