<?php

/**
 * Offers inbound digital post to the document intake inbox.
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

use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcher;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One intake event per received item, on channel `digitalPost`, carrying the
 * sender identity as metadata. The dispatcher is the same one the mail intake
 * uses, so an instance without a document intake inbox is told the item stayed
 * in integriq rather than being told it was delivered.
 *
 * @psalm-api
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md#requirement-inbound-post-feeds-the-document-intake-inbox-req-dpa-003
 */
class DigitalPostInboundJob extends TimedJob {
	/**
	 * The channel every item this job offers arrives on.
	 */
	public const CHANNEL = 'digitalPost';

	/**
	 * The register digital post sources live in.
	 */
	public const REGISTER = 'integriq';

	/**
	 * Poll interval in seconds (fifteen minutes).
	 *
	 * @var integer
	 */
	private const DEFAULT_INTERVAL = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for job scheduling.
	 * @param DigitalPostProviderRegistry $providers The bindings.
	 * @param IntakeDocumentDispatcher $intake The document intake inbox seam.
	 * @param OrObjectService $objectService OpenRegister's object-service facade.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DigitalPostProviderRegistry $providers,
		private readonly IntakeDocumentDispatcher $intake,
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		$this->setInterval(seconds: self::DEFAULT_INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Poll every active digital post source.
	 *
	 * @param mixed $argument Job argument, unused.
	 *
	 * @return void
	 */
	protected function run($argument): void {
		unset($argument);

		foreach ($this->activeSources() as $source) {
			$this->pollSource(source: $source);
		}
	}//end run()

	/**
	 * Poll one source and offer everything it received.
	 *
	 * @param array<string,mixed> $source The source's data.
	 *
	 * @return int How many items were offered to the intake inbox.
	 */
	public function pollSource(array $source): int {
		$config = ($source['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = json_decode($config, true);
		}

		if (is_array($config) === false) {
			return 0;
		}

		$providerId = (string)($config['providerId'] ?? '');
		if ($this->providers->has($providerId) === false) {
			return 0;
		}

		try {
			$items = $this->providers->get($providerId)->pollInbound($config);
		} catch (Throwable $e) {
			$this->logger->warning(
				'digital-post.inbound.failed',
				['source' => (string)($source['slug'] ?? ''), 'error' => $e->getMessage()]
			);

			return 0;
		}

		$offered = 0;
		foreach ($items as $item) {
			$accepted = $this->intake->dispatch(
				[
					'channel' => self::CHANNEL,
					'subject' => (string)($item['subject'] ?? ''),
					'receivedAt' => (string)($item['receivedAt'] ?? gmdate('c')),
					'files' => [($item['document'] ?? [])],
					'metadata' => [
						'sender' => (string)($item['sender'] ?? ''),
						'source' => (string)($source['slug'] ?? ''),
						'provider' => $providerId,
					],
				]
			);

			if ($accepted === true) {
				$offered++;
			}
		}

		return $offered;
	}//end pollSource()

	/**
	 * Every enabled digital post source.
	 *
	 * @return array<int,array<string,mixed>> The sources.
	 */
	public function activeSources(): array {
		try {
			$result = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER, 'schema' => 'source']]
			);
		} catch (Throwable $e) {
			$this->logger->warning('digital-post.inbound.sources-unreadable', ['error' => $e->getMessage()]);

			return [];
		}

		$sources = [];
		foreach (($result['results'] ?? $result) as $entity) {
			$data = $entity;
		if ($entity instanceof ObjectEntity === true) {
			$data = $entity->getObject();
		}
			if (is_array($data) === false || ($data['isEnabled'] ?? false) !== true) {
				continue;
			}

			$config = ($data['configuration'] ?? []);
			if (is_string($config) === true) {
				$config = json_decode($config, true);
			}

			// Only a source that names a digital post provider is polled. The
			// check is made here, on the row, rather than trusted to a filter
			// a backend may ignore.
			if (is_array($config) === false || $this->providers->has((string)($config['providerId'] ?? '')) === false) {
				continue;
			}

			$sources[] = $data;
		}

		return $sources;
	}//end activeSources()
}//end class
