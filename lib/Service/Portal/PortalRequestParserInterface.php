<?php

/**
 * Contract of the DSOParserService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Portal
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

namespace OCA\Integriq\Service\Portal;

/**
 * Public operations of {@see \OCA\Integriq\Service\DSOParserService} that other components call.
 */
interface PortalRequestParserInterface {
	/**
	 * Validate a DSO-verzoek payload against the STAM schema.
	 *
	 * Returns an array of validation errors. An empty array means the payload is valid.
	 *
	 * @param array $payload The verzoek payload data.
	 *
	 * @return array Array of validation error objects with 'field', 'error', and 'message' keys.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
	 */
	public function validatePayload(array $payload): array;

	/**
	 * Parse a DSO-verzoek payload into structured data.
	 *
	 * Extracts and normalizes all verzoek fields including aanvrager,
	 * locatie, activiteiten, and bijlagen references.
	 *
	 * @param array $payload The raw verzoek payload.
	 *
	 * @return array The parsed and structured verzoek data.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-2
	 */
	public function parseRequest(array $payload): array;

	/**
	 * Validate a BSN (Burger Service Nummer) using the 11-proef.
	 *
	 * The 11-proef validation multiplies each digit by a weight factor
	 * and checks that the sum is divisible by 11.
	 *
	 * NOTE: This is intentionally duplicated from OpenRegister's BsnFormat.
	 * Integriq cannot depend on OpenRegister being installed since it
	 * connects to external systems independently. If the canonical implementation
	 * changes, this method must be updated to match.
	 *
	 * @param string $bsn The BSN to validate.
	 *
	 * @return bool True if the BSN passes the 11-proef.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
	 *
	 * @see \OCA\OpenRegister\Formats\BsnFormat::validate() Canonical BSN validation (ADR-011)
	 */
	public function validateBSN(string $bsn): bool;

	/**
	 * Validate an ISO 8601 date string.
	 *
	 * @param string $date The date string to validate.
	 *
	 * @return bool True if the date is valid ISO 8601.
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
	 */
	public function validateISODate(string $date): bool;
}
