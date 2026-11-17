<?php

/**
 * Integriq — the registry subscription pieces at their edges.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/registry-subscription-connector/spec.md#requirement-a-subscription-provider-per-registry-req-rsc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Registry;

use OCA\Integriq\Service\Registry\ChangeRowShape;
use OCA\Integriq\Service\Registry\LogSubscriptionProvider;
use OCA\Integriq\Service\Registry\SubscriptionChange;
use OCA\Integriq\Service\Registry\SubscriptionRegistry;
use OCA\Integriq\Service\Registry\SubscriptionResult;
use OCA\Integriq\Service\Registry\SubscriptionRoster;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A stored roster that is not a map, an identity that cannot be stored, the
 * log binding's unsubscribe and unfiltered poll, a second binding for the same
 * registry, a feed that is not a list, and the identity a result carries.
 */
#[CoversClass(SubscriptionRoster::class)]
#[CoversClass(LogSubscriptionProvider::class)]
#[CoversClass(SubscriptionRegistry::class)]
#[CoversClass(ChangeRowShape::class)]
#[CoversClass(SubscriptionResult::class)]
#[UsesClass(SubscriptionChange::class)]
class RegistryEdgeCasesTest extends TestCase {

	/**
	 * The app config values, by key.
	 *
	 * @var array<string,string>
	 */
	private array $stored = [];

	/**
	 * A roster over an in-memory app config.
	 *
	 * @return SubscriptionRoster
	 */
	private function roster(): SubscriptionRoster {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		return new SubscriptionRoster($appConfig);
	}//end roster()

	/**
	 * Stored text that is not a JSON map reads as an empty roster, and a
	 * target entry that is not a list reads as no targets.
	 *
	 * @return void
	 */
	public function testAStoredValueThatIsNotAMapReadsAsEmpty(): void {
		$this->stored[SubscriptionRoster::KEY_PREFIX . 'brp'] = '"999993653"';
		$this->stored[SubscriptionRoster::TARGETS_PREFIX . 'brp'] = '[broken';
		$this->stored[SubscriptionRoster::TARGETS_PREFIX . 'kvk'] = '{"12345678":"organisation"}';
		$roster = $this->roster();

		$this->assertSame([], $roster->identities(registryId: 'brp'));
		$this->assertSame([], $roster->targets(registryId: 'brp', identity: '999993653'));
		$this->assertSame([], $roster->targets(registryId: 'kvk', identity: '12345678'));

		$roster->add(registryId: 'brp', identity: '999993653', reference: 'v-1');
		$this->assertSame(['999993653' => 'v-1'], $roster->identities(registryId: 'brp'));
	}//end testAStoredValueThatIsNotAMapReadsAsEmpty()

	/**
	 * An identity that is not UTF-8 cannot be encoded: an empty map is stored
	 * instead of a broken value or nothing.
	 *
	 * @return void
	 */
	public function testAnIdentityThatCannotBeEncodedStoresAnEmptyMap(): void {
		$roster = $this->roster();
		$roster->add(registryId: 'brp', identity: "\xB1\x31", reference: 'v-1');
		$roster->addTarget(registryId: 'brp', identity: "\xB1\x31", targetSchema: 'person');

		$this->assertSame(
			[SubscriptionRoster::KEY_PREFIX . 'brp' => '{}', SubscriptionRoster::TARGETS_PREFIX . 'brp' => '{}'],
			$this->stored
		);
	}//end testAnIdentityThatCannotBeEncodedStoresAnEmptyMap()

	/**
	 * The log binding logs an unsubscribe, and a poll without identities hands
	 * over every queued change once.
	 *
	 * @return void
	 */
	public function testTheLogBindingLogsAnUnsubscribeAndPollsEverythingWithoutIdentities(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$provider = new LogSubscriptionProvider($logger);
		$provider->unsubscribe(identity: '999993653');
		$this->assertSame([['registry-subscription.log.unsubscribe', ['identity' => '999993653']]], $logged);

		$first = new SubscriptionChange('999993653', ['naam' => 'De Vries'], 'e-1');
		$second = new SubscriptionChange('999990019', ['adres' => 'Markt 1'], 'e-2');
		$provider->queue($first);
		$provider->queue($second);
		$this->assertSame([$first, $second], $provider->pollChanges());
		$this->assertSame([], $provider->pollChanges());

		$provider->queue($first);
		$provider->queue($second);
		$this->assertSame([$second], $provider->pollChanges(identities: ['999990019']));
	}//end testTheLogBindingLogsAnUnsubscribeAndPollsEverythingWithoutIdentities()

	/**
	 * A second binding for a registry that already has one is left out; the
	 * first one stays the binding of that registry.
	 *
	 * @return void
	 */
	public function testTheFirstBindingOfARegistryWins(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$first = new LogSubscriptionProvider($logger);
		$second = new LogSubscriptionProvider($logger);
		$registry = new SubscriptionRegistry([$first, $second]);

		$this->assertSame([$first->registryId() => $first], $registry->all());
		$this->assertSame($first, $registry->get($first->registryId()));
		$this->assertNull($registry->get('kvk'));
		$this->assertSame([], (new SubscriptionRegistry())->all());
	}//end testTheFirstBindingOfARegistryWins()

	/**
	 * A feed that is not a list of rows has no changes.
	 *
	 * @return void
	 */
	public function testAFeedThatIsNotAListHasNoChanges(): void {
		$shape = new ChangeRowShape(identityKey: 'bsn', referenceKey: 'eventId');

		$this->assertSame([], $shape->changesIn('not rows'));
		$this->assertSame([], $shape->changesIn(null));
		$this->assertSame([], $shape->changesIn(42));
	}//end testAFeedThatIsNotAListHasNoChanges()

	/**
	 * A result carries the identity it is about, active or failed.
	 *
	 * @return void
	 */
	public function testAResultCarriesItsIdentity(): void {
		$active = SubscriptionResult::active('999993653', 'v-1');
		$failed = SubscriptionResult::failed('999990019', 'Onbekend BSN');

		$this->assertSame(['999993653', '999990019'], [$active->getIdentity(), $failed->getIdentity()]);
		$this->assertSame(
			['state' => $failed->getState(), 'identity' => '999990019', 'error' => 'Onbekend BSN', 'reference' => ''],
			$failed->toArray()
		);
	}//end testAResultCarriesItsIdentity()
}//end class
