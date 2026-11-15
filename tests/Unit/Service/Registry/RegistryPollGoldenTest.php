<?php

/**
 * Characterisation test for the BRP and KvK pollChanges.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registry;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\Registry\BrpVolgindicatieProvider;
use OCA\Integriq\Service\Registry\KvkMutatieProvider;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Golden record of the registry polls.
 */
class RegistryPollGoldenTest extends TestCase {

	/**
	 * Every call and log line, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * The outcome of one poll.
	 *
	 * @param string            $class      The provider class.
	 * @param array<int,string> $identities The identities polled.
	 * @param int               $status     The status the source answers.
	 * @param mixed             $body       The body the source answers.
	 *
	 * @return array<string, mixed>
	 */
	private function poll(string $class, array $identities, int $status, mixed $body): array {
		$this->log = [];
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findSourceBySlug')->willReturn($this->createMock(ObjectEntity::class));
		$callLog = $this->createMock(ObjectEntity::class);
		$callLog->method('getObject')->willReturn(['statusCode' => $status, 'response' => ['body' => json_encode($body)]]);
		$callService = $this->getMockBuilder(CallService::class)->disableOriginalConstructor()->onlyMethods(['call'])->getMock();
		$callService->method('call')->willReturnCallback(
			function (...$args) use ($callLog): ObjectEntity {
				$this->log[] = ['call' => [$args[1], $args[2], $args[3]]];
				return $callLog;
			}
		);
		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<mixed> $log The shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};

		$provider = new $class($store, $callService, $logger);
		$changes = [];
		foreach ($provider->pollChanges($identities) as $change) {
			$changes[] = $change->toArray();
		}

		return ['calls' => $this->log, 'changes' => $changes];
	}//end poll()

	/**
	 * The recorded polls.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$brpRows = [
			'_embedded' => ['ingeschrevenpersonen' => [
				['burgerservicenummer' => '999993653', 'gewijzigd' => ['naam' => 'Jansen'], 'volgindicatieId' => 'vi-1', 'eventReference' => 'ev-x'],
				['burgerservicenummer' => '999993654', 'gewijzigd' => ['adres' => 'Dorpsstraat 1'], 'eventReference' => 'ev-2'],
				['burgerservicenummer' => '999993655', 'gewijzigd' => ['a' => 1]],
				['burgerservicenummer' => '', 'gewijzigd' => ['a' => 1]],
				['burgerservicenummer' => '999993656', 'gewijzigd' => []],
				['burgerservicenummer' => '999993657', 'gewijzigd' => 'text'],
				['burgerservicenummer' => '999993658'],
				'not a row',
			]],
		];
		$kvkRows = [
			'mutaties' => [
				['kvkNummer' => '69599084', 'gewijzigd' => ['handelsnaam' => 'Conduction'], 'mutatieId' => 'm-1'],
				['kvkNummer' => 12345678, 'gewijzigd' => ['adres' => 'X'], 'eventReference' => 'ev-9'],
				['kvkNummer' => '', 'gewijzigd' => ['a' => 1]],
				['kvkNummer' => '87654321', 'gewijzigd' => []],
				7,
			],
		];
		$record = [
			'brp no identities' => $this->poll(BrpVolgindicatieProvider::class, [], 200, $brpRows),
			'brp embedded' => $this->poll(BrpVolgindicatieProvider::class, ['999993653', '999993654'], 200, $brpRows),
			'brp results' => $this->poll(BrpVolgindicatieProvider::class, ['1'], 200, ['results' => $brpRows['_embedded']['ingeschrevenpersonen']]),
			'brp embedded wins' => $this->poll(BrpVolgindicatieProvider::class, ['1'], 200, $brpRows + ['results' => [['burgerservicenummer' => 'r', 'gewijzigd' => ['a' => 1]]]]),
			'brp empty' => $this->poll(BrpVolgindicatieProvider::class, ['1'], 200, []),
			'brp refused' => $this->poll(BrpVolgindicatieProvider::class, ['1'], 403, ['title' => 'Geen abonnement']),
			'kvk no identities' => $this->poll(KvkMutatieProvider::class, [], 200, $kvkRows),
			'kvk mutaties' => $this->poll(KvkMutatieProvider::class, ['69599084', '12345678'], 200, $kvkRows),
			'kvk results' => $this->poll(KvkMutatieProvider::class, ['1'], 200, ['results' => $kvkRows['mutaties']]),
			'kvk refused' => $this->poll(KvkMutatieProvider::class, ['1'], 500, ['detail' => 'down']),
		];

		$path = __DIR__ . '/../../../fixtures/registry/registry-poll.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
