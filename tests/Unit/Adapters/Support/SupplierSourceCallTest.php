<?php

/**
 * Unit tests: the shared supplier source call of the live education clients.
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

use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Adapters\Support\SupplierSourceCall;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Source lookup, status check and body decoding.
 */
class SupplierSourceCallTest extends TestCase {
	use SupplierCallHarness;

	/**
	 * A 2xx JSON answer is decoded, through the source with the slug, with an Accept header.
	 *
	 * @return void
	 */
	public function testA2xxAnswerIsDecoded(): void {
		$call = $this->supplierCall(slugs: ['other', 'lvs-boom'], status: 200, body: '{"results":[{"toetscode":"BL-M6"}]}');

		$answer = $call->json(slug: 'lvs-boom', endpoint: 'uwlr/results', config: ['query' => ['groep' => '6']]);

		$this->assertSame(expected: ['results' => [['toetscode' => 'BL-M6']]], actual: $answer);
		$this->assertSame(expected: 'lvs-boom', actual: $this->calls[0][0]);
		$this->assertSame(expected: '/uwlr/results', actual: $this->calls[0][1]);
		$this->assertSame(expected: 'GET', actual: $this->calls[0][2]);
		$this->assertSame(
			expected: ['query' => ['groep' => '6'], 'headers' => ['Accept' => 'application/json']],
			actual: $this->calls[0][3]
		);
		$this->assertFalse($this->calls[0][4]);
	}//end testA2xxAnswerIsDecoded()

	/**
	 * Withheld bodies are neither logged nor stored.
	 *
	 * @return void
	 */
	public function testWithheldBodiesAreNotLogged(): void {
		$call = $this->supplierCall(slugs: ['swv-ldos'], status: 201, body: ['referenceId' => 'x']);

		$this->assertSame(expected: ['referenceId' => 'x'], actual: $call->json(slug: 'swv-ldos', endpoint: '/handoff', method: 'POST', withholdBodies: true));
		$this->assertArrayNotHasKey('logBody', $this->calls[0][3]);
		$this->assertTrue($this->calls[0][4]);
	}//end testWithheldBodiesAreNotLogged()

	/**
	 * A refused credential throws with the status, and says it was refused.
	 *
	 * @return void
	 */
	public function testARefusedCredentialThrowsWithItsStatus(): void {
		$call = $this->supplierCall(slugs: ['lvs-boom'], status: 401, body: '{"error":"invalid token"}');

		try {
			$call->json(slug: 'lvs-boom', endpoint: '/uwlr/results');
			$this->fail('A 401 must throw.');
		} catch (SupplierCallException $exception) {
			$this->assertSame(expected: 401, actual: $exception->getStatus());
			$this->assertTrue($exception->isRefusedCredential());
			$this->assertSame(expected: 'lvs-boom answered /uwlr/results with status 401.', actual: $exception->getMessage());
		}
	}//end testARefusedCredentialThrowsWithItsStatus()

	/**
	 * A missing source, a failing call and a non-JSON body each throw.
	 *
	 * @return void
	 */
	public function testMissingSourceFailedCallAndBadBodyThrow(): void {
		$messages = [];
		try {
			$this->supplierCall(slugs: ['other'], status: 200, body: '{}')->json(slug: 'lvs-iep', endpoint: '/x');
		} catch (SupplierCallException $exception) {
			$messages[] = [$exception->getStatus(), $exception->getMessage()];
		}

		try {
			$this->supplierCall(slugs: ['lvs-iep'], status: 200, body: 'not json')->json(slug: 'lvs-iep', endpoint: '/x');
		} catch (SupplierCallException $exception) {
			$messages[] = [$exception->getStatus(), $exception->getMessage()];
		}

		$objectService = $this->createMock(OrObjectService::class);
		$objectService->method('findAll')->willReturn(['results' => []]);
		$callService = $this->createMock(CallServiceInterface::class);
		$callService->expects($this->never())->method('call');
		try {
			(new SupplierSourceCall(callService: $callService, orObjectService: $objectService))->json(slug: 'lvs-dia', endpoint: '/x');
		} catch (SupplierCallException $exception) {
			$messages[] = [$exception->getStatus(), $exception->getMessage()];
		}

		$this->assertSame(
			expected: [
				[0, 'No source with slug lvs-iep is in register integriq. Add one with the supplier\'s address and credential.'],
				[200, 'lvs-iep answered /x without a JSON body.'],
				[0, 'No source with slug lvs-dia is in register integriq. Add one with the supplier\'s address and credential.'],
			],
			actual: $messages
		);
	}//end testMissingSourceFailedCallAndBadBodyThrow()

	/**
	 * An exception from CallService becomes a supplier call failure naming the call.
	 *
	 * @return void
	 */
	public function testACallServiceFailureIsWrapped(): void {
		$objectService = $this->createMock(OrObjectService::class);
		$source = new \OCA\OpenRegister\Db\ObjectEntity();
		$source->setObject(['slug' => 'swv-kindkans']);
		$objectService->method('findAll')->willReturn(['results' => [$source]]);
		$callService = $this->createMock(CallServiceInterface::class);
		$callService->method('call')->willThrowException(new RuntimeException('connection refused'));

		$this->expectException(SupplierCallException::class);
		$this->expectExceptionMessage('The call to swv-kindkans /handoff failed: connection refused');

		(new SupplierSourceCall(callService: $callService, orObjectService: $objectService))->json(slug: 'swv-kindkans', endpoint: '/handoff');
	}//end testACallServiceFailureIsWrapped()
}//end class
