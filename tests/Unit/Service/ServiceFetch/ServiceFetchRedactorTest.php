<?php

/**
 * Unit tests for ServiceFetchRedactor — input values out of the call log line.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ServiceFetch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ServiceFetch;

use OCA\Integriq\Service\ServiceFetch\ServiceFetchRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Design D4: input values are redacted, a BSN always.
 *
 * @spec openspec/changes/forms-service-fetch-for-apps/specs/service-fetch/spec.md#requirement-an-app-calls-a-service-fetch-with-inputs-and-gets-mapped-outputs-req-sf-002
 */
#[CoversClass(ServiceFetchRedactor::class)]
#[UsesClass(SensitiveFieldRegistry::class)]
class ServiceFetchRedactorTest extends TestCase {

	/**
	 * Every place a value can sit in the request, raw or encoded, and the echo
	 * in the response body; other fields are untouched.
	 *
	 * @return void
	 */
	public function testInputValuesAreRedactedFromRequestAndResponseBody(): void {
		$row = [
			'statusCode' => 200,
			'context' => ['app' => 'portaliq', 'fetch' => 'kadaster-eigenaar'],
			'request' => [
				'url' => 'https://brk.example/eigendom/999993653?adres=Dam%201&x=Dam+1',
				'method' => 'GET',
				'query' => ['bsn' => '999993653', 'adres' => 'Dam 1'],
				'json' => ['nested' => ['bsn' => '999993653']],
				'timeout' => 5,
			],
			'response' => ['statusCode' => 200, 'body' => '{"bsn":"999993653","eigenaar":true}', 'headers' => ['X' => ['999993653']]],
		];

		$redacted = (new ServiceFetchRedactor())->redact(row: $row, values: ['999993653', 'Dam 1']);

		$r = SensitiveFieldRegistry::PLACEHOLDER;
		$this->assertSame("https://brk.example/eigendom/{$r}?adres={$r}&x={$r}", $redacted['request']['url']);
		$this->assertSame(['bsn' => $r, 'adres' => $r], $redacted['request']['query']);
		$this->assertSame(['nested' => ['bsn' => $r]], $redacted['request']['json']);
		$this->assertSame(5, $redacted['request']['timeout']);
		$this->assertSame('{"bsn":"' . $r . '","eigenaar":true}', $redacted['response']['body']);
		$this->assertSame(['X' => ['999993653']], $redacted['response']['headers']);
		$this->assertSame($row['context'], $redacted['context']);
	}//end testInputValuesAreRedactedFromRequestAndResponseBody()

	/**
	 * Without values the row comes back unchanged.
	 *
	 * @return void
	 */
	public function testNothingToRedactLeavesTheRow(): void {
		$row = ['request' => ['url' => 'https://brk.example/x']];

		$this->assertSame($row, (new ServiceFetchRedactor())->redact(row: $row, values: ['']));
	}//end testNothingToRedactLeavesTheRow()
}//end class
