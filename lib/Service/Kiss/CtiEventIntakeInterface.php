<?php

/**
 * Contract of the CtiEventIntake.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Kiss
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

namespace OCA\Integriq\Service\Kiss;

use OCP\IRequest;

/**
 * Public operations of {@see CtiEventIntake} that other components call.
 */
interface CtiEventIntakeInterface {
	/**
	 * The CTI source for an id, or null.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return array<string, mixed>|null The source's configuration, or null.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function findSource(string $sourceId): ?array;

	/**
	 * The headers a provider is allowed to verify on.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, string> The allowed headers, keys lower-cased.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function headersFrom(IRequest $request): array;

	/**
	 * Whether this request really came from the phone system the source names.
	 *
	 * Fails CLOSED on anything unexpected. A binding that throws is a binding
	 * that did not say yes, and a misconfigured source must refuse rather than
	 * admit.
	 *
	 * @param array $source The CTI source.
	 * @param array $headers The allowed headers.
	 * @param string $rawBody The raw request body.
	 *
	 * @return boolean True when the request is genuine.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function verify(array $source, array $headers, string $rawBody): bool;

	/**
	 * Normalise, deduplicate and dispatch one verified payload.
	 *
	 * @param array $source The CTI source.
	 * @param string $sourceId The source id.
	 * @param array $payload The decoded payload.
	 *
	 * @return integer How many events were accepted and dispatched.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function handle(array $source, string $sourceId, array $payload): int;
}
