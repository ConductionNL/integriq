<?php

/**
 * Contract of the StufProviderReceiver.
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
 * Public operations of {@see StufProviderReceiver} that other components call.
 */
interface StufProviderReceiverInterface {
	/**
	 * The cases route.
	 *
	 * @var string
	 */
	public const SERVICE_CASES = 'cases';

	/**
	 * The persons route.
	 *
	 * @var string
	 */
	public const SERVICE_PERSONS = 'persons';

	/**
	 * Receive one provider request.
	 *
	 * 200 with a zakLa01, npsLa01 or Bv03 when the owning app answered; 400 with
	 * a Fo02/Fo03 when the request is malformed, on the wrong route, carries a
	 * mutatiesoort this adapter does not carry out, or is refused by the app
	 * (403, 404 or 409 when the app says it is forbidden, not found or already
	 * there); 401 when the source or its credentials do not match; 413 when the
	 * body is too large; 500 with a StUF055 fault when nobody answered. A fault
	 * never travels as HTTP 200.
	 *
	 * @param string $rawXml   The raw request body.
	 * @param string $sourceId The integriq StUF-ZKN source the request is for.
	 * @param string $service  SERVICE_CASES or SERVICE_PERSONS.
	 *
	 * @return array The answer (ProviderAnswer).
	 *
	 * @spec openspec/changes/stuf-provider-receiver/specs/stuf-provider-receiver/spec.md#requirement-provider-requests-are-authenticated-against-the-source
	 */
	public function receive(string $rawXml, string $sourceId, string $service): array;

	/**
	 * Receive a provider request a sibling app handed over from its own URL.
	 *
	 * A query is answered as on integriq's route. A write is refused: decision
	 * 206 lets a write in on integriq's own route only.
	 *
	 * @param string $rawXml   The raw request body.
	 * @param string $sourceId The integriq StUF-ZKN source the request is for.
	 * @param string $service  SERVICE_CASES or SERVICE_PERSONS.
	 *
	 * @return array The answer (ProviderAnswer).
	 *
	 * @spec openspec/changes/stuf-provider-writes/specs/stuf-provider-receiver/spec.md#requirement-writes-arrive-on-integriqs-own-route-only
	 */
	public function receiveForwarded(string $rawXml, string $sourceId, string $service): array;
}
