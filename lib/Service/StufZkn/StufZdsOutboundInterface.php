<?php

/**
 * Contract of the StufZdsOutbound.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\StufZkn
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

namespace OCA\Integriq\Service\StufZkn;

/**
 * Public operations of {@see StufZdsOutbound} that other components call.
 */
interface StufZdsOutboundInterface {
	/**
	 * Send a kennisgeving (creeerZaak, actualiseerZaak, vrijBericht) and classify the answer.
	 *
	 * @param array                                     $source    The source, `id` set.
	 * @param string                                    $operation The operation.
	 * @param array{referentienummer:string,xml:string} $message   The built message.
	 * @param array                                     $context   correlationId, sourceApp, caseIdentification.
	 *
	 * @return array The result (ZdsResult).
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function kennisgeving(array $source, string $operation, array $message, array $context): array;

	/**
	 * Send a question (geefZaakdetails, genereerZaakIdentificatie) and conclude its row at once.
	 *
	 * A question is answered in the request, so it is not retried in the background.
	 *
	 * @param array                                     $source    The source, `id` set.
	 * @param string                                    $operation The operation.
	 * @param array{referentienummer:string,xml:string} $message   The built message.
	 * @param array                                     $context   correlationId, sourceApp, caseIdentification.
	 *
	 * @return array The result (ZdsResult) plus `answer`, the answer XML.
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-operations
	 */
	public function question(array $source, string $operation, array $message, array $context): array;

	/**
	 * Re-send a kennisgeving whose last attempt failed transiently (called by the retry job).
	 *
	 * @param string $messageId The stuf_message uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-circuit-breaker-and-retry
	 */
	public function retry(string $messageId): void;
}
