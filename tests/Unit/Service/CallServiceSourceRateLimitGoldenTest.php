<?php

/**
 * Golden characterization of CallService::sourceRateLimit().
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
 * @spec openspec/specs/http-call-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Tests\Helpers\SaveCallShape;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Every rate-limit state and header set sourceRateLimit() tells apart,
 * recorded against a golden file: the headers it returns, what it saves and
 * the source it leaves behind. A timestamp derived from the clock is recorded
 * relative to the time of the call, to the nearest ten seconds.
 *
 * Regenerate the golden file on the code it must describe with
 * SOURCE_RATE_LIMIT_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\CallService
 */
class CallServiceSourceRateLimitGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/call/source-rate-limit.json';

	/**
	 * What the service saved in the current case.
	 *
	 * @var array<int, mixed>
	 */
	private array $saved = [];

	/**
	 * The cases: the stored source data and the response headers.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	private function cases(): array {
		$future = (time() + 120);
		return [
			'untouched' => [['name' => 's'], []],
			'other-headers' => [['name' => 's'], ['Content-Type' => ['json']]],
			'header-reset' => [['name' => 's'], ['X-RateLimit-Reset' => (string)$future]],
			'header-reset-absurd' => [['name' => 's'], ['X-RateLimit-Reset' => '99999999999']],
			'header-reset-garbage' => [['name' => 's'], ['X-RateLimit-Reset' => 'soon']],
			'window-starts-reset' => [['rateLimitWindow' => 60], []],
			'window-with-reset' => [['rateLimitWindow' => 60, 'rateLimitReset' => $future], []],
			'window-and-header-reset' => [['rateLimitWindow' => 60], ['X-RateLimit-Reset' => (string)$future]],
			'window-and-garbage-reset' => [['rateLimitWindow' => 60], ['X-RateLimit-Reset' => 'x']],
			'header-limit' => [['name' => 's'], ['X-RateLimit-Limit' => '100']],
			'header-limit-negative' => [['name' => 's'], ['X-RateLimit-Limit' => '-5']],
			'header-remaining' => [['name' => 's'], ['X-RateLimit-Remaining' => '7']],
			'header-limit-and-remaining' => [['name' => 's'], ['X-RateLimit-Limit' => '100', 'X-RateLimit-Remaining' => '42']],
			'stored-limit-decrements' => [['rateLimitLimit' => 10, 'rateLimitRemaining' => 4], []],
			'stored-limit-no-remaining' => [['rateLimitLimit' => 10], []],
			'stored-limit-header-remaining' => [['rateLimitLimit' => 10, 'rateLimitRemaining' => 4], ['X-RateLimit-Remaining' => '9']],
			'stored-limit-garbage-remaining' => [['rateLimitLimit' => 10, 'rateLimitRemaining' => 4], ['X-RateLimit-Remaining' => 'x']],
			'everything' => [['rateLimitWindow' => 30, 'rateLimitLimit' => 5], ['X-RateLimit-Limit' => '8', 'Accept' => ['a']]],
			'array-header-values' => [['name' => 's'], ['X-RateLimit-Limit' => ['20'], 'X-RateLimit-Remaining' => ['3'], 'X-RateLimit-Reset' => [(string)$future]]],
		];
	}//end cases()

	/**
	 * A value made relative to the time of the call when it is a near-future timestamp.
	 *
	 * @param mixed $value  The value.
	 * @param int   $before The time just before the call.
	 *
	 * @return mixed
	 */
	private function relative(mixed $value, int $before): mixed {
		if (is_numeric($value) === true && (int)$value >= $before && (int)$value <= ($before + (400 * 86400))) {
			return 'now+' . (int)(round(((int)$value - $before) / 10) * 10);
		}

		if (is_array($value) === true) {
			return array_map(fn ($inner) => $this->relative(value: $inner, before: $before), $value);
		}

		return $value;
	}//end relative()

	/**
	 * Every case's outcome as JSON text.
	 *
	 * @return array<string, string>
	 */
	private function record(): array {
		$service = (new ReflectionClass(CallService::class))->newInstanceWithoutConstructor();
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(SaveCallShape::callback(
			function (...$args) {
				// Only the object: the positions of the other arguments follow the loaded OpenRegister class.
				$this->saved[] = $args[0];
				return new ObjectEntity();
			}
		));
		$property = new ReflectionProperty(CallService::class, 'objectService');
		$property->setAccessible(true);
		$property->setValue($service, $objects);
		$method = new ReflectionMethod(CallService::class, 'sourceRateLimit');
		$method->setAccessible(true);

		$out = [];
		foreach ($this->cases() as $name => [$data, $headers]) {
			$this->saved = [];
			$source = new ObjectEntity();
			$source->setUuid('source-uuid');
			$source->setObject($data);
			$before = time();
			$returned = $method->invoke($service, $source, $data, $headers);
			$out[$name] = json_encode(
				$this->relative(value: ['headers' => $returned, 'saved' => $this->saved, 'source' => $source->getObject()], before: $before),
				JSON_UNESCAPED_SLASHES
			);
		}

		return $out;
	}//end record()

	/**
	 * Every case matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$record = $this->record();
		if (getenv('SOURCE_RATE_LIMIT_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, json_encode($record, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($golden), array_keys($record));
		foreach ($record as $name => $value) {
			$this->assertSame($golden[$name], $value, $name);
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
