<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\FlowToken;
use PHPUnit\Framework\TestCase;

/**
 * The four snapshot pairs of a flow token: each amended snapshot starts as
 * a copy of its original and is changed on its own, and serialising writes
 * all eight under their names.
 *
 * @covers \OCA\Integriq\Service\Helper\FlowToken
 */
final class FlowTokenSnapshotsTest extends TestCase {

	public function testEachAmendedSnapshotStartsAsItsOriginal(): void {
		$token = new FlowToken(
			requestOriginal: ['method' => 'POST'],
			responseOriginal: ['status' => 201],
			syncInputOriginal: ['in' => 1],
			syncOutputOriginal: ['out' => 2]
		);

		$this->assertSame(['method' => 'POST'], $token->getRequestAmended());
		$this->assertSame(['status' => 201], $token->getResponseAmended());
		$this->assertSame(['in' => 1], $token->getSyncInputAmended());
		$this->assertSame(['out' => 2], $token->getSyncOutputAmended());
	}

	public function testAmendingASyncSnapshotLeavesItsOriginal(): void {
		$token = new FlowToken(syncInputOriginal: ['in' => 1], syncOutputOriginal: ['out' => 2]);

		$this->assertSame(['in' => 9], $token->setSyncInputAmended(syncInputAmended: ['in' => 9]));
		$this->assertSame(['out' => 8], $token->setSyncOutputAmended(syncOutputAmended: ['out' => 8]));

		$this->assertSame(['in' => 9], $token->getSyncInputAmended());
		$this->assertSame(['in' => 1], $token->getSyncInputOriginal());
		$this->assertSame(['out' => 8], $token->getSyncOutputAmended());
		$this->assertSame(['out' => 2], $token->getSyncOutputOriginal());
	}

	public function testSerialisingWritesEverySnapshotByName(): void {
		$token = new FlowToken(requestOriginal: ['method' => 'GET'], responseOriginal: ['status' => 200], syncInputOriginal: ['a' => 1]);
		$token->setRequestAmended(requestAmended: ['method' => 'PUT']);
		$token->setSyncOutputAmended(syncOutputAmended: ['z' => 26]);

		$this->assertSame(
			[
				'requestOriginal' => ['method' => 'GET'],
				'requestAmended' => ['method' => 'PUT'],
				'responseOriginal' => ['status' => 200],
				'responseAmended' => ['status' => 200],
				'syncInputOriginal' => ['a' => 1],
				'syncInputAmended' => ['a' => 1],
				'syncOutputOriginal' => [],
				'syncOutputAmended' => ['z' => 26],
			],
			$token->__serialize()
		);
		$this->assertStringContainsString('s:17:"syncOutputAmended";a:1:{s:1:"z";i:26;}', serialize($token));
	}
}
