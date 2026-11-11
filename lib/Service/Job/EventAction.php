<?php

/**
 * Integriq Event Action.
 *
 * Emits a CloudEvent onto the event bus when a scheduled job fires, so a
 * recurring event ("the reconcile window closed", "the nightly export is due")
 * can be published to whatever subscriptions are listening without anything
 * having to poll for it.
 *
 * This is the scheduled counterpart to the object-lifecycle listeners: those
 * emit when something changes, this emits when a clock says so. Both end up in
 * `EventService::processEvent()`, so subscription matching, delivery, retry and
 * the dead-letter path treat a job-emitted event exactly like any other.
 *
 * @category Action
 * @package  OCA\Integriq\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service\Job;

use OCA\Integriq\Service\EventServiceInterface;
use Throwable;

/**
 * Runs event-driven actions wired into the Integriq cron job list.
 */
class EventAction {
	/**
	 * Constructor.
	 *
	 * @param EventServiceInterface $eventService Emits the event onto the bus.
	 */
	public function __construct(
		private readonly EventServiceInterface $eventService,
	) {

	}//end __construct()

	/**
	 * Emit a CloudEvent described by the job's arguments.
	 *
	 * Arguments: `type` and `source` are required — they are the CloudEvents
	 * attributes that make an event routable at all. `subject`, `data` and
	 * `userId` are optional.
	 *
	 * NEVER throws. A background action that throws takes down the whole cron
	 * pass rather than just its own job, so every failure is reported through
	 * the returned trace instead, in the same shape `SynchronizationAction` and
	 * `PingAction` use and the job log renders.
	 *
	 * @param array $argument The arguments for the action.
	 *
	 * @return array The result of the action.
	 */
	public function run(array $argument = []): array {
		$response = ['stackTrace' => []];
		$response['stackTrace'][] = 'Running EventAction';

		$refusal = $this->argumentRefusal(argument: $argument);
		if ($refusal !== null) {
			return $this->failed(response: $response, message: $refusal);
		}

		// Emitting to nobody is not an error, but it is worth saying out loud:
		// from the outside, a bus with no active subscriptions is
		// indistinguishable from a broken one.
		$response['stackTrace'] = array_merge($response['stackTrace'], $this->subscriptionNotes());

		return $this->emit(argument: $argument, response: $response);
	}//end run()

	/**
	 * Why the job's arguments cannot be emitted, or null when they can.
	 *
	 * @param array $argument The job arguments.
	 *
	 * @return string|null The refusal.
	 */
	private function argumentRefusal(array $argument): ?string {
		if (trim((string)($argument['type'] ?? '')) === '' || trim((string)($argument['source'] ?? '')) === '') {
			return 'EventAction needs both a "type" and a "source" argument; nothing was emitted.';
		}

		if ($this->eventData(argument: $argument) === null) {
			return 'The "data" argument must be an object, or a JSON object as a string.';
		}

		return null;

	}//end argumentRefusal()

	/**
	 * The event's data: an array as given, or a JSON object given as a string.
	 *
	 * A job's arguments are authored in a free-text field, so `data` may
	 * arrive as a JSON string. Accept that rather than quietly emitting an
	 * event with no payload.
	 *
	 * @param array $argument The job arguments.
	 *
	 * @return array|null The data, or null when it is neither.
	 */
	private function eventData(array $argument): ?array {
		$data = ($argument['data'] ?? []);
		if (is_array($data) === true) {
			return $data;
		}

		$decoded = json_decode((string)$data, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;

	}//end eventData()

	/**
	 * What to say about the bus's subscriptions: nothing, that nobody listens, or that it could not be checked.
	 *
	 * @return array<int, string> The stack trace lines.
	 */
	private function subscriptionNotes(): array {
		try {
			if ($this->eventService->hasActiveSubscriptions() === false) {
				return ['No active subscriptions — the event is recorded but delivered to nobody.'];
			}
		} catch (Throwable $e) {
			return ['Could not check for active subscriptions: ' . $e->getMessage()];
		}

		return [];

	}//end subscriptionNotes()

	/**
	 * Emit the CloudEvent and report how many subscription messages it created.
	 *
	 * @param array $argument The job arguments, validated.
	 * @param array $response The response so far.
	 *
	 * @return array The response.
	 */
	private function emit(array $argument, array $response): array {
		$type = trim((string)($argument['type'] ?? ''));
		$response['stackTrace'][] = 'Emitting CloudEvent type="' . $type . '" source="' . trim((string)($argument['source'] ?? '')) . '"';

		try {
			$messages = $this->emitCloudEvent(argument: $argument);
		} catch (Throwable $e) {
			return $this->failed(response: $response, message: 'Emitting the event failed: ' . $e->getMessage());
		}

		$response['level'] = 'INFO';
		$response['stackTrace'][] = $response['message'] = 'Emitted CloudEvent "' . $type . '"; ' . count($messages) . ' subscription message(s) created.';

		return $response;

	}//end emit()

	/**
	 * Hand the event to the bus.
	 *
	 * @param array $argument The job arguments, validated.
	 *
	 * @return array The subscription messages created.
	 */
	private function emitCloudEvent(array $argument): array {
		return $this->eventService->emitCloudEvent(
			type: trim((string)($argument['type'] ?? '')),
			source: trim((string)($argument['source'] ?? '')),
			subject: $this->optionalString(argument: $argument, key: 'subject'),
			data: ($this->eventData(argument: $argument) ?? []),
			userId: $this->optionalString(argument: $argument, key: 'userId')
		);

	}//end emitCloudEvent()

	/**
	 * An optional argument as a string, or null when absent.
	 *
	 * @param array  $argument The job arguments.
	 * @param string $key      The argument name.
	 *
	 * @return string|null The value.
	 */
	private function optionalString(array $argument, string $key): ?string {
		$value = ($argument[$key] ?? null);
		if ($value !== null) {
			return (string)$value;
		}

		return null;

	}//end optionalString()

	/**
	 * End the run as an error with a message.
	 *
	 * @param array  $response The response so far.
	 * @param string $message  The message.
	 *
	 * @return array The response.
	 */
	private function failed(array $response, string $message): array {
		$response['level'] = 'ERROR';
		$response['stackTrace'][] = $response['message'] = $message;

		return $response;

	}//end failed()
}//end class
