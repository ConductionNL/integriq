<?php

/**
 * The contract of POST /api/outbound/identities/{id}/alignment
 *
 * This endpoint is network facing and shipped without a contract test, which
 * gate 25 caught. Every branch it can take is an answer somebody acts on: a
 * caller who is not signed in, a caller who is signed in but not allowed to
 * manage identities, an id nothing answers to, and the ordinary case where
 * the domain is asked and the verdict comes back.
 *
 * `atRisk` is the field worth guarding hardest. It is the one the interface
 * reads to decide whether to warn before a letter goes out, and the checker
 * and the controller are separate objects, so a controller that dropped it
 * would leave a screen that never warns and no test that noticed.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.integriq.app
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SenderIdentityController;
use OCA\Integriq\Outbound\Identity\DomainAlignmentChecker;
use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Service\ActionAuthService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SenderIdentityController::checkAlignment().
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
class SenderIdentityAlignmentContractTest extends TestCase {

	/**
	 * Build the controller over doubles.
	 *
	 * @param IUser|null $user The signed-in user, or null for nobody.
	 * @param bool $allowed Whether the action check passes.
	 * @param array<string, mixed>|null $identity The identity resolve() answers with, or null to raise.
	 * @param array<string, mixed> $alignment What the checker reports.
	 * @param bool $atRisk What the checker says about that report.
	 *
	 * @return SenderIdentityController The controller under test.
	 */
	private function controller(
		?IUser $user,
		bool $allowed = true,
		?array $identity = ['address' => 'post@example.nl'],
		array $alignment = [],
		bool $atRisk = false,
	): SenderIdentityController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$actionAuth = $this->createMock(ActionAuthService::class);
		if ($allowed === false) {
			$actionAuth->method('requireAction')
				->willThrowException(new OCSForbiddenException('not allowed'));
		}

		$identities = $this->createMock(SenderIdentityService::class);
		if ($identity === null) {
			$identities->method('resolve')->willThrowException(new RuntimeException('No sender identity "nope".'));
		} else {
			$identities->method('resolve')->willReturn(['identity' => $identity]);
		}

		$checker = $this->createMock(DomainAlignmentChecker::class);
		$checker->method('check')->willReturn($alignment);
		$checker->method('isAtRisk')->willReturn($atRisk);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new SenderIdentityController(
			'integriq',
			$this->createMock(IRequest::class),
			$session,
			$actionAuth,
			$identities,
			$checker,
			$this->createMock(UnsubscribeTokenService::class),
			$this->createMock(OptOutRegistry::class),
			$this->createMock(HoldQueue::class),
			$l
		);

	}//end controller()

	/**
	 * A signed-in, allowed caller gets the verdict and the risk flag.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	public function testTheVerdictAndTheRiskFlagBothReachTheCaller(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');

		$response = $this->controller(
			user: $user,
			alignment: [
				'spf' => ['state' => 'aligned'],
				'dkim' => ['state' => 'absent'],
				'dmarc' => ['state' => 'aligned'],
			],
			atRisk: true
		)->checkAlignment('identity-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$body = $response->getData();
		$this->assertSame('absent', $body['alignment']['dkim']['state']);
		$this->assertTrue(
			$body['atRisk'],
			'atRisk is what the interface warns on, so the controller must pass it through rather than recompute or drop it'
		);

	}//end testTheVerdictAndTheRiskFlagBothReachTheCaller()

	/**
	 * A caller who is not signed in is refused, and nothing is looked up.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller(user: null)->checkAlignment('identity-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnAnonymousCallerIsRefused()

	/**
	 * The least privileged principal that should be refused: signed in, but
	 * not allowed to manage identities.
	 *
	 * The action check raises rather than returning, so the refusal leaves
	 * the method. Asserting that it does is the point: a controller that
	 * swallowed it would answer 200 with a domain report to somebody who may
	 * not see it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	public function testASignedInCallerWithoutTheActionIsRefused(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');

		$this->expectException(OCSForbiddenException::class);
		$this->controller(user: $user, allowed: false)->checkAlignment('identity-1');

	}//end testASignedInCallerWithoutTheActionIsRefused()

	/**
	 * An id nothing answers to is a 404, not an empty report.
	 *
	 * An empty alignment report reads as "the domain says nothing", which is
	 * a real and different answer from "there is no such identity".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md
	 */
	public function testAnUnknownIdentityIsNotFound(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');

		$response = $this->controller(user: $user, identity: null)->checkAlignment('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey(
			'alignment',
			$response->getData(),
			'a missing identity must not come back carrying an empty report, which reads as a domain that said nothing'
		);

	}//end testAnUnknownIdentityIsNotFound()
}//end class
