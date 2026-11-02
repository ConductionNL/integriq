<?php

/**
 * Golden characterization of KissSyncService::pushCustomerContact().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/kiss-kcc-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Kiss\CallEventLog;
use OCA\Integriq\Service\Kiss\KlantinteractiesClient;
use OCA\Integriq\Service\Kiss\LogKlantinteractiesProvider;
use OCA\Integriq\Service\KissSyncService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pushes contact moments with and without a case, parties and a call, and
 * records every provider call, write and outcome against a golden file.
 * Regenerate it on the code it must describe with KISS_PUSH_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\KissSyncService
 * @uses   \OCA\Integriq\Service\Security\RawSourceResolver
 * @uses   \OCA\Integriq\Service\Kiss\CallEventLog
 */
class KissSyncServicePushGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/kiss/push-customer-contact.json';

	/**
	 * Everything the push called or wrote, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * The push inputs, by case name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function inputs(): array {
		$at = ['occurredOn' => '2026-10-01T10:00:00+00:00'];

		return [
			'minimal' => $at,
			'english-keys' => $at + ['subject' => 'Vraag', 'channel' => 'e-mail', 'text' => 'Over de aanslag', 'indicationContactSucceeded' => false, 'language' => 'en'],
			'wire-keys' => $at + ['onderwerp' => 'Melding', 'tekst' => 'Losse stoeptegel', 'kanaal' => 'balie', 'taal' => 'fy'],
			'english-wins-over-wire' => $at + ['subject' => 'A', 'onderwerp' => 'B', 'text' => 'C', 'tekst' => 'D'],
			'case-default-type' => $at + ['subject' => 'Zaak', 'caseReference' => 'case-1', 'sourceApp' => 'dossiq'],
			'case-own-type' => $at + ['caseReference' => 'case-2', 'caseObjectType' => 'melding', 'sourceApp' => 7],
			'case-empty-reference' => $at + ['caseReference' => '', 'caseObjectType' => 'melding'],
			'party' => $at + ['involvedParty' => ['rol' => 'klant', 'organisatienaam' => 'Bakkerij']],
			'party-empty' => $at + ['involvedParty' => []],
			'party-scalar' => $at + ['involvedParty' => 'not a list'],
			'call-new' => ['callId' => 'call-1', 'callSourceId' => 'pbx-1', 'channel' => 'chat', 'kanaal' => 'chat', 'subject' => 'Gebeld'],
			'call-own-time' => $at + ['callId' => 'call-1', 'subject' => 'Gebeld'],
			'call-no-time' => ['callId' => 'call-2', 'subject' => 'Gebeld'],
			'call-recorded' => ['callId' => 'call-done', 'callSourceId' => 'pbx-1'],
			'call-unknown' => ['callId' => 'call-gone'],
			'provider-fails' => $at + ['subject' => 'fail'],
			'link-fails' => $at + ['caseReference' => 'fail'],
		];

	}//end inputs()

	/**
	 * Build the service with mocks that record every call into the trace.
	 *
	 * @return KissSyncService
	 */
	private function service(): KissSyncService {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$this->trace[] = ['findAll' => $filters];
				return ['results' => $this->found($filters)];
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				unset($object['syncedAt']);
				$object['occurredOn'] = $this->stableTime(time: ($object['occurredOn'] ?? null));
				if (isset($object['registratiedatum']) === true) {
					$object['registratiedatum'] = ($object['registratiedatum'] !== '');
				}

				$this->trace[] = ['save' => $schema, 'uuid' => $uuid, 'object' => $object];
				return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'local-1'));
			}
		);

		$rest = $this->createMock(KlantinteractiesClient::class);
		$rest->method('createCustomerContact')->willReturnCallback(
			function (array $sourceConfiguration, array $payload): string {
				$payload['plaatsgevondenOp'] = $this->stableTime(time: $payload['plaatsgevondenOp']);
				$this->trace[] = ['create' => $payload, 'configuration' => $sourceConfiguration];
				if (($payload['onderwerp'] ?? '') === 'fail') {
					throw new RuntimeException('KISS refused the contact');
				}

				return 'kiss-1';
			}
		);
		$rest->method('linkOnderwerpobject')->willReturnCallback(
			function (array $sourceConfiguration, string $customerContactId, string $caseReference, string $caseObjectType): string {
				$this->trace[] = ['link' => $customerContactId, 'caseReference' => $caseReference, 'caseObjectType' => $caseObjectType];
				if ($caseReference === 'fail') {
					throw new RuntimeException('KISS refused the link');
				}

				return 'object-1';
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger = $this->createMock(LoggerInterface::class);

		return new KissSyncService(
			$objects,
			$this->createMock(LogKlantinteractiesProvider::class),
			$rest,
			$l10n,
			$logger,
			new RawSourceResolver($objects, $logger),
			new CallEventLog($objects)
		);

	}//end service()

	/**
	 * A fixture time as is, or `now` for a time the code took from the clock.
	 *
	 * @param mixed $time The recorded time.
	 *
	 * @return mixed
	 */
	private function stableTime(mixed $time): mixed {
		if (is_string($time) === true && str_starts_with($time, '2026-10-0') === false) {
			return 'now';
		}

		return $time;

	}//end stableTime()

	/**
	 * The stored objects a findAll() with these filters answers with.
	 *
	 * @param array<string, mixed> $filters The findAll() filters.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function found(array $filters): array {
		$schema = (string)($filters['schema'] ?? '');
		if ($schema === KissSyncService::SCHEMA_SOURCE) {
			return [ObjectServiceMockBuilder::objectEntity($this, ['type' => 'kiss', 'isEnabled' => true, 'configuration' => ['provider' => 'rest', 'baseUrl' => 'https://kiss.test']], 'source-1')];
		}

		$callId = (string)($filters['callId'] ?? '');
		if ($schema === KissSyncService::SCHEMA_KLANTCONTACT && $callId === 'call-done') {
			return [ObjectServiceMockBuilder::objectEntity($this, ['kissId' => 'kiss-old', 'callId' => 'call-done', 'callSourceId' => 'pbx-1'], 'local-old')];
		}

		if ($schema === KissSyncService::SCHEMA_KLANTCONTACT || in_array($callId, ['call-1', 'call-2', 'call-done'], true) === false) {
			return [];
		}

		$event = ['callId' => $callId, 'sourceId' => 'pbx-1', 'kind' => 'ended', 'durationSeconds' => -3];
		if ($callId === 'call-1') {
			$event['at'] = '2026-10-02T09:30:00+00:00';
			$event['durationSeconds'] = 125;
		}

		return [ObjectServiceMockBuilder::objectEntity($this, $event, 'event-' . $callId)];

	}//end found()

	/**
	 * Every push matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryPushMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->inputs() as $name => $input) {
			$service = $this->service();
			$this->trace = [];
			try {
				$outcome = ['return' => $service->pushCustomerContact(input: $input)];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['trace' => $this->trace, 'outcome' => $outcome];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('KISS_PUSH_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);

	}//end testEveryPushMatchesTheGoldenFile()
}//end class
