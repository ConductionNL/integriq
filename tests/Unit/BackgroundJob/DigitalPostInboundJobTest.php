<?php

/**
 * Characterisation of DigitalPostInboundJob::pollSource(): which sources are
 * polled, what is offered to the intake inbox, and what is counted.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/berichtenbox-digital-post-adapter/specs/digital-post-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\BackgroundJob;

use OCA\Integriq\BackgroundJob\DigitalPostInboundJob;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Mail\IntakeDocumentDispatcherInterface;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\BackgroundJob\DigitalPostInboundJob
 */
class DigitalPostInboundJobTest extends TestCase {

	/**
	 * Poll one source with a provider that answers the given items (or throws),
	 * and an intake that accepts every other item.
	 *
	 * @param array<string,mixed> $source The source.
	 * @param mixed               $items  The items, or 'throw'.
	 *
	 * @return array{0: int, 1: array<int, array<string,mixed>>, 2: array<int, array<int, mixed>>, 3: array<int, mixed>} Count, offered payloads, warnings, provider configs.
	 */
	private function poll(array $source, mixed $items): array {
		$configs = [];
		$provider = $this->createMock(DigitalPostProviderInterface::class);
		$provider->method('getProviderId')->willReturn('berichtenbox');
		$provider->method('pollInbound')->willReturnCallback(
			function (array $config) use ($items, &$configs): array {
				$configs[] = $config;
				if ($items === 'throw') {
					throw new RuntimeException('provider down');
				}

				return $items;
			}
		);
		$offered = [];
		$intake = $this->createMock(IntakeDocumentDispatcherInterface::class);
		$intake->method('dispatch')->willReturnCallback(
			function (array $payload) use (&$offered): bool {
				$offered[] = $payload;
				return (count($offered) % 2) === 1;
			}
		);
		$warnings = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$job = new DigitalPostInboundJob($this->createMock(ITimeFactory::class), new DigitalPostProviderRegistry([$provider]), $intake, $this->createMock(OrObjectService::class), $logger);
		$count = $job->pollSource($source);

		return [$count, $offered, $warnings, $configs];
	}

	/**
	 * Every path through pollSource.
	 *
	 * @return void
	 */
	public function testEveryPollOutcome(): void {
		$items = [
			['sender' => '999993653', 'subject' => 'Reactie', 'document' => ['name' => 'a.pdf'], 'receivedAt' => '2026-09-18T10:00:00+00:00'],
			['sender' => 42],
			['subject' => 'Derde', 'receivedAt' => '2026-09-19T10:00:00+00:00'],
		];
		[$count, $offered, $warnings, $configs] = $this->poll(['slug' => 'bb', 'configuration' => '{"providerId":"berichtenbox","x":1}'], $items);

		$this->assertSame(2, $count);
		$this->assertSame([['providerId' => 'berichtenbox', 'x' => 1]], $configs);
		$this->assertSame([], $warnings);
		$this->assertSame(
			['channel' => 'digitalPost', 'subject' => 'Reactie', 'receivedAt' => '2026-09-18T10:00:00+00:00', 'files' => [['name' => 'a.pdf']], 'metadata' => ['sender' => '999993653', 'source' => 'bb', 'provider' => 'berichtenbox']],
			$offered[0]
		);
		$this->assertSame(['', [[]], ['sender' => '42', 'source' => 'bb', 'provider' => 'berichtenbox']], [$offered[1]['subject'], $offered[1]['files'], $offered[1]['metadata']]);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $offered[1]['receivedAt']);
		$this->assertSame(['Derde', '2026-09-19T10:00:00+00:00'], [$offered[2]['subject'], $offered[2]['receivedAt']]);

		$this->assertSame([0, [], [['digital-post.inbound.failed', ['source' => '', 'error' => 'provider down']]]], array_slice($this->poll(['configuration' => ['providerId' => 'berichtenbox']], 'throw'), 0, 3));
		$this->assertSame([0, [], [], []], $this->poll(['configuration' => 'not json'], $items));
		$this->assertSame([0, [], [], []], $this->poll(['configuration' => ['providerId' => 'other']], $items));
		$this->assertSame([0, [], [], []], $this->poll([], $items));
		$this->assertSame([0, [], [], [['providerId' => 'berichtenbox']]], $this->poll(['configuration' => ['providerId' => 'berichtenbox']], []));
	}
}
