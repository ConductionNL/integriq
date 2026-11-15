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

use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistryInterface;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcherInterface;
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
	 * @param DigitalPostProviderRegistryInterface $providers The bindings.
	 * @param IntakeDocumentDispatcherInterface $intake The document intake inbox seam.
	 * @param OrObjectService $objectService OpenRegister's object-service facade.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly DigitalPostProviderRegistryInterface $providers,
		private readonly IntakeDocumentDispatcherInterface $intake,
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
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
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
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	public function pollSource(array $source): int {
		$config = $this->configurationOf(source: $source);
		if ($config === null) {
			return 0;
		}

		$providerId = (string)($config['providerId'] ?? '');
		if ($this->providers->has($providerId) === false) {
			return 0;
		}

		$items = $this->receivedItems(source: $source, config: $config);

		$offered = 0;
		foreach ($items as $item) {
			$metadata = ['sender' => (string)($item['sender'] ?? ''), 'source' => (string)($source['slug'] ?? ''), 'provider' => $providerId];
			if ($this->offer(item: $item, metadata: $metadata) === true) {
				$offered++;
			}
		}

		return $offered;
	}//end pollSource()

	/**
	 * What the source's provider received; nothing, after a logged warning, when polling fails.
	 *
	 * @param array<string,mixed> $source The source's data.
	 * @param array<string,mixed> $config The source's configuration, naming a known provider.
	 *
	 * @return iterable<array<string,mixed>> The received items.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	private function receivedItems(array $source, array $config): iterable {
		try {
			return $this->providers->get((string)($config['providerId'] ?? ''))->pollInbound($config);
		} catch (Throwable $e) {
			$this->logger->warning(
				'digital-post.inbound.failed',
				['source' => (string)($source['slug'] ?? ''), 'error' => $e->getMessage()]
			);

			return [];
		}
	}//end receivedItems()

	/**
	 * The source's configuration, decoded when it is stored as JSON text.
	 *
	 * @param array<string,mixed> $source The source's data.
	 *
	 * @return array<string,mixed>|null The configuration, or null when it is not a map.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	private function configurationOf(array $source): ?array {
		$config = ($source['configuration'] ?? []);
		if (is_string($config) === true) {
			$config = json_decode($config, true);
		}

		if (is_array($config) === false) {
			return null;
		}

		return $config;
	}//end configurationOf()

	/**
	 * Offer one received item to the intake inbox.
	 *
	 * @param array<string,mixed>  $item     The received item.
	 * @param array<string,string> $metadata Its sender, source and provider.
	 *
	 * @return boolean Whether the intake accepted it.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	private function offer(array $item, array $metadata): bool {
		return $this->intake->dispatch(
			[
				'channel' => self::CHANNEL,
				'subject' => (string)($item['subject'] ?? ''),
				'receivedAt' => (string)($item['receivedAt'] ?? gmdate('c')),
				'files' => [($item['document'] ?? [])],
				'metadata' => $metadata,
			]
		) === true;
	}//end offer()

	/**
	 * Every enabled digital post source.
	 *
	 * @return array<int,array<string,mixed>> The sources.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
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
			$data = $this->enabledSourceData(entity: $entity);
			if ($data !== null && $this->namesProvider(source: $data) === true) {
				$sources[] = $data;
			}
		}

		return $sources;
	}//end activeSources()

	/**
	 * A source row's data when the source is enabled.
	 *
	 * @param mixed $entity The row: an ObjectEntity or its data.
	 *
	 * @return array<string,mixed>|null The data, or null when the source is not enabled.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	private function enabledSourceData(mixed $entity): ?array {
		$data = $entity;
		if ($entity instanceof ObjectEntity === true) {
			$data = $entity->getObject();
		}

		if (is_array($data) === false || ($data['isEnabled'] ?? false) !== true) {
			return null;
		}

		return $data;
	}//end enabledSourceData()

	/**
	 * Whether a source names a digital post provider. The check is made here,
	 * on the row, rather than trusted to a filter a backend may ignore.
	 *
	 * @param array<string,mixed> $source The source's data.
	 *
	 * @return boolean True when it is polled.
	 *
	 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
	 */
	private function namesProvider(array $source): bool {
		$config = $this->configurationOf(source: $source);

		return $config !== null && $this->providers->has((string)($config['providerId'] ?? '')) === true;
	}//end namesProvider()
}//end class
