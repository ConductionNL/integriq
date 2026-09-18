<?php

/**
 * Integriq CTI Event Intake.
 *
 * Everything that happens to an inbound call payload after the controller has
 * read it: verify, normalise, deduplicate, look the caller up, dispatch.
 *
 * Split out of the controller so each step is testable without a request, and
 * so the controller stays the thing it should be: routing, status codes, and
 * one undifferentiated refusal.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\Integriq\Event\CallEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a verified inbound payload into dispatched call events.
 */
class CtiEventIntake {

	/**
	 * The headers a provider may be handed for verification.
	 *
	 * An allowlist, not the whole request. Handing every header to a binding
	 * makes it easy to write one that authenticates on something it should
	 * not, such as a forwarded address.
	 *
	 * @var string[]
	 */
	public const VERIFIABLE_HEADERS = [
		'authorization',
		'x-api-key',
		'x-signature',
		'x-timestamp',
	];

	/**
	 * Constructor.
	 *
	 * @param CtiSourceResolver $sources Reads CTI sources and their bindings.
	 * @param CallEventNormaliser $normaliser Turns a vendor payload into the shared shape.
	 * @param CallEventDeduplicator $deduplicator Guards against a PBX's retries.
	 * @param CallContextService $context Resolves the caller and their open cases.
	 * @param IEventDispatcher $dispatcher Dispatches the typed event.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CtiSourceResolver $sources,
		private readonly CallEventNormaliser $normaliser,
		private readonly CallEventDeduplicator $deduplicator,
		private readonly CallContextService $context,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The CTI source for an id, or null.
	 *
	 * @param string $sourceId The source id.
	 *
	 * @return array<string, mixed>|null The source's configuration, or null.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function findSource(string $sourceId): ?array {
		return $this->sources->find(sourceId: $sourceId);

	}//end findSource()

	/**
	 * The headers a provider is allowed to verify on.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return array<string, string> The allowed headers, keys lower-cased.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function headersFrom(IRequest $request): array {
		$headers = [];
		foreach (self::VERIFIABLE_HEADERS as $name) {
			$value = (string) $request->getHeader($name);
			if ($value !== '') {
				$headers[$name] = $value;
			}
		}

		return $headers;

	}//end headersFrom()

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
	public function verify(array $source, array $headers, string $rawBody): bool {
		$provider = $this->sources->provider(source: $source);
		if ($provider === null) {
			return false;
		}

		try {
			return $provider->verify(
				sourceConfiguration: ($source['configuration'] ?? []),
				headers: $headers,
				rawBody: $rawBody
			);
		} catch (Throwable $e) {
			$this->logger->warning('[CtiEventIntake] verification threw, refusing: '.$e->getMessage());

			return false;
		}//end try

	}//end verify()

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
	public function handle(array $source, string $sourceId, array $payload): int {
		$provider = $this->sources->provider(source: $source);
		if ($provider === null) {
			return 0;
		}

		$configuration = ($source['configuration'] ?? []);
		$accepted      = 0;

		foreach ($provider->normalize(sourceConfiguration: $configuration, payload: $payload) as $normalised) {
			if (is_array($normalised) === false) {
				continue;
			}

			$callId = (string) ($normalised['callId'] ?? '');
			$kind   = (string) ($normalised['kind'] ?? '');

			if ($this->deduplicator->claim(sourceId: $sourceId, callId: $callId, kind: $kind) === false) {
				// A retry. Already handled, so handling it again would pop the
				// agent's panel a second time for the same ringing phone.
				continue;
			}

			$this->dispatchFor(normalised: $normalised, configuration: $configuration, sourceId: $sourceId);
			$accepted++;
		}

		return $accepted;

	}//end handle()

	/**
	 * Build and dispatch one call event.
	 *
	 * The dispatch is wrapped: a listener that throws must not turn a call
	 * that really happened into a 500 back to the PBX, which would make it
	 * retry an event that was already handled.
	 *
	 * @param array $normalised The normalised event.
	 * @param array $configuration The source configuration.
	 * @param string $sourceId The source id.
	 *
	 * @return void
	 */
	private function dispatchFor(array $normalised, array $configuration, string $sourceId): void {
		$number  = (string) ($normalised['callerNumber'] ?? '');
		$context = $this->context->resolve(e164: $number, sourceConfiguration: $configuration);

		$event = new CallEvent(
			kind: (string) ($normalised['kind'] ?? ''),
			callId: (string) ($normalised['callId'] ?? ''),
			callerNumber: $number,
			caller: $context['caller'],
			openCases: $context['openCases'],
			agentId: (string) ($normalised['agentId'] ?? ''),
			sourceId: $sourceId,
			at: (string) ($normalised['at'] ?? ''),
			durationSeconds: (int) ($normalised['durationSeconds'] ?? 0)
		);

		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->error(
				'[CtiEventIntake] a listener threw on '.$event->cloudEventType().': '.$e->getMessage(),
				['exception' => $e]
			);
		}

	}//end dispatchFor()

}//end class
