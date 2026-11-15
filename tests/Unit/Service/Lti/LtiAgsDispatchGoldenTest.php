<?php

/**
 * Characterisation test for LtiAgsService::dispatchAgsCall.
 *
 * Pins the ad-hoc source, endpoint, method, request config and the read of the
 * call log for each URL shape.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
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

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Lti\LtiAgsService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden record of the AGS dispatch.
 */
class LtiAgsDispatchGoldenTest extends TestCase {

	/**
	 * The recorded calls and answers.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$record = [];
		$logs = [
			['response' => ['statusCode' => 200, 'body' => ['ok' => true]], 'statusCode' => 500],
			['statusCode' => 204],
			[],
			['response' => ['body' => 'text']],
		];
		$callService = $this->createMock(CallService::class);
		$callService->method('call')->willReturnCallback(
			function (ObjectEntity $source, string $endpoint, string $method, array $config, ...$rest) use (&$record, &$logs): ObjectEntity {
				$record[] = ['call' => [$source->getUuid(), $source->getObject(), $endpoint, $method, $config, $rest]];
				$log = new ObjectEntity();
				$log->setObject((array)array_shift($logs));
				return $log;
			}
		);

		$service = (new ReflectionClass(LtiAgsService::class))->newInstanceWithoutConstructor();
		(function (CallService $callService): void {
			$this->callService = $callService;
		})->call($service, $callService);
		$dispatch = (new ReflectionClass(LtiAgsService::class))->getMethod('dispatchAgsCall');

		$cases = [
			['https://lms.example.test:8443/api/ags/lineitems/7/scores?type=x&y=1', 'POST', 'tok-1', ['scoreGiven' => 1]],
			['https://lms.example.test/api/ags/lineitems', 'GET', 'tok-2', null],
			['http://lms.example.test', 'POST', 'tok-3', []],
			['lms.example.test/path', 'GET', '', null],
		];
		foreach ($cases as [$url, $method, $token, $body]) {
			$record[] = ['answer' => $dispatch->invoke($service, $url, $method, $token, $body)];
		}

		$path = __DIR__ . '/../../../fixtures/lti/ags-dispatch.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
