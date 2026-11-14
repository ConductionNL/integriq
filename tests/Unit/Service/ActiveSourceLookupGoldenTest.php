<?php

/**
 * Every adapter service finds its one active source the same way.
 *
 * Pins resolveActiveSource() of the nine adapter services that look their
 * source up identically: the exact findAll() query, the raw re-read of the
 * first match, and the exception and message when nothing is configured, so
 * the shared lookup can move without changing any of them.
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
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-004-push-endpoint-and-signed-retour-receiver
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\FscConnectivityException;
use OCA\Integriq\Exception\IwmoIjwProviderException;
use OCA\Integriq\Exception\KissProviderException;
use OCA\Integriq\Exception\OsoProviderException;
use OCA\Integriq\Exception\RodProviderException;
use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Exception\StufZknProviderException;
use OCA\Integriq\Exception\UwlrEduVProviderException;
use OCA\Integriq\Exception\VerzuimloketProviderException;
use OCA\Integriq\Service\FscCallService;
use OCA\Integriq\Service\IwmoIjwSyncService;
use OCA\Integriq\Service\KissSyncService;
use OCA\Integriq\Service\OsoService;
use OCA\Integriq\Service\RodService;
use OCA\Integriq\Service\SmsDispatchService;
use OCA\Integriq\Service\StufZknSyncService;
use OCA\Integriq\Service\UwlrEduVService;
use OCA\Integriq\Service\VerzuimloketService;
use OCA\Integriq\Service\Security\RawSourceResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden tests for the adapter services' active source lookup.
 */
final class ActiveSourceLookupGoldenTest extends TestCase {

	/**
	 * Each service, its source type, its exception and its message.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: class-string, 3: string}>
	 */
	public static function services(): array {
		return [
			'IwmoIjwSyncService' => [IwmoIjwSyncService::class, 'iwmo-ijw', IwmoIjwProviderException::class, 'No active iWMO/iJW source is configured (register "openconnector", schema "source", type "iwmo-ijw", isEnabled=true). Configure one before using the iWMO/iJW bridge.'],
			'RodService' => [RodService::class, 'rod', RodProviderException::class, 'No active ROD source is configured (register "integriq", schema "source", type "rod", isEnabled=true). Configure one before using the ROD bridge.'],
			'VerzuimloketService' => [VerzuimloketService::class, 'verzuimloket', VerzuimloketProviderException::class, 'No active Verzuimloket source is configured (register "integriq", schema "source", type "verzuimloket", isEnabled=true). Configure one before using the Verzuimloket bridge.'],
			'OsoService' => [OsoService::class, 'oso', OsoProviderException::class, 'No active OSO source is configured (register "integriq", schema "source", type "oso", isEnabled=true). Configure one before using the OSO bridge.'],
			'UwlrEduVService' => [UwlrEduVService::class, 'uwlr-eduv', UwlrEduVProviderException::class, 'No active UWLR/Edu-V source is configured (register "integriq", schema "source", type "uwlr-eduv", isEnabled=true). Configure one before using this bridge.'],
			'FscCallService' => [FscCallService::class, 'fsc', FscConnectivityException::class, 'No active FSC source is configured (register "openconnector", schema "source", type "fsc", isEnabled=true). Configure one before using FSC connectivity.'],
			'SmsDispatchService' => [SmsDispatchService::class, 'sms', SmsProviderException::class, 'No active SMS source is configured (register "openconnector", schema "source", type "sms", isEnabled=true). Configure one before using the SMS channel.'],
			'KissSyncService' => [KissSyncService::class, 'kiss', KissProviderException::class, 'No active KISS source is configured (register "openconnector", schema "source", type "kiss", isEnabled=true). Configure one before using the KISS bridge.'],
			'StufZknSyncService' => [StufZknSyncService::class, 'stuf-zkn', StufZknProviderException::class, 'No active StUF-ZKN source is configured (register "openconnector", schema "source", type "stuf-zkn", isEnabled=true). Configure one before using the StUF-ZKN bridge.'],
		];

	}//end services()

	/**
	 * A service with only its object service and raw source resolver set.
	 *
	 * @param class-string      $class    The service.
	 * @param ORObjectService   $objects  The object service.
	 * @param RawSourceResolver $resolver The raw source resolver.
	 *
	 * @return object
	 */
	private function service(string $class, ORObjectService $objects, RawSourceResolver $resolver): object {
		$service = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		(function (ORObjectService $objects, RawSourceResolver $resolver): void {
			$this->objectService = $objects;
			$this->rawSourceResolver = $resolver;
		})->call($service, $objects, $resolver);

		return $service;

	}//end service()

	/**
	 * The object service, expecting the one active source query for a type.
	 *
	 * @param string $type   The source type.
	 * @param array  $answer What findAll() answers.
	 *
	 * @return ORObjectService&MockObject
	 */
	private function objects(string $type, array $answer): ORObjectService {
		$objects = $this->createMock(ORObjectService::class);
		$objects->expects($this->once())->method('findAll')->with(
			[
				'filters' => [
					'register' => 'integriq',
					'schema' => 'source',
					'type' => $type,
					'isEnabled' => true,
				],
				'limit' => 1,
			]
		)->willReturn($answer);

		return $objects;

	}//end objects()

	/**
	 * The first match is re-read raw, from a results envelope or a plain list.
	 *
	 * @param class-string $class     The service.
	 * @param string       $type      The source type.
	 * @param class-string $exception The exception (not thrown here).
	 * @param string       $message   The message (not thrown here).
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testTheFirstMatchIsReadRaw(string $class, string $type, string $exception, string $message): void {
		$first = new ObjectEntity();
		$raw = new ObjectEntity();
		foreach ([['results' => [$first, new ObjectEntity()]], [$first]] as $answer) {
			$resolver = $this->createMock(RawSourceResolver::class);
			$resolver->expects($this->once())->method('resolveRaw')->with($first)->willReturn($raw);

			$this->assertSame($raw, $this->service($class, $this->objects($type, $answer), $resolver)->resolveActiveSource());
		}

	}//end testTheFirstMatchIsReadRaw()

	/**
	 * No match throws the service's own exception and message.
	 *
	 * @param class-string $class     The service.
	 * @param string       $type      The source type.
	 * @param class-string $exception The exception.
	 * @param string       $message   The message.
	 *
	 * @return void
	 */
	#[DataProvider('services')]
	public function testNoMatchIsRefused(string $class, string $type, string $exception, string $message): void {
		$resolver = $this->createMock(RawSourceResolver::class);
		$resolver->expects($this->never())->method('resolveRaw');

		$this->expectException($exception);
		$this->expectExceptionMessage($message);

		$this->service($class, $this->objects($type, ['results' => []]), $resolver)->resolveActiveSource();

	}//end testNoMatchIsRefused()
}//end class
