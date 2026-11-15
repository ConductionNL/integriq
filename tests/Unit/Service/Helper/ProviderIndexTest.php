<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\Helper\ProviderIndex;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Providers by id: the first one registered under an id wins, a lookup of an
 * unknown id names the kind and every registered id, and the ids keep
 * registration order.
 *
 * @covers \OCA\Integriq\Service\Helper\ProviderIndex
 * @uses   \OCA\Integriq\Service\Helper\ProviderIds
 */
final class ProviderIndexTest extends TestCase {

	public function testItFindsAProviderByItsId(): void {
		$alpha = $this->provider('alpha');
		$beta  = $this->provider('beta');
		$index = new ProviderIndex(kind: 'Oso', providers: [$alpha, $beta]);

		$this->assertTrue($index->has('beta'));
		$this->assertFalse($index->has('gamma'));
		$this->assertSame($beta, $index->get('beta'));
		$this->assertSame(['alpha', 'beta'], $index->ids());
	}

	public function testTheFirstProviderUnderAnIdWins(): void {
		$first  = $this->provider('log');
		$second = $this->provider('log');
		$index  = new ProviderIndex(kind: 'Oso', providers: [$first, $second]);

		$this->assertSame($first, $index->get('log'));
		$this->assertSame(['log'], $index->ids());
	}

	public function testItAcceptsAnyIterable(): void {
		$generator = (function () {
			yield $this->provider('a');
			yield $this->provider('b');
		})();

		$this->assertSame(['a', 'b'], (new ProviderIndex(kind: 'Rod', providers: $generator))->ids());
	}

	public function testAnUnknownIdNamesTheKindAndTheRegisteredIds(): void {
		$index = new ProviderIndex(kind: 'Verzuimloket', providers: [$this->provider('log'), $this->provider('rest')]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No Verzuimloket provider is registered under "soap". Registered providers: log, rest. Nothing was sent.');
		$index->get('soap');
	}

	public function testAnEmptyIndexSaysNone(): void {
		$index = new ProviderIndex(kind: 'Rod', providers: []);

		$this->assertSame([], $index->ids());
		$this->expectExceptionMessage('No Rod provider is registered under "log". Registered providers: (none). Nothing was sent.');
		$index->get('log');
	}

	private function provider(string $id): object {
		return new class($id) {
			public function __construct(private readonly string $id) {
			}

			public function getProviderId(): string {
				return $this->id;
			}
		};
	}
}
