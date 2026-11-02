<?php

/**
 * Contract of the FscCallService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\FscConnectivityException;
use OCA\Integriq\Exception\FscDirectoryException;
use OCA\Integriq\Service\Fsc\FscConnectivityProviderInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see FscCallService} that other components call.
 */
interface FscCallServiceInterface {
	/**
	 * Resolve an organisation+service via the directory and dispatch one call.
	 *
	 * @param array $input The call payload: `organisation`, `service`, optional `method`
	 *                     (defaults `POST`), optional `payload` (defaults `[]`).
	 *
	 * @return array{ref: string, statusCode: int, body: mixed} The transport outcome.
	 *
	 * @throws FscConnectivityException When no active source is configured, or a
	 *                                  transport/config failure occurs (a `status: failed`
	 *                                  `fsc_call` IS persisted first).
	 * @throws FscDirectoryException When the organisation/service cannot be resolved
	 *                               (no `fsc_call` record is persisted — nothing was attempted).
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-call-routing-through-the-provider-seam-req-003
	 */
	public function callService(array $input): array;

	/**
	 * List the current `fsc_service` directory cache for the active source.
	 *
	 * Never throws — an unconfigured instance simply has nothing cached,
	 * which is not an error condition for a read (mirrors "unconfigured ->
	 * clean not-configured, no HTTP" but for a list endpoint that is
	 * naturally empty rather than naturally failing).
	 *
	 * @return array<int, array<string, mixed>> The cached resolutions.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#scenario-listing-services-when-unconfigured-returns-an-empty-list-not-an-error
	 */
	public function listResolvableServices(): array;

	/**
	 * Resolve the single active FSC source (`type=fsc`, `isEnabled=true`).
	 *
	 * @return ObjectEntity The resolved source.
	 *
	 * @throws FscConnectivityException When no active FSC source is configured.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#scenario-no-active-source-produces-a-clean-not-configured-failure
	 */
	public function resolveActiveSource(): ObjectEntity;

	/**
	 * Select the provider binding named by `configuration.provider` (default `log`).
	 *
	 * @param array $configuration The FSC source's `configuration` object.
	 *
	 * @return FscConnectivityProviderInterface The resolved provider binding.
	 *
	 * @spec openspec/specs/fsc-connectivity/spec.md#requirement-fsc-provider-abstraction-with-log-and-rest-bindings-req-001
	 */
	public function resolveProvider(array $configuration): FscConnectivityProviderInterface;
}
