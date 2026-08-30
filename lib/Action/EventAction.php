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
 * @package  OCA\Integriq\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Action;

use OCA\Integriq\Service\EventService;
use Throwable;

/**
 * Runs event-driven actions wired into the Integriq cron job list.
 */
class EventAction {
	/**
	 * Constructor.
	 *
	 * @param EventService $eventService Emits the event onto the bus.
	 */
	public function __construct(
		private readonly EventService $eventService,
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

		$type = trim((string)($argument['type'] ?? ''));
		$source = trim((string)($argument['source'] ?? ''));

		if ($type === '' || $source === '') {
			$response['level'] = 'ERROR';
			$response['stackTrace'][] = $response['message'] = 'EventAction needs both a "type" and a "source" argument; nothing was emitted.';
			return $response;
		}

		$subject = ($argument['subject'] ?? null);
		if ($subject !== null) {
			$subject = (string)$subject;
		}

		$data = ($argument['data'] ?? []);
		if (is_array($data) === false) {
			// A job's arguments are authored in a free-text field, so `data` may
			// arrive as a JSON string. Accept that rather than quietly emitting
			// an event with no payload.
			$decoded = json_decode((string)$data, true);
			if (is_array($decoded) === false) {
				$response['level'] = 'ERROR';
				$response['stackTrace'][] = $response['message'] = 'The "data" argument must be an object, or a JSON object as a string.';
				return $response;
			}

			$data = $decoded;
		}

		$userId = ($argument['userId'] ?? null);
		if ($userId !== null) {
			$userId = (string)$userId;
		}

		// Emitting to nobody is not an error, but it is worth saying out loud:
		// from the outside, a bus with no active subscriptions is
		// indistinguishable from a broken one.
		try {
			if ($this->eventService->hasActiveSubscriptions() === false) {
				$response['stackTrace'][] = 'No active subscriptions — the event is recorded but delivered to nobody.';
			}
		} catch (Throwable $e) {
			$response['stackTrace'][] = 'Could not check for active subscriptions: ' . $e->getMessage();
		}

		$response['stackTrace'][] = 'Emitting CloudEvent type="' . $type . '" source="' . $source . '"';

		try {
			$messages = $this->eventService->emitCloudEvent(
				type: $type,
				source: $source,
				subject: $subject,
				data: $data,
				userId: $userId
			);
		} catch (Throwable $e) {
			$response['level'] = 'ERROR';
			$response['stackTrace'][] = $response['message'] = 'Emitting the event failed: ' . $e->getMessage();
			return $response;
		}

		$response['level'] = 'INFO';
		$response['stackTrace'][] = $response['message'] = 'Emitted CloudEvent "' . $type . '"; ' . count($messages) . ' subscription message(s) created.';

		return $response;
	}//end run()
}//end class
