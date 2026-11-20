<?php

/**
 * Tests for ApiDesignCheckController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Integriq\Controller\ApiDesignCheckController;
use OCA\Integriq\Service\ApiDesign\ApiDesignFindingStore;
use OCA\Integriq\Service\ApiDesign\ApiProductDesignCheck;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * The check and waive endpoints.
 */
#[CoversClass(ApiDesignCheckController::class)]
class ApiDesignCheckControllerTest extends TestCase {

	/**
	 * Check mock.
	 *
	 * @var ApiProductDesignCheck&MockObject
	 */
	private ApiProductDesignCheck&MockObject $check;

	/**
	 * Store mock.
	 *
	 * @var ApiDesignFindingStore&MockObject
	 */
	private ApiDesignFindingStore&MockObject $store;

	/**
	 * The controller under test.
	 *
	 * @var ApiDesignCheckController
	 */
	private ApiDesignCheckController $controller;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->check = $this->createMock(ApiProductDesignCheck::class);
		$this->store = $this->createMock(ApiDesignFindingStore::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T12:00:00+00:00'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$this->controller = new ApiDesignCheckController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			check: $this->check,
			store: $this->store,
			userSession: $session,
			time: $time,
			l10n: $l10n,
			logger: new NullLogger()
		);

	}//end setUp()

	/**
	 * A check answers the findings.
	 *
	 * @return void
	 */
	public function testCheckAnswersTheFindings(): void {
		$result = ['findings' => [['ruleId' => '/core/a']], 'manual' => []];
		$this->check->expects(self::once())->method('checkProduct')->with('p1')->willReturn($result);

		$response = $this->controller->check(productId: 'p1');

		self::assertSame(200, $response->getStatus());
		self::assertSame($result, $response->getData());

	}//end testCheckAnswersTheFindings()

	/**
	 * A check that cannot run answers 409 with the reason.
	 *
	 * @return void
	 */
	public function testACheckThatCannotRunIsAConflict(): void {
		$this->check->method('checkProduct')->willThrowException(new RuntimeException('There is no such API product.'));

		$response = $this->controller->check(productId: 'nope');

		self::assertSame(409, $response->getStatus());
		self::assertSame(['error' => 'The API product could not be checked: There is no such API product.'], $response->getData());

	}//end testACheckThatCannotRunIsAConflict()

	/**
	 * A waiver is recorded for the signed-in administrator.
	 *
	 * @return void
	 */
	public function testWaiveRecordsTheAdministrator(): void {
		$this->store->expects(self::once())
			->method('waive')
			->with('f1', 'partner requires this path until 2027', 'admin', '2026-11-14T12:00:00+00:00')
			->willReturn(['id' => 'f1', 'status' => 'waived']);

		$response = $this->controller->waive(findingId: 'f1', reason: 'partner requires this path until 2027');

		self::assertSame(200, $response->getStatus());
		self::assertSame(['id' => 'f1', 'status' => 'waived'], $response->getData());

	}//end testWaiveRecordsTheAdministrator()

	/**
	 * A refused waiver is a bad request with the reason.
	 *
	 * @return void
	 */
	public function testARefusedWaiverIsABadRequest(): void {
		$this->store->method('waive')->willThrowException(new InvalidArgumentException('A waiver needs a reason.'));

		$response = $this->controller->waive(findingId: 'f1');

		self::assertSame(400, $response->getStatus());
		self::assertSame(['error' => 'A waiver needs a reason.'], $response->getData());

	}//end testARefusedWaiverIsABadRequest()

	/**
	 * Both endpoints are for administrators only.
	 *
	 * @return void
	 */
	public function testBothEndpointsAreAdminOnly(): void {
		foreach (['check', 'waive'] as $method) {
			$attributes = (new ReflectionMethod(ApiDesignCheckController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			self::assertCount(1, $attributes, $method);
		}

	}//end testBothEndpointsAreAdminOnly()
}//end class
