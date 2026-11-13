<?php

/**
 * Unit tests for the source-call node configuration guard.
 *
 * A flow author's configuration is checked when the flow is saved, so a bad
 * method, status list, request part, body source, error policy or decode mode
 * is refused with a sentence naming the field, instead of failing at run time
 * in a background job nobody watches.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Flow;

use OCA\Integriq\Flow\SourceCallConfigGuard;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Accepted and refused source-call configurations, per check.
 *
 * @covers \OCA\Integriq\Flow\SourceCallConfigGuard
 */
class SourceCallConfigGuardTest extends TestCase {

	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => (is_array($parameters) === true && $parameters !== []) ? vsprintf($text, $parameters) : $text
		);
	}//end setUp()

	/**
	 * Configurations every check accepts.
	 *
	 * @return void
	 */
	public function testAWellFormedConfigurationPassesEveryCheck(): void {
		$config = [
			'method' => ' post ',
			'acceptStatuses' => [200, 201, 404],
			'query' => ['page' => '1'],
			'headers' => ['Accept' => 'application/json'],
			'bodyFrom' => 'item.payload',
			'responseMapping' => ['id' => 'data.id'],
			'onError' => 'Dead_Letter',
			'decode' => 'JSON',
		];

		SourceCallConfigGuard::assertMethod($config, $this->l10n);
		SourceCallConfigGuard::assertAcceptStatuses($config, $this->l10n);
		SourceCallConfigGuard::assertRequestParts($config, $this->l10n);
		SourceCallConfigGuard::assertBodyFrom($config, $this->l10n);
		SourceCallConfigGuard::assertOnError($config, $this->l10n);
		SourceCallConfigGuard::assertDecode($config, $this->l10n);
		SourceCallConfigGuard::assertRequestParts(['body' => '<xml/>'], $this->l10n);
		SourceCallConfigGuard::assertAcceptStatuses([], $this->l10n);
		SourceCallConfigGuard::assertBodyFrom([], $this->l10n);
		SourceCallConfigGuard::assertOnError([], $this->l10n);
		SourceCallConfigGuard::assertDecode([], $this->l10n);

		$this->addToAssertionCount(1);
	}//end testAWellFormedConfigurationPassesEveryCheck()

	/**
	 * Refused configurations and the sentence each one gets.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public static function refusals(): array {
		return [
			'no method' => ['assertMethod', ['method' => '  '], 'The "method" field must name an HTTP method (GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS).'],
			'unsupported method' => ['assertMethod', ['method' => 'trace'], 'The "method" field names an unsupported HTTP method "TRACE"; supported methods are GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS.'],
			'statuses not a list' => ['assertAcceptStatuses', ['acceptStatuses' => ['ok' => 200]], 'The "acceptStatuses" field must be a list of HTTP status codes.'],
			'statuses a string' => ['assertAcceptStatuses', ['acceptStatuses' => '200'], 'The "acceptStatuses" field must be a list of HTTP status codes.'],
			'status out of range' => ['assertAcceptStatuses', ['acceptStatuses' => [200, 600]], 'The "acceptStatuses" field must contain only HTTP status codes between 100 and 599.'],
			'status as a string' => ['assertAcceptStatuses', ['acceptStatuses' => ['200']], 'The "acceptStatuses" field must contain only HTTP status codes between 100 and 599.'],
			'query not an object' => ['assertRequestParts', ['query' => 'page=1'], 'The "query" field must be an object of name/value pairs.'],
			'headers not an object' => ['assertRequestParts', ['headers' => 'Accept: */*'], 'The "headers" field must be an object of name/value pairs.'],
			'body a number' => ['assertRequestParts', ['body' => 42], 'The "body" field must be an object or a string.'],
			'mapping not an object' => ['assertRequestParts', ['responseMapping' => 'data.id'], 'The "responseMapping" field must be an object of target key to selector.'],
			'bodyFrom blank' => ['assertBodyFrom', ['bodyFrom' => ' '], 'The "bodyFrom" field must be a dot-path to an object on the item.'],
			'bodyFrom not a string' => ['assertBodyFrom', ['bodyFrom' => ['item']], 'The "bodyFrom" field must be a dot-path to an object on the item.'],
			'body and bodyFrom' => ['assertBodyFrom', ['bodyFrom' => 'item.payload', 'body' => []], 'Use "body" or "bodyFrom", not both.'],
			'unknown onError' => ['assertOnError', ['onError' => 'retry'], 'The "onError" field must be one of stop, continue, dead_letter.'],
		];
	}//end refusals()

	/**
	 * Each refused configuration raises its own sentence.
	 *
	 * @param string              $check   The guard method.
	 * @param array<string,mixed> $config  The configuration.
	 * @param string              $message The expected sentence.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testARefusedConfigurationNamesTheField(string $check, array $config, string $message): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage($message);

		SourceCallConfigGuard::$check($config, $this->l10n);
	}//end testARefusedConfigurationNamesTheField()

	/**
	 * An unknown decode mode is refused, naming the modes there are.
	 *
	 * @return void
	 */
	public function testAnUnknownDecodeModeIsRefused(): void {
		try {
			SourceCallConfigGuard::assertDecode(['decode' => 'xml'], $this->l10n);
			$this->fail('Expected the decode mode to be refused.');
		} catch (UnexpectedValueException $e) {
			$this->assertStringStartsWith('The "decode" field must be one of ', $e->getMessage());
			$this->assertStringContainsString('json', $e->getMessage());
		}
	}//end testAnUnknownDecodeModeIsRefused()
}//end class
