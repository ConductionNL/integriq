<?php

/**
 * Integriq LogCleanUpTask.
 *
 * Background job task for cleaning up old logs in the Integriq
 * application. Removes expired call logs and job logs to maintain
 * database performance.
 *
 * @category Cron
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\BackgroundJob;

use DateTime;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Background job task for cleaning up expired logs.
 *
 * Runs periodically to remove old call logs and job logs from the database
 * and prevent storage bloat.
 *
 * @psalm-api
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class LogCleanUpTask extends TimedJob {
	/**
	 * Constructor.
	 *
	 * Initializes the log cleanup task with required dependencies and
	 * configures the background job settings.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param OrObjectService $orObjectService OR object service for log operations.
	 * @param BodyCapturePolicy $bodyCapturePolicy Strips call bodies past their expiry.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly OrObjectService $orObjectService,
		private readonly BodyCapturePolicy $bodyCapturePolicy,
	) {
		parent::__construct(time: $time);

		// Run every minute. @todo change to hour.
		$this->setInterval(seconds: 60);

		// Delay until low-load time.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only run one instance of this job at a time.
		$this->setAllowParallelRuns(allow: true);

	}//end __construct()

	/**
	 * Delete expired objects for a given schema in the openconnector register.
	 *
	 * Finds all objects with a non-null `expires` field that is in the past,
	 * then deletes them one by one via OR ObjectService.
	 *
	 * @param string $schema The schema slug to clean up.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	private function cleanupSchema(string $schema): void {
		$now = (new DateTime())->format('Y-m-d H:i:s');
		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'integriq',
					'schema' => $schema,
					'expires[lt]' => $now,
				],
			]
		);

		$objects = $matches['results'] ?? $matches;
		foreach ($objects as $object) {
			try {
				$this->orObjectService->deleteObject(uuid: $object->getUuid());
			} catch (\Exception $e) {
				// Continue with remaining objects even if one deletion fails.
			}
		}

	}//end cleanupSchema()

	/**
	 * Strip the bodies of every call record past its `bodyExpiresAt`, and keep the record.
	 *
	 * Removes the request and response bodies and `replayRequest`, sets
	 * `bodyCaptured` to false and `bodyExpiredAt` to now, and drops
	 * `bodyExpiresAt` so the record is not picked up again. The record itself
	 * stays until its own `expires`.
	 *
	 * @return int How many records were stripped.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	public function stripExpiredBodies(): int {
		$now = new DateTime();
		$matches = $this->orObjectService->findAll(
			config: [
				'filters' => [
					'register' => 'integriq',
					'schema' => 'call_log',
					'bodyExpiresAt[lt]' => $now->format('Y-m-d H:i:s'),
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		$stripped = 0;
		foreach (($matches['results'] ?? $matches) as $object) {
			$record = $object->getObject();
			$bags = $this->bodyCapturePolicy->strip(
				request: $this->bag(value: ($record['request'] ?? [])),
				response: $this->bag(value: ($record['response'] ?? []))
			);
			$record['request'] = $bags['request'];
			$record['response'] = $bags['response'];
			unset($record['replayRequest'], $record['bodyExpiresAt']);
			$record['bodyCaptured'] = false;
			$record['bodyExpiredAt'] = $now->format('c');

			try {
				$this->orObjectService->saveObject(
					object: $record,
					register: 'integriq',
					schema: 'call_log',
					uuid: $object->getUuid(),
					_rbac: false,
					_multitenancy: false,
					silent: true
				);
				$stripped++;
			} catch (\Exception $e) {
				// Continue with the remaining records even if one write fails.
			}
		}//end foreach

		return $stripped;

	}//end stripExpiredBodies()

	/**
	 * A stored request or response narrowed to an array.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<string,mixed> The value, or an empty array.
	 */
	private function bag(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];

	}//end bag()

	/**
	 * Execute the log cleanup task.
	 *
	 * This method removes expired logs from all log schemas to maintain
	 * database performance and prevent storage bloat.
	 *
	 * @param mixed $argument Task arguments (not used in this implementation).
	 *
	 * @return void
	 *
	 * @psalm-param   mixed $argument
	 * @phpstan-param mixed $argument
	 *
	 * @spec openspec/specs/job-scheduling/spec.md
	 */
	public function run(mixed $argument): void {
		// Strip captured bodies past their expiry; the records stay (REQ-OCD-009).
		$this->stripExpiredBodies();

		// Clear expired call logs.
		$this->cleanupSchema(schema: 'call_log');

		// Clear expired job logs.
		$this->cleanupSchema(schema: 'job_log');

		// Clear expired synchronization contract logs.
		$this->cleanupSchema(schema: 'synchronization_contract_log');

		// Clear expired synchronization logs.
		$this->cleanupSchema(schema: 'synchronization_log');

	}//end run()
}//end class
