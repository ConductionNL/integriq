<?php

/**
 * Unit tests for ConnectionMonitorService: a source's last 24 hours and its certificate.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\ConnectionCertificateReader;
use OCA\Integriq\Service\ConnectionMonitorService;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Calls, errors by kind, response times and certificate expiry for one source.
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-how-it-performed-in-the-last-24-hours-req-crun-006
 */
final class ConnectionMonitorServiceTest extends TestCase {

	private const NOW = '2026-10-07T11:00:00+02:00';

	private const SOURCE = 'b1b2c3d4-0000-4000-8000-00000000b4b0';

	/**
	 * Objects per schema the fake object service holds.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $store = [];

	/**
	 * Build the service over the fake store.
	 *
	 * @param integer|null $inlineNotAfter What the resolver reads off inline material.
	 *
	 * @return ConnectionMonitorService
	 */
	private function makeService(?int $inlineNotAfter = null): ConnectionMonitorService {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('find')->willReturnCallback(
			fn (string|int $id): ?ObjectEntity => (($this->store['source'][0] ?? null)?->getUuid() === $id ? $this->store['source'][0] : null)
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = $config['filters'];
				$rows = [];
				foreach (($this->store[$filters['schema']] ?? []) as $entity) {
					$body = $entity->getObject();
					$match = true;
					foreach ($filters as $key => $value) {
						if (in_array($key, ['register', 'schema'], true) === false && ($body[$key] ?? null) !== $value) {
							$match = false;
						}
					}

					if ($match === true) {
						$rows[] = $entity;
					}
				}

				return array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 1000));
			}
		);

		$mtls = $this->createMock(MtlsConfigResolver::class);
		$mtls->method('certificateNotAfter')->willReturn($inlineNotAfter);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n->method('l')->willReturnCallback(fn (string $type, $value): string => $value->format('j F Y'));
		$l10n->method('n')->willReturnCallback(
			fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $one : $many))
		);

		return new ConnectionMonitorService(
			$objects,
			new ConnectionCertificateReader($mtls, $this->createMock(IEventDispatcher::class), $l10n, $this->createMock(LoggerInterface::class)),
			$l10n
		);
	}//end makeService()

	/**
	 * The BRP source with calls: three time-outs, a slow one, and one call older than a day.
	 *
	 * @return void
	 */
	private function brpWithCalls(): void {
		$this->store['source'] = [
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'BRP Haal Centraal', 'slug' => 'brp-haalcentraal', 'configuration' => []], self::SOURCE),
		];
		$call = fn (string $id, int $status, int $ms, string $at, string $message = ''): ObjectEntity => ObjectServiceMockBuilder::objectEntity(
			$this,
			['source' => self::SOURCE, 'statusCode' => $status, 'durationMs' => $ms, 'created' => $at, 'statusMessage' => $message],
			$id
		);
		$this->store['call_log'] = [
			$call('c1', 200, 300, '2026-10-07T10:50:00+02:00'),
			$call('c2', 200, 1900, '2026-10-07T10:40:00+02:00'),
			$call('c3', 0, 500, '2026-10-07T10:30:00+02:00', 'cURL error 28: Operation timed out'),
			$call('c4', 0, 500, '2026-10-07T09:30:00+02:00', 'Connection timed out'),
			$call('c5', 0, 400, '2026-10-06T12:00:00+02:00', 'timed out'),
			$call('c-old', 500, 9000, '2026-10-05T12:00:00+02:00'),
		];
	}//end brpWithCalls()

	/**
	 * Five calls in the day, three time-outs, the slowest 1.9 s and the average over all five.
	 *
	 * @return void
	 */
	public function testItAggregatesTheLastDayBySlug(): void {
		$this->brpWithCalls();

		$report = $this->makeService()->report(sourceRef: 'brp-haalcentraal', now: new DateTimeImmutable(self::NOW));

		$this->assertSame(self::SOURCE, $report['source']['id']);
		$performance = $report['performance'];
		$this->assertSame(5, $performance['calls']);
		$this->assertSame(3, $performance['errors']);
		$this->assertSame(['timeout' => 3, 'client' => 0, 'server' => 0, 'tls' => 0], $performance['errorsByKind']);
		$this->assertSame('all time-outs', $performance['errorSummary']);
		$this->assertSame(1900, $performance['slowestMs']);
		$this->assertSame(1.9, $performance['slowestSeconds']);
		$this->assertSame(1, $performance['slowCount']);
		$this->assertSame(720, $performance['averageMs']);
		$this->assertSame('2026-10-06T11:00:00+02:00', $report['period']['from']);
		$this->assertSame(['configured' => false, 'none' => true], $report['certificate']);
	}//end testItAggregatesTheLastDayBySlug()

	/**
	 * A call log written by CallService carries its timing in response.responseTime, not in durationMs.
	 *
	 * @return void
	 */
	public function testItReadsTheTimingOfACallServiceRow(): void {
		$this->store['source'] = [
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'BRP Haal Centraal', 'slug' => 'brp-haalcentraal', 'configuration' => []], self::SOURCE),
		];
		$call = fn (string $id, float $responseTime): ObjectEntity => ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'source' => self::SOURCE,
				'direction' => 'outbound',
				'statusCode' => 200,
				'statusMessage' => 'OK',
				'request' => ['url' => 'https://brp.example/personen', 'method' => 'POST'],
				'response' => ['statusCode' => 200, 'statusMessage' => 'OK', 'responseTime' => $responseTime],
				'created' => '2026-10-07T10:50:00+02:00',
			],
			$id
		);
		$this->store['call_log'] = [$call('c1', 2400.4), $call('c2', 199.6)];

		$performance = $this->makeService()->report(sourceRef: 'brp-haalcentraal', now: new DateTimeImmutable(self::NOW))['performance'];

		$this->assertSame(2, $performance['calls']);
		$this->assertSame(2400, $performance['slowestMs']);
		$this->assertSame(1300, $performance['averageMs']);
		$this->assertSame(1, $performance['slowCount']);
	}//end testItReadsTheTimingOfACallServiceRow()

	/**
	 * An unknown source has no report.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceHasNoReport(): void {
		$this->brpWithCalls();

		$this->assertNull($this->makeService()->report(sourceRef: 'nope', now: new DateTimeImmutable(self::NOW)));
	}//end testAnUnknownSourceHasNoReport()

	/**
	 * Inline material 54 days from expiry reads as expiring soon, with a notice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007
	 */
	public function testACertificateThatExpiresSoonSaysSo(): void {
		$this->brpWithCalls();
		$notAfter = (new DateTimeImmutable(self::NOW))->modify('+54 days')->getTimestamp();

		$certificate = $this->makeService(inlineNotAfter: $notAfter)->report(sourceRef: self::SOURCE, now: new DateTimeImmutable(self::NOW))['certificate'];

		$this->assertSame('expiring', $certificate['status']);
		$this->assertSame(54, $certificate['daysLeft']);
		$this->assertSame('source', $certificate['keptIn']);
		$this->assertStringContainsString('30 November 2026', $certificate['notice']);
	}//end testACertificateThatExpiresSoonSaysSo()

	/**
	 * A keepiq reference without keepiq answering reads as unknown, and says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007
	 */
	public function testAKeepiqReferenceWithoutAnAnswerIsUnknown(): void {
		$this->store['source'] = [
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['name' => 'BRP', 'slug' => 'brp-haalcentraal', 'configuration' => ['authentication' => ['mtls' => ['certificate' => ['credentialRef' => ['credentialId' => 'k-1']]]]]],
				self::SOURCE
			),
		];

		$certificate = $this->makeService()->report(sourceRef: self::SOURCE, now: new DateTimeImmutable(self::NOW))['certificate'];

		$this->assertSame('keepiq', $certificate['keptIn']);
		$this->assertSame('unknown', $certificate['status']);
		$this->assertSame('Expiry unknown: keepiq did not answer.', $certificate['notice']);
	}//end testAKeepiqReferenceWithoutAnAnswerIsUnknown()
}//end class
