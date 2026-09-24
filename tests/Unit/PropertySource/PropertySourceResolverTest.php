<?php

/**
 * Integriq — property-source resolver tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\PropertySource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\PropertySource;

use OCA\Integriq\PropertySource\Exception\SourceUnreachableException;
use OCA\Integriq\PropertySource\PropertySourceProviderInterface;
use OCA\Integriq\PropertySource\PropertySourceRegistry;
use OCA\Integriq\PropertySource\PropertySourceResolver;
use OCA\Integriq\PropertySource\ResolvedValue;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-RFS-002, REQ-RFS-003, REQ-RFS-004 and REQ-RFS-005.
 *
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-live-means-a-stated-staleness-budget-req-rfs-004
 * @spec openspec/changes/registry-backed-field-source/specs/registry-field-source/spec.md#requirement-an-unreachable-source-degrades-to-a-labelled-last-value-req-rfs-005
 */
class PropertySourceResolverTest extends TestCase {
	/**
	 * In-memory stand-in for the distributed cache.
	 *
	 * @var array<string,mixed>
	 */
	private array $store = [];

	/**
	 * The cache double reading and writing $store.
	 *
	 * @var ICache&MockObject
	 */
	private ICache $cache;

	/**
	 * Set up an in-memory cache double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		$this->cache = $this->createMock(ICache::class);
		$this->cache->method('get')->willReturnCallback(fn (string $key) => ($this->store[$key] ?? null));
		$this->cache->method('set')->willReturnCallback(
			function (string $key, $value, int $ttl = 0): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
	}//end setUp()

	/**
	 * Build a resolver around one provider double.
	 *
	 * @param PropertySourceProviderInterface $provider The provider.
	 *
	 * @return PropertySourceResolver The resolver under test.
	 */
	private function resolver(PropertySourceProviderInterface $provider): PropertySourceResolver {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		return new PropertySourceResolver(
			new PropertySourceRegistry([$provider]),
			$factory,
			$this->createMock(LoggerInterface::class)
		);
	}//end resolver()

	/**
	 * A provider double with a stated budget.
	 *
	 * @param int $budget Staleness budget in seconds.
	 *
	 * @return PropertySourceProviderInterface&MockObject The double.
	 */
	private function provider(int $budget = 600): PropertySourceProviderInterface {
		$provider = $this->createMock(PropertySourceProviderInterface::class);
		$provider->method('id')->willReturn('brp');
		$provider->method('describe')->willReturn(
			[
				'id' => 'brp',
				'label' => 'BRP person',
				'identifier' => 'burgerservicenummer',
				'stalenessBudget' => $budget,
				'listShaped' => false,
			]
		);

		return $provider;
	}//end provider()

	/**
	 * Seed a cache entry for one identifier at one provider.
	 *
	 * @param string $identifier Identifier at the source.
	 * @param array<string,mixed> $value The cached value.
	 * @param int $ageSeconds How old the entry is.
	 *
	 * @return void
	 */
	private function seedCache(string $identifier, array $value, int $ageSeconds): void {
		$this->store['brp:' . sha1($identifier)] = ['value' => $value, 'readAt' => (time() - $ageSeconds)];
	}//end seedCache()

	/**
	 * A value read from the source carries provider, identifier and read time.
	 *
	 * @return void
	 */
	public function testAResolvedValueCarriesItsProvenance(): void {
		$provider = $this->provider();
		$provider->expects($this->once())->method('resolve')->with('999993653')->willReturn(['naam' => 'De Vries']);

		$resolved = $this->resolver($provider)->resolve('brp', '999993653');

		$this->assertSame(['naam' => 'De Vries'], $resolved->getValue());
		$this->assertSame('brp', $resolved->getProviderId());
		$this->assertSame('999993653', $resolved->getSourceIdentifier());
		$this->assertSame(ResolvedValue::ORIGIN_SOURCE, $resolved->getOrigin());
		$this->assertNotNull($resolved->getReadAt());
		$this->assertTrue($resolved->isLive());
	}//end testAResolvedValueCarriesItsProvenance()

	/**
	 * A hand-typed value says nobody looked it up.
	 *
	 * @return void
	 */
	public function testAManualValueCarriesNoProviderId(): void {
		$manual = $this->resolver($this->provider())->manual(['straat' => 'Kerkstraat']);

		$this->assertSame(ResolvedValue::ORIGIN_MANUAL, $manual->getOrigin());
		$this->assertNull($manual->getProviderId());
		$this->assertFalse($manual->isLive());
		$this->assertSame('manual', $manual->toArray()['provenance']['origin']);
	}//end testAManualValueCarriesNoProviderId()

	/**
	 * A cached answer inside the budget reports that it came from cache, and
	 * how old the entry is.
	 *
	 * @return void
	 */
	public function testACachedAnswerReportsItsAge(): void {
		$provider = $this->provider(600);
		$provider->expects($this->never())->method('resolve');
		$this->seedCache('999993653', ['naam' => 'De Vries'], 120);

		$resolved = $this->resolver($provider)->resolve('brp', '999993653');

		$this->assertSame(ResolvedValue::ORIGIN_CACHE, $resolved->getOrigin());
		$this->assertEqualsWithDelta(120, $resolved->getCacheAgeSeconds(), 2.0);
		$this->assertFalse($resolved->isLive(), 'A cached value must never be described as live.');
	}//end testACachedAnswerReportsItsAge()

	/**
	 * An entry older than the budget triggers a source read, and the cache is
	 * replaced.
	 *
	 * @return void
	 */
	public function testAStaleEntryTriggersASourceRead(): void {
		$provider = $this->provider(600);
		$provider->expects($this->once())->method('resolve')->willReturn(['naam' => 'Nieuw']);
		$this->seedCache('999993653', ['naam' => 'Oud'], 900);

		$resolved = $this->resolver($provider)->resolve('brp', '999993653');

		$this->assertSame(['naam' => 'Nieuw'], $resolved->getValue());
		$this->assertSame(ResolvedValue::ORIGIN_SOURCE, $resolved->getOrigin());
		$this->assertSame(['naam' => 'Nieuw'], $this->store['brp:' . sha1('999993653')]['value']);
	}//end testAStaleEntryTriggersASourceRead()

	/**
	 * A caller may demand a fresh read inside the budget.
	 *
	 * @return void
	 */
	public function testACallerCanDemandAFreshRead(): void {
		$provider = $this->provider(600);
		$provider->expects($this->once())->method('resolve')->willReturn(['naam' => 'Vers']);
		$this->seedCache('999993653', ['naam' => 'Oud'], 10);

		$resolved = $this->resolver($provider)->resolve('brp', '999993653', [], true);

		$this->assertSame(['naam' => 'Vers'], $resolved->getValue());
		$this->assertTrue($resolved->isLive());
	}//end testACallerCanDemandAFreshRead()

	/**
	 * An unreachable source degrades to the last known value, labelled, with
	 * its age and an explicit unreachable state.
	 *
	 * @return void
	 */
	public function testAnUnreachableSourceDegradesToALabelledLastValue(): void {
		$provider = $this->provider(60);
		$provider->method('resolve')->willThrowException(new SourceUnreachableException('brp', 'down'));
		$this->seedCache('999993653', ['naam' => 'Laatst bekend'], 3600);

		$resolved = $this->resolver($provider)->resolve('brp', '999993653');

		$this->assertSame(['naam' => 'Laatst bekend'], $resolved->getValue());
		$this->assertTrue($resolved->isUnreachable());
		$this->assertSame(ResolvedValue::ORIGIN_CACHE, $resolved->getOrigin());
		$this->assertEqualsWithDelta(3600, $resolved->getCacheAgeSeconds(), 2.0);
		$this->assertFalse($resolved->isLive());
	}//end testAnUnreachableSourceDegradesToALabelledLastValue()

	/**
	 * Unreachable with nothing cached returns no value, and says so, rather
	 * than a blank that reads like an answer.
	 *
	 * @return void
	 */
	public function testAnUnreachableSourceWithNothingCachedReturnsNoValueAndSaysSo(): void {
		$provider = $this->provider(60);
		$provider->method('resolve')->willThrowException(new SourceUnreachableException('brp', 'down'));

		$resolved = $this->resolver($provider)->resolve('brp', '999993653');

		$this->assertNull($resolved->getValue());
		$this->assertTrue($resolved->isUnreachable());
		$this->assertFalse($resolved->isLive());
	}//end testAnUnreachableSourceWithNothingCachedReturnsNoValueAndSaysSo()

	/**
	 * A suggestion is not an answer: it is resolved by its identifier, and the
	 * payload it carried is discarded.
	 *
	 * @return void
	 */
	public function testASuggestionIsResolvedByItsIdentifierBeforeItIsStored(): void {
		$provider = $this->provider(600);
		$provider->expects($this->once())->method('resolve')->with('999993653')->willReturn(['naam' => 'Bron']);

		$resolved = $this->resolver($provider)->resolveSuggestion(
			'brp',
			['identifier' => '999993653', 'label' => 'De Vries', 'naam' => 'Type-ahead label']
		);

		$this->assertSame(['naam' => 'Bron'], $resolved->getValue());
		$this->assertSame(ResolvedValue::ORIGIN_SOURCE, $resolved->getOrigin());
	}//end testASuggestionIsResolvedByItsIdentifierBeforeItIsStored()

	/**
	 * A suggestion is marked as not authoritative, so nothing can mistake one
	 * for a value.
	 *
	 * @return void
	 */
	public function testASuggestionIsMarkedAsNotAuthoritative(): void {
		$provider = $this->provider();
		$provider->method('suggest')->willReturn([['identifier' => '999993653', 'label' => 'De Vries']]);

		$suggestions = $this->resolver($provider)->suggest('brp', 'de vri');

		$this->assertCount(1, $suggestions);
		$this->assertFalse($suggestions[0]['authoritative']);
		$this->assertSame('brp', $suggestions[0]['provider']);
	}//end testASuggestionIsMarkedAsNotAuthoritative()
}//end class
