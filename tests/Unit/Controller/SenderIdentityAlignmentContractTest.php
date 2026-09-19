<?php

/**
 * The contract of GET /api/outbound/identities/{id}/alignment.
 *
 * A new public endpoint, so this is its contract test: the three answers it
 * can give, and the shape of the body each one carries. The shape is the
 * product here — a panel branches on `atRisk` to decide whether to warn an
 * operator that mail from this identity is likely to be refused downstream.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Controller
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

namespace OCA\Integriq\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\Integriq\Controller\SenderIdentityController;
use OCA\Integriq\Outbound\Identity\DomainAlignmentChecker;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\SenderIdentityService;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SenderIdentityAlignmentContractTest extends TestCase {

	private SenderIdentityService&MockObject $identities;

	private DomainAlignmentChecker&MockObject $alignment;

	private function controller(?IUser $user = null): SenderIdentityController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new SenderIdentityController(
			'integriq',
			$this->createMock(IRequest::class),
			$session,
			$this->createMock(ActionAuthService::class),
			$this->identities,
			$this->alignment,
			$this->createMock(UnsubscribeTokenService::class),
			$this->createMock(OptOutRegistry::class),
			$this->createMock(HoldQueue::class),
			$l
		);
	}

	protected function setUp(): void {
		parent::setUp();
		$this->identities = $this->createMock(SenderIdentityService::class);
		$this->alignment = $this->createMock(DomainAlignmentChecker::class);
	}

	public function testAnAnonymousCallerIsRefused(): void {
		$response = $this->controller()->checkAlignment('id-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testAnUnknownIdentityAnswers404(): void {
		$this->identities->method('resolve')->willThrowException(new RuntimeException('No such identity.'));

		$response = $this->controller($this->createMock(IUser::class))->checkAlignment('nope');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testTheAnswerCarriesTheAlignmentAndWhetherItIsAtRisk(): void {
		// The two keys are the contract: a panel reads `atRisk` to decide
		// whether to warn, and `alignment` to say what to publish where.
		$report = ['spf' => ['state' => 'aligned'], 'dkim' => ['state' => 'absent']];
		$this->identities->method('resolve')->willReturn(['identity' => ['address' => 'post@example.org']]);
		$this->alignment->method('check')->willReturn($report);
		$this->alignment->method('isAtRisk')->willReturn(true);

		$response = $this->controller($this->createMock(IUser::class))->checkAlignment('id-1');
		$body = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayHasKey('alignment', $body);
		$this->assertArrayHasKey('atRisk', $body);
		$this->assertSame($report, $body['alignment']);
		$this->assertTrue($body['atRisk']);
	}
}//end class
