<?php

/**
 * Verification fails closed, and a retry is not a second call.
 *
 * Two rules carry this file. A binding that THROWS during verification has not
 * said yes, so the request is refused: a misconfigured source must refuse
 * rather than admit. And a source naming a binding this instance does not have
 * is refused too, never falling back to the sandbox, because a typo would
 * otherwise look like a working integration delivering to a log file.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Event\CallEvent;
use OCA\Integriq\Service\Kiss\CallContextService;
use OCA\Integriq\Service\Kiss\CallerDirectory;
use OCA\Integriq\Service\Kiss\CallerLookup;
use OCA\Integriq\Service\Kiss\CallEventDeduplicator;
use OCA\Integriq\Service\Kiss\CtiEventIntake;
use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use OCA\Integriq\Service\Kiss\CtiProviderInterface;
use OCA\Integriq\Service\Kiss\CtiSourceResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the CTI intake's refusals and its dispatch.
 */
class CtiEventIntakeTest extends TestCase {

	/**
	 * Events the dispatcher was handed.
	 *
	 * @var array<int, Event>
	 */
	private array $dispatched = [];

	/**
	 * Build an intake over a binding that behaves as told.
	 *
	 * @param CtiProviderInterface|null $provider The binding the source resolves to.
	 *
	 * @return CtiEventIntake The intake.
	 */
	private function intake(?CtiProviderInterface $provider): CtiEventIntake {
		$this->dispatched = [];

		$resolver = $this->createMock(originalClassName: CtiSourceResolver::class);
		$resolver->method('provider')->willReturn($provider);

		$store = [];
		$cache = $this->createMock(originalClassName: ICache::class);
		// A closure with `use (&$store)`, NOT an arrow function: an arrow
		// function captures by VALUE, so it would never see the writes below
		// and every retry would look like a first sight. The test would then
		// pass for a deduplicator that does nothing.
		$cache->method('hasKey')->willReturnCallback(
			static function (string $key) use (&$store): bool {
				return array_key_exists($key, $store);
			}
		);
		$cache->method('set')->willReturnCallback(
			static function (string $key) use (&$store): bool {
				$store[$key] = 1;
				return true;
			}
		);
		$factory = $this->createMock(originalClassName: ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);

		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->dispatched[] = $event;
			}
		);

		return new CtiEventIntake(
			sources: $resolver,
			normaliser: new CallEventNormaliser(),
			deduplicator: new CallEventDeduplicator(cacheFactory: $factory),
			context: new CallContextService(
				lookup: new CallerLookup(),
				directory: new CallerDirectory(search: null),
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			),
			dispatcher: $dispatcher,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end intake()

	/**
	 * A binding that answers as told.
	 *
	 * @param boolean $verifies What verify() answers.
	 * @param array $events What normalize() answers.
	 * @param boolean $throwsOnVerify Whether verify() throws instead.
	 *
	 * @return CtiProviderInterface The binding.
	 */
	private function provider(bool $verifies, array $events = [], bool $throwsOnVerify = false): CtiProviderInterface {
		$provider = $this->createMock(originalClassName: CtiProviderInterface::class);

		if ($throwsOnVerify === true) {
			$provider->method('verify')->willThrowException(new RuntimeException('config is nonsense'));
		} else {
			$provider->method('verify')->willReturn($verifies);
		}

		$provider->method('normalize')->willReturn($events);

		return $provider;

	}//end provider()

	/**
	 * A verified payload is dispatched.
	 *
	 * @return void
	 */
	public function testAVerifiedPayloadIsDispatched(): void {
		$intake = $this->intake(
			provider: $this->provider(
				verifies: true,
				events: [['kind' => 'ringing', 'callId' => '42', 'callerNumber' => '+31612345678', 'agentId' => 'a7', 'at' => '']]
			)
		);

		$accepted = $intake->handle(source: ['configuration' => []], sourceId: 'pbx-1', payload: []);

		$this->assertSame(1, $accepted);
		$this->assertCount(1, $this->dispatched);
		$this->assertInstanceOf(CallEvent::class, $this->dispatched[0]);
		$this->assertSame('42', $this->dispatched[0]->callId);
		$this->assertSame('pbx-1', $this->dispatched[0]->sourceId);

	}//end testAVerifiedPayloadIsDispatched()

	/**
	 * A binding that throws during verification has not said yes.
	 *
	 * @return void
	 */
	public function testABindingThatThrowsDuringVerificationIsRefused(): void {
		// Fail closed. A misconfigured source must refuse rather than admit,
		// and an exception is the shape a misconfiguration usually arrives in.
		$refused = $this->intake(provider: $this->provider(verifies: true, throwsOnVerify: true))
			->verify(source: ['configuration' => []], headers: [], rawBody: '{}');

		$this->assertFalse($refused);

	}//end testABindingThatThrowsDuringVerificationIsRefused()

	/**
	 * A source naming no installed binding is refused, never fallen back.
	 *
	 * @return void
	 */
	public function testASourceNamingNoInstalledBindingIsRefused(): void {
		// Falling back to the sandbox would make a typo in a source's
		// configuration look like a working integration that quietly
		// delivers to a log file.
		$intake = $this->intake(provider: null);

		$this->assertFalse($intake->verify(source: ['configuration' => []], headers: [], rawBody: '{}'));
		$this->assertSame(0, $intake->handle(source: ['configuration' => []], sourceId: 'pbx-1', payload: []));

	}//end testASourceNamingNoInstalledBindingIsRefused()

	/**
	 * A retry does not pop the panel twice.
	 *
	 * @return void
	 */
	public function testARetryDoesNotPopThePanelTwice(): void {
		$events = [['kind' => 'ringing', 'callId' => '42', 'callerNumber' => '+31612345678', 'agentId' => '', 'at' => '']];
		$intake = $this->intake(provider: $this->provider(verifies: true, events: $events));

		$first  = $intake->handle(source: ['configuration' => []], sourceId: 'pbx-1', payload: []);
		$second = $intake->handle(source: ['configuration' => []], sourceId: 'pbx-1', payload: []);

		$this->assertSame(1, $first);
		$this->assertSame(0, $second, 'the retry is accepted with nothing dispatched');
		$this->assertCount(1, $this->dispatched);

	}//end testARetryDoesNotPopThePanelTwice()

	/**
	 * A payload with nothing this integration handles is accepted, not refused.
	 *
	 * @return void
	 */
	public function testAPayloadWithNothingToHandleIsAcceptedNotRefused(): void {
		// A refusal would make the PBX retry a keep-alive that will never be
		// wanted, forever.
		$accepted = $this->intake(provider: $this->provider(verifies: true, events: []))
			->handle(source: ['configuration' => []], sourceId: 'pbx-1', payload: []);

		$this->assertSame(0, $accepted);
		$this->assertCount(0, $this->dispatched);

	}//end testAPayloadWithNothingToHandleIsAcceptedNotRefused()

	/**
	 * An unidentified caller still rings, with no cases attached.
	 *
	 * @return void
	 */
	public function testAnUnidentifiedCallerStillRings(): void {
		$intake = $this->intake(
			provider: $this->provider(
				verifies: true,
				events: [['kind' => 'ringing', 'callId' => '99', 'callerNumber' => '+31612345678', 'agentId' => '', 'at' => '']]
			)
		);

		$intake->handle(source: ['configuration' => []], sourceId: 'pbx-1', payload: []);

		$event = $this->dispatched[0];
		$this->assertNull($event->caller);
		$this->assertSame([], $event->openCases);
		$this->assertFalse($event->isAnonymous(), 'the number arrived; nobody matched it');

	}//end testAnUnidentifiedCallerStillRings()

	/**
	 * Only the headers a binding is allowed to verify on are handed over.
	 *
	 * @return void
	 */
	public function testOnlyAllowedHeadersAreHandedToABinding(): void {
		// An allowlist, not the whole request. Handing every header over makes
		// it easy to write a binding that authenticates on something it should
		// not, such as a forwarded address.
		$this->assertContains('authorization', CtiEventIntake::VERIFIABLE_HEADERS);
		$this->assertContains('x-signature', CtiEventIntake::VERIFIABLE_HEADERS);
		$this->assertNotContains('x-forwarded-for', CtiEventIntake::VERIFIABLE_HEADERS);
		$this->assertNotContains('cookie', CtiEventIntake::VERIFIABLE_HEADERS);

	}//end testOnlyAllowedHeadersAreHandedToABinding()

}//end class
