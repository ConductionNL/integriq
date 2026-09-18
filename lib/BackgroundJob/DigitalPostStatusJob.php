<?php

/**
 * Asks the digital post providers what became of the letters they took.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use OCA\Integriq\Service\DigitalPost\DigitalPostResult;
use OCA\Integriq\Service\DigitalPost\DigitalPostService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Only messages that are still on their way are polled: a delivered, read or
 * failed letter is finished, and asking about it again would be a call nobody
 * needs. A status change dispatches `DigitalPostDeliveredEvent`, so the app
 * that asked for the letter never has to poll integriq itself.
 *
 * @psalm-api
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-a-send-is-a-typed-command-with-a-tracked-message-req-dpa-002
 */
class DigitalPostStatusJob extends TimedJob {
	/**
	 * Poll interval in seconds (ten minutes).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param DigitalPostService $service The digital post service.
	 * @param OrObjectService $objectService OpenRegister's object-service facade.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DigitalPostService $service,
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Poll every message that is still on its way.
	 *
	 * @param mixed $argument Job argument, unused.
	 *
	 * @return void
	 */
	protected function run($argument): void {
		unset($argument);

		$open = $this->openMessages();
		if ($open === []) {
			return;
		}

		$changed = $this->service->pollStatuses($open);
		if ($changed > 0) {
			$this->logger->info('digital-post.status.changed', ['count' => $changed]);
		}
	}//end run()

	/**
	 * Messages that have left but have not finished.
	 *
	 * @return array<int,array<string,mixed>> The messages.
	 */
	public function openMessages(): array {
		try {
			$result = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => DigitalPostService::REGISTER,
						'schema' => DigitalPostService::SCHEMA,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('digital-post.status.read-failed', ['error' => $e->getMessage()]);

			return [];
		}

		$open = [];
		foreach (($result['results'] ?? $result) as $entity) {
			$data = ($entity instanceof ObjectEntity === true ? $entity->getObject() : $entity);
			if (is_array($data) === false) {
				continue;
			}

			// A finished letter is not polled again. The filter lives here
			// rather than in the query so a backend that ignores an unknown
			// filter cannot hand back every message and have it look like a
			// deliberate answer.
			if ((string)($data['status'] ?? '') !== DigitalPostResult::STATUS_SENT) {
				continue;
			}

			$open[] = $data;
		}

		return $open;
	}//end openMessages()
}//end class
