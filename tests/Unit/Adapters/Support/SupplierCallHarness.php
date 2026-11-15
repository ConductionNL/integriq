<?php

/**
 * Test harness: a SupplierSourceCall over a recorded supplier answer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\Support;

use OCA\Integriq\Adapters\Support\SupplierSourceCall;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Builds the real SupplierSourceCall with CallService and the register doubled.
 */
trait SupplierCallHarness {

	/**
	 * Every CallService call: [source slug, endpoint, method, config, withholdBodies].
	 *
	 * @var array<int,array{0:string,1:string,2:string,3:array<string,mixed>,4:bool}>
	 */
	private array $calls = [];

	/**
	 * A SupplierSourceCall whose register holds the given source slugs and
	 * whose CallService answers with the status and body.
	 *
	 * @param array<int,string> $slugs  The source slugs in the register.
	 * @param int               $status The answered status.
	 * @param mixed             $body   The answered body.
	 *
	 * @return SupplierSourceCall
	 */
	private function supplierCall(array $slugs, int $status, mixed $body): SupplierSourceCall {
		/** @var TestCase $this */
		$objectService = $this->createMock(OrObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = []) use ($slugs): array {
				$results = [];
				foreach ($slugs as $slug) {
					$source = new ObjectEntity();
					$source->setObject(['slug' => $slug, 'location' => 'https://' . $slug . '.example.invalid']);
					$results[] = $source;
				}

				return ['results' => $results];
			}
		);

		$callService = $this->createMock(CallServiceInterface::class);
		$callService->method('call')->willReturnCallback(
			function (ObjectEntity $source, string $endpoint = '', string $method = 'GET', array $config = [], ...$rest) use ($status, $body): ObjectEntity {
				$withhold = (bool)($rest['withholdBodies'] ?? ($rest[8] ?? false));
				$this->calls[] = [(string)$source->getObject()['slug'], $endpoint, $method, $config, $withhold];
				$log = new ObjectEntity();
				$log->setObject(['statusCode' => $status, 'response' => ['statusCode' => $status, 'body' => $body]]);
				return $log;
			}
		);

		return new SupplierSourceCall(callService: $callService, orObjectService: $objectService);
	}//end supplierCall()

	/**
	 * A recorded fixture, decoded.
	 *
	 * @param string $path The path below tests/fixtures.
	 *
	 * @return array<string,mixed>
	 */
	private function recorded(string $path): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../fixtures/' . $path), true);
	}//end recorded()
}//end trait
