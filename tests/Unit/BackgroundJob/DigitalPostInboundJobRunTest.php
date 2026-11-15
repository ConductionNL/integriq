<?php

/**
 * DigitalPostInboundJob polls every active source on a run and offers what each received.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use ReflectionMethod;

/**
 * @covers \OCA\Integriq\BackgroundJob\DigitalPostInboundJob
 * @uses \OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry
 * @uses \OCA\Integriq\Service\Helper\ProviderIndex
 */
class DigitalPostInboundJobRunTest extends TestCase {

	public function testARunOffersEveryItemOfEveryActiveSource(): void {
		$provider = $this->createMock(DigitalPostProviderInterface::class);
		$provider->method('getProviderId')->willReturn('berichtenbox');
		$provider->expects($this->exactly(2))->method('pollInbound')->willReturnCallback(
			static fn (array $config): array => [['subject' => 'Brief ' . $config['box'], 'sender' => 'Gemeente', 'receivedAt' => '2026-11-01T10:00:00+00:00', 'document' => ['name' => 'brief.pdf']]]
		);
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturn(
			[
				'results' => [
					['slug' => 'box-a', 'isEnabled' => true, 'configuration' => ['providerId' => 'berichtenbox', 'box' => 'a']],
					['slug' => 'box-off', 'isEnabled' => false, 'configuration' => ['providerId' => 'berichtenbox', 'box' => 'off']],
					['slug' => 'box-b', 'isEnabled' => true, 'configuration' => '{"providerId":"berichtenbox","box":"b"}'],
				],
			]
		);

		$offered = [];
		$intake = $this->createMock(IntakeDocumentDispatcherInterface::class);
		$intake->method('dispatch')->willReturnCallback(
			function (array $document) use (&$offered): bool {
				$offered[] = [$document['subject'], $document['metadata']['source']];
				return true;
			}
		);

		$job = new DigitalPostInboundJob($this->createMock(ITimeFactory::class), new DigitalPostProviderRegistry([$provider]), $intake, $objects, $this->createMock(LoggerInterface::class));
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame([['Brief a', 'box-a'], ['Brief b', 'box-b']], $offered);
	}
}
