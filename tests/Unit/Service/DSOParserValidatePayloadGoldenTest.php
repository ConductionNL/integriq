<?php

/**
 * Golden characterization of DSOParserService::validatePayload().
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
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\DSOParserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every payload shape validatePayload() tells apart, recorded against a
 * golden file: the errors and their order.
 *
 * Regenerate the golden file on the code it must describe with
 * DSO_VALIDATE_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\DSOParserService
 */
class DSOParserValidatePayloadGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/dso/validate-payload.json';

	/**
	 * The payloads.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$valid = [
			'verzoekId' => 'v-1',
			'bronorganisatie' => '001234567',
			'type' => 'aanvraag',
			'indieningsdatum' => '2026-01-02',
			'aanvrager' => ['bsn' => '111222333'],
			'locatie' => ['adres' => 'x'],
			'activiteiten' => [['code' => 'a']],
		];
		return [
			'empty' => [],
			'valid' => $valid,
			'valid-datetime' => array_merge($valid, ['indieningsdatum' => '2026-01-02T10:11:12']),
			'empty-strings' => array_map(static fn () => '', $valid),
			'nulls' => array_map(static fn () => null, $valid),
			'zero-and-false' => array_merge($valid, ['verzoekId' => 0, 'locatie' => false]),
			'bad-type' => array_merge($valid, ['type' => 'Aanvraag']),
			'type-int' => array_merge($valid, ['type' => 1]),
			'every-type' => array_merge($valid, ['type' => 'melding']),
			'informatieverzoek' => array_merge($valid, ['type' => 'informatieverzoek']),
			'vooroverleg' => array_merge($valid, ['type' => 'vooroverleg']),
			'activiteiten-string' => array_merge($valid, ['activiteiten' => 'bouwen']),
			'activiteiten-empty-array' => array_merge($valid, ['activiteiten' => []]),
			'bad-bsn' => array_merge($valid, ['aanvrager' => ['bsn' => '123456789']]),
			'short-bsn' => array_merge($valid, ['aanvrager' => ['bsn' => '12345']]),
			'no-bsn' => array_merge($valid, ['aanvrager' => ['kvk' => '1']]),
			'bad-date' => array_merge($valid, ['indieningsdatum' => '02-01-2026']),
			'date-int' => array_merge($valid, ['indieningsdatum' => 20260102]),
			'everything-wrong' => [
				'type' => 'x',
				'activiteiten' => 'y',
				'aanvrager' => ['bsn' => '1'],
				'indieningsdatum' => 'z',
			],
		];
	}//end cases()

	/**
	 * Every case's errors as JSON text.
	 *
	 * @return array<string, string>
	 */
	private function record(): array {
		$service = new DSOParserService($this->createMock(LoggerInterface::class));
		$out = [];
		foreach ($this->cases() as $name => $payload) {
			try {
				$out[$name] = json_encode($service->validatePayload(payload: $payload));
			} catch (\Throwable $e) {
				$out[$name] = get_class($e);
			}
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
		if (getenv('DSO_VALIDATE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($record, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($golden), array_keys($record));
		foreach ($record as $name => $value) {
			$this->assertSame($golden[$name], $value, $name);
		}
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
