<?php

/**
 * Integriq — remove the CloudEvent recursion storm's rows.
 *
 * @category Command
 * @package  OCA\Integriq\Command
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/integriq
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes the `event` rows that were generated from other events, and the
 * `event_message` rows whose event is gone.
 *
 * Before the listener guard, every stored CloudEvent was itself an object
 * create, so it produced another CloudEvent whose source is
 * `/objects/com.nextcloud.openregister.object.created` (the type of the event
 * it came from; a genuine event's source is `/objects/<object type>`). The dev
 * instance held 45,715 events, 45,398 of them of that kind. A genuine event is
 * never touched. Dry run unless --apply is given, like integriq:contracts:dedupe.
 *
 * @spec openspec/changes/stop-cloudevent-recursion/tasks.md
 */
class PurgeEventRecursion extends Command {

	/**
	 * The sources only a CloudEvent generated from a CloudEvent carries.
	 *
	 * @var array<int, string>
	 */
	public const RECURSION_SOURCES = [
		'/objects/com.nextcloud.openregister.object.created',
		'/objects/com.nextcloud.openregister.object.updated',
		'/objects/com.nextcloud.openregister.object.deleted',
	];

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objects  OpenRegister's object service.
	 * @param int             $pageSize Rows per read and per delete batch.
	 */
	public function __construct(
		private readonly OrObjectService $objects,
		private readonly int $pageSize = 500,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:events:purge-recursion')
			->setDescription(
				'Delete the CloudEvents generated from other CloudEvents and the event messages '
				. 'whose event is gone. Dry run unless --apply is given.'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Actually delete. Without this flag the command only reports what it would delete.'
			);
	}//end configure()

	/**
	 * Is this event one the recursion generated?
	 *
	 * @param array<string, mixed> $event The event object.
	 *
	 * @return boolean
	 */
	public static function isRecursion(array $event): bool {
		return in_array(($event['source'] ?? null), self::RECURSION_SOURCES, true);
	}//end isRecursion()

	/**
	 * Is this message's event gone? A message that names no event stays.
	 *
	 * @param array<string, mixed> $message The event_message object.
	 * @param array<string, true>  $kept    Uuids of the events that remain.
	 *
	 * @return boolean
	 */
	public static function isOrphan(array $message, array $kept): bool {
		$eventUuid = ($message['event'] ?? '');
		if (is_string($eventUuid) === false || $eventUuid === '') {
			return false;
		}

		return isset($kept[$eventUuid]) === false;
	}//end isOrphan()

	/**
	 * Plan, report and (with --apply) delete.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return integer 0 on success; 1 when OpenRegister removed fewer rows than planned.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$apply = (bool)$input->getOption('apply');
		$plan = $this->plan();

		$mode = '<comment>DRY RUN</comment>: nothing will be deleted';
		if ($apply === true) {
			$mode = '<info>Applying</info>';
		}

		$output->writeln($mode);
		$output->writeln(
			sprintf(
				'Events:          %d (%d generated from events, %d genuine)',
				$plan['events'],
				count($plan['doomedEvents']),
				($plan['events'] - count($plan['doomedEvents']))
			)
		);
		$output->writeln(sprintf('Messages:        %d', $plan['messages']));
		$output->writeln(sprintf('Orphan messages: %d', count($plan['doomedMessages'])));

		if ($apply === false) {
			if ($plan['doomedEvents'] !== [] || $plan['doomedMessages'] !== []) {
				$output->writeln('<comment>Re-run with --apply to delete them.</comment>');
			}

			return 0;
		}

		return $this->apply(plan: $plan, output: $output);
	}//end execute()

	/**
	 * Scan both schemas and decide what goes.
	 *
	 * @return array{events: int, messages: int, doomedEvents: array<int, string>, doomedMessages: array<int, string>}
	 */
	private function plan(): array {
		$kept = [];
		$events = 0;
		$doomedEvents = [];
		foreach ($this->rows(schema: 'event') as $uuid => $event) {
			$events++;
			if (self::isRecursion(event: $event) === true) {
				$doomedEvents[] = $uuid;
				continue;
			}

			$kept[$uuid] = true;
		}

		$messages = 0;
		$doomedMessages = [];
		foreach ($this->rows(schema: 'event_message') as $uuid => $message) {
			$messages++;
			if (self::isOrphan(message: $message, kept: $kept) === true) {
				$doomedMessages[] = $uuid;
			}
		}

		return [
			'events' => $events,
			'messages' => $messages,
			'doomedEvents' => $doomedEvents,
			'doomedMessages' => $doomedMessages,
		];
	}//end plan()

	/**
	 * Delete the plan and report what OpenRegister actually removed.
	 *
	 * @param array{events: int, messages: int, doomedEvents: array<int, string>, doomedMessages: array<int, string>} $plan   The plan.
	 * @param OutputInterface                                                                                          $output The output.
	 *
	 * @return integer 0 when everything planned was removed, else 1.
	 */
	private function apply(array $plan, OutputInterface $output): int {
		$deletedEvents = $this->delete(uuids: $plan['doomedEvents']);
		$deletedMessages = $this->delete(uuids: $plan['doomedMessages']);
		$output->writeln(sprintf('Deleted:         %d event(s), %d message(s)', $deletedEvents, $deletedMessages));
		$output->writeln(sprintf('Events left:     %d', ($plan['events'] - $deletedEvents)));

		// Count the result, never the plan: a run that plans N deletions and
		// removes fewer must not read as a finished cleanup.
		if ($deletedEvents !== count($plan['doomedEvents']) || $deletedMessages !== count($plan['doomedMessages'])) {
			$output->writeln('<error>OpenRegister removed fewer rows than planned. Do not treat this run as a cleanup.</error>');

			return 1;
		}

		return 0;
	}//end apply()

	/**
	 * Every object of one integriq schema, page by page, keyed by uuid.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return \Generator<string, array<string, mixed>>
	 */
	private function rows(string $schema): \Generator {
		$offset = 0;
		do {
			$page = $this->objects->findAll(
				config: [
					'filters' => ['register' => 'integriq', 'schema' => $schema],
					'limit' => $this->pageSize,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false
			);
			$page = ($page['results'] ?? $page);
			foreach ($page as $row) {
				if ($row instanceof ObjectEntity === true) {
					yield (string)$row->getUuid() => ($row->getObject() ?? []);
				}
			}

			$offset += $this->pageSize;
			$full = (count($page) === $this->pageSize);
		} while ($full === true);
	}//end rows()

	/**
	 * Delete in batches; return how many OpenRegister actually removed.
	 *
	 * @param array<int, string> $uuids The uuids to delete.
	 *
	 * @return integer
	 */
	private function delete(array $uuids): int {
		$deleted = 0;
		foreach (array_chunk($uuids, max(1, $this->pageSize)) as $batch) {
			// System context: an admin remediation; with the defaults RBAC
			// silently filters the list and the run deletes nothing.
			$result = $this->objects->deleteObjects(uuids: $batch, _rbac: false, _multitenancy: false);
			$deleted += count(($result['deleted_uuids'] ?? []));
		}

		return $deleted;
	}//end delete()
}//end class
