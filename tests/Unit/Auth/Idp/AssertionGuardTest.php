<?php

/**
 * Unit tests for AssertionGuard and the dormant adapter.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Auth\Idp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-replay-audience-confusion-and-idp-initiated-flows-are-rejected
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Auth\Idp;

use OCA\Integriq\Auth\Idp\AssertionGuard;
use OCA\Integriq\Auth\Idp\EnvelopeReplayGuard;
use OCA\Integriq\Auth\Idp\LogGovernmentIdpAdapter;
use OCA\Integriq\Exception\IdpAssertionException;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the four refusals and the dormant seam.
 *
 * @spec openspec/changes/idp-broker-envelope-runtime/specs/digid-eherkenning-auth-adapter/spec.md#requirement-dormant-seam-adapters-ship-config-flag-gated-and-inert
 */
class AssertionGuardTest extends TestCase {

	/**
	 * The configured SP EntityID.
	 *
	 * @var string
	 */
	private const AUDIENCE = 'https://gemeente-x.nl/sp';

	/**
	 * The outstanding request this broker sent.
	 *
	 * @var string
	 */
	private const REQUEST_ID = '_a1b2c3d4e5';

	/**
	 * A guard over a real in-memory shared cache.
	 *
	 * @return AssertionGuard The guard.
	 */
	private function guard(): AssertionGuard {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn(new FakeMemcache());

		return new AssertionGuard(replayGuard: new EnvelopeReplayGuard($factory));
	}//end guard()

	/**
	 * One assertion that should pass, with the caller's overrides applied.
	 *
	 * @param array<string,mixed> $overrides What to change.
	 *
	 * @return array<string,mixed> The assertion.
	 */
	private function assertion(array $overrides = []): array {
		return array_merge(
			[
				'id' => '_assertion-1',
				'inResponseTo' => self::REQUEST_ID,
				'audience' => self::AUDIENCE,
				'notBefore' => '2026-09-22T10:00:00Z',
				'notOnOrAfter' => '2026-09-22T10:05:00Z',
			],
			$overrides
		);
	}//end assertion()

	/**
	 * The clock inside the assertion's window.
	 *
	 * @return integer The unix time.
	 */
	private function inWindow(): int {
		return (int)strtotime('2026-09-22T10:02:00Z');
	}//end inWindow()

	/**
	 * A clean assertion passes.
	 *
	 * @return void
	 */
	public function testACleanAssertionPasses(): void {
		$this->guard()->assertAcceptable(
			assertion: $this->assertion(),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: $this->inWindow()
		);

		$this->addToAssertionCount(1);
	}//end testACleanAssertionPasses()

	/**
	 * An unsolicited response, correctly signed and completely valid, is
	 * refused because it answers no request of ours.
	 *
	 * @return void
	 */
	public function testAnUnsolicitedResponseIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('answers no outstanding request');

		$this->guard()->assertAcceptable(
			assertion: $this->assertion(['inResponseTo' => '']),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: $this->inWindow()
		);
	}//end testAnUnsolicitedResponseIsRefused()

	/**
	 * A response answering somebody else's request is refused.
	 *
	 * @return void
	 */
	public function testAMismatchedRequestIdIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('answers a different request');

		$this->guard()->assertAcceptable(
			assertion: $this->assertion(['inResponseTo' => '_somebody-else']),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: $this->inWindow()
		);
	}//end testAMismatchedRequestIdIsRefused()

	/**
	 * An assertion issued for another Service Provider is refused.
	 *
	 * @return void
	 */
	public function testAWrongAudienceIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('names another audience');

		$this->guard()->assertAcceptable(
			assertion: $this->assertion(['audience' => 'https://gemeente-y.nl/sp']),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: $this->inWindow()
		);
	}//end testAWrongAudienceIsRefused()

	/**
	 * An assertion presented before it is valid is refused, with no skew
	 * allowed.
	 *
	 * @return void
	 */
	public function testAnAssertionBeforeItsWindowIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('outside its validity window');

		$this->guard()->assertAcceptable(
			assertion: $this->assertion(),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: ((int)strtotime('2026-09-22T09:59:59Z'))
		);
	}//end testAnAssertionBeforeItsWindowIsRefused()

	/**
	 * `notOnOrAfter` means exactly that: the boundary second is already out.
	 *
	 * @return void
	 */
	public function testTheWindowIsClosedOnItsUpperBound(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('outside its validity window');

		$this->guard()->assertAcceptable(
			assertion: $this->assertion(),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: ((int)strtotime('2026-09-22T10:05:00Z'))
		);
	}//end testTheWindowIsClosedOnItsUpperBound()

	/**
	 * An assertion carrying no readable window is refused rather than read as
	 * always valid.
	 *
	 * @return void
	 */
	public function testAnUnreadableWindowIsRefused(): void {
		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('no readable validity window');

		$this->guard()->assertAcceptable(
			assertion: $this->assertion(['notOnOrAfter' => 'soon']),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: $this->inWindow()
		);
	}//end testAnUnreadableWindowIsRefused()

	/**
	 * The same assertion, valid in every other way, is refused the second
	 * time it arrives.
	 *
	 * @return void
	 */
	public function testAReplayedAssertionIsRefused(): void {
		$guard = $this->guard();

		$guard->assertAcceptable(
			assertion: $this->assertion(),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: $this->inWindow()
		);

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('already been used');

		$guard->assertAcceptable(
			assertion: $this->assertion(),
			outstandingRequestId: self::REQUEST_ID,
			expectedAudience: self::AUDIENCE,
			now: $this->inWindow()
		);
	}//end testAReplayedAssertionIsRefused()

	/**
	 * The dormant adapter refuses both calls and says it is not configured.
	 *
	 * @return void
	 */
	public function testTheDormantAdapterRefusesBothCalls(): void {
		$adapter = new LogGovernmentIdpAdapter(
			logger: $this->createMock(LoggerInterface::class),
			providerId: 'eherkenning'
		);

		$this->assertFalse($adapter->isConfigured());
		$this->assertSame('eherkenning', $adapter->getProviderId());

		try {
			$adapter->beginAuthentication(['organisation' => 'gemeente-x']);
			$this->fail('The dormant adapter started an authentication.');
		} catch (IdpAssertionException $exception) {
			$this->assertStringContainsString('Broker not configured', $exception->getMessage());
		}

		$this->expectException(IdpAssertionException::class);
		$this->expectExceptionMessage('Broker not configured');
		$adapter->readAssertion(['SAMLResponse' => 'PHNhbWw+']);
	}//end testTheDormantAdapterRefusesBothCalls()

	/**
	 * The dormant adapter logs the provider and the call, and no part of the
	 * callback payload. That payload is the one place a raw assertion exists.
	 *
	 * @return void
	 */
	public function testTheDormantAdapterNeverLogsTheCallbackPayload(): void {
		$captured = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []) use (&$captured) {
				$captured = $context;
			}
		);

		$adapter = new LogGovernmentIdpAdapter(logger: $logger, providerId: 'digid');

		try {
			$adapter->readAssertion(['SAMLResponse' => 'a-raw-assertion-with-a-bsn-in-it']);
		} catch (IdpAssertionException $exception) {
			$this->addToAssertionCount(1);
		}

		$this->assertSame('digid', $captured['provider']);
		$this->assertSame('readAssertion', $captured['call']);
		$this->assertStringNotContainsString(
			'a-raw-assertion-with-a-bsn-in-it',
			(string)json_encode($captured)
		);
	}//end testTheDormantAdapterNeverLogsTheCallbackPayload()

}//end class
