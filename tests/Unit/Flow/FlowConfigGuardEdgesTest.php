<?php

/**
 * Unit tests for the edges of the shared flow configuration guard.
 *
 * The guard refuses targets, identities, credentials and auth headers in a
 * flow document. These tests pin the shapes it must pass over without either
 * crashing or letting a forbidden header through behind them (numeric keys,
 * a headers value that is not a map), and the blank `output` and `endpoint`
 * values it refuses outright.
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

use OCA\Integriq\Flow\FlowConfigGuard;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Numeric keys, odd headers, and blank output and endpoint values.
 *
 * @covers \OCA\Integriq\Flow\FlowConfigGuard
 */
class FlowConfigGuardEdgesTest extends TestCase {

	private IL10N $l10n;

	protected function setUp(): void {
		parent::setUp();
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => (is_array($parameters) === true && $parameters !== []) ? vsprintf($text, $parameters) : $text
		);
	}//end setUp()

	/**
	 * Numeric keys and a headers value that is not a map are passed over.
	 *
	 * @return void
	 */
	public function testNumericKeysAndNonMapHeadersArePassedOver(): void {
		FlowConfigGuard::assertNoForbiddenFields([0 => 'url', 'endpoint' => '/items', 'headers' => 'Authorization: x'], $this->l10n);
		FlowConfigGuard::assertNoForbiddenFields(['headers' => [0 => 'Authorization', 'Accept' => 'application/json']], $this->l10n);

		$this->addToAssertionCount(1);
	}//end testNumericKeysAndNonMapHeadersArePassedOver()

	/**
	 * An auth header behind a numeric header key is still refused.
	 *
	 * @return void
	 */
	public function testAnAuthHeaderBehindANumericKeyIsStillRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The "Authorization" request header may not be set on a flow step');

		FlowConfigGuard::assertNoForbiddenFields(['headers' => [0 => 'x', 'Authorization' => 'Bearer t']], $this->l10n);
	}//end testAnAuthHeaderBehindANumericKeyIsStillRefused()

	/**
	 * A blank output key is refused.
	 *
	 * @return void
	 */
	public function testABlankOutputKeyIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('The "output" field must name a non-empty item key.');

		FlowConfigGuard::assertOutputKeyAllowed('   ', $this->l10n);
	}//end testABlankOutputKeyIsRefused()

	/**
	 * A blank endpoint is refused, rendered or not.
	 *
	 * @return void
	 */
	public function testABlankEndpointIsRefused(): void {
		foreach ([false, true] as $rendered) {
			try {
				FlowConfigGuard::assertEndpointContained(" \t ", $this->l10n, $rendered);
				$this->fail('Expected a blank endpoint to be refused.');
			} catch (UnexpectedValueException $e) {
				$this->assertSame('The "endpoint" field must name a path relative to the Source.', $e->getMessage());
			}
		}
	}//end testABlankEndpointIsRefused()
}//end class
