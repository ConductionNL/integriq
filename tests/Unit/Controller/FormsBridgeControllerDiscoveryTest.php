<?php

/**
 * Unit tests for FormsBridgeController's status, discovery and error mapping.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\FormsBridgeController;
use OCA\Integriq\Exception\FormsConfigException;
use OCA\Integriq\Exception\FormsFeatureDisabledException;
use OCA\Integriq\Exception\FormsNotFoundException;
use OCA\Integriq\Exception\FormsPermissionDeniedException;
use OCA\Integriq\Exception\FormsUpstreamException;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCA\Integriq\Service\Forms\FormsSyncAdapterInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The Forms status flag, form and question discovery for a source, and how each failure is answered.
 *
 * @covers \OCA\Integriq\Controller\FormsBridgeController
 * @uses   \OCA\Integriq\Exception\FormsConfigException
 * @uses   \OCA\Integriq\Exception\FormsFeatureDisabledException
 * @uses   \OCA\Integriq\Exception\FormsNotFoundException
 * @uses   \OCA\Integriq\Exception\FormsPermissionDeniedException
 * @uses   \OCA\Integriq\Exception\FormsUpstreamException
 */
final class FormsBridgeControllerDiscoveryTest extends TestCase {

	/** @var FormsSyncAdapterInterface&MockObject */
	private FormsSyncAdapterInterface $adapter;

	/** @var OrObjectService&MockObject */
	private OrObjectService $objects;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IUser|null */
	private ?IUser $user;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->adapter = $this->createMock(FormsSyncAdapterInterface::class);
		$this->objects = $this->createMock(OrObjectService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->user    = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The controller.
	 *
	 * @return FormsBridgeController
	 */
	private function controller(): FormsBridgeController {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		return new FormsBridgeController(
			'integriq',
			$this->createMock(IRequest::class),
			$this->adapter,
			$this->objects,
			$l,
			$this->logger,
			$session,
			$this->createMock(ActionAuthServiceInterface::class)
		);
	}//end controller()

	/**
	 * The status says whether Forms is enabled for the user, and refuses without one.
	 *
	 * @return void
	 */
	public function testStatusSaysWhetherFormsIsEnabledForTheUser(): void {
		$this->adapter->expects($this->once())->method('isEnabled')->with($this->user)->willReturn(true);
		$this->assertSame(['enabled' => true], $this->controller()->status()->getData());

		$this->user = null;
		$response   = $this->controller()->status();
		$this->assertSame(401, $response->getStatus());
	}//end testStatusSaysWhetherFormsIsEnabledForTheUser()

	/**
	 * The forms of a source are listed after checking Forms is enabled.
	 *
	 * @return void
	 */
	public function testFormsListsTheFormsOfTheSource(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Forms'], 'src-1');
		$this->objects->expects($this->once())->method('find')->willReturn($source);
		$this->adapter->expects($this->once())->method('assertEnabled')->with($this->user);
		$this->adapter->expects($this->once())->method('listFormsForEditor')->with($source)->willReturn([['id' => 3, 'title' => 'Intake']]);

		$response = $this->controller()->forms('src-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [['id' => 3, 'title' => 'Intake']]], $response->getData());
	}//end testFormsListsTheFormsOfTheSource()

	/**
	 * The questions of a form are listed for the source and form.
	 *
	 * @return void
	 */
	public function testQuestionsListsTheQuestionsOfTheForm(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, [], 'src-1');
		$this->objects->method('find')->willReturn($source);
		$this->adapter->expects($this->once())->method('listQuestionsForEditor')->with($source, 7)->willReturn([['id' => 1]]);

		$this->assertSame(['results' => [['id' => 1]]], $this->controller()->questions(7, 'src-1')->getData());
	}//end testQuestionsListsTheQuestionsOfTheForm()

	/**
	 * Questions need a source id.
	 *
	 * @return void
	 */
	public function testQuestionsNeedASourceId(): void {
		$this->adapter->expects($this->never())->method('listQuestionsForEditor');

		$response = $this->controller()->questions(7);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'sourceId is required'], $response->getData());
	}//end testQuestionsNeedASourceId()

	/**
	 * An unknown source answers 404 naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceIs404(): void {
		$this->objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->adapter->expects($this->never())->method('listFormsForEditor');

		$response = $this->controller()->forms('src-9');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Source not found: src-9'], $response->getData());
	}//end testAnUnknownSourceIs404()

	/**
	 * The status and body each failure kind is answered with.
	 *
	 * @return array<string, array{Throwable, int, array<string, string>, string|null}>
	 */
	public static function failures(): array {
		return [
			'Forms disabled'    => [new FormsFeatureDisabledException(), 409, ['error' => 'The Forms app is not enabled'], 'info'],
			'form not found'    => [new FormsNotFoundException('Form 7 not found'), 404, ['error' => 'Form 7 not found'], null],
			'permission denied' => [new FormsPermissionDeniedException('No access to form 7', 403), 403, ['error' => 'No access to form 7'], null],
			'config problem'    => [new FormsConfigException('No base URL', 422), 422, ['error' => 'No base URL'], null],
			'upstream failure'  => [new FormsUpstreamException('Forms said 500'), 502, ['error' => 'Upstream Forms call failed'], 'warning'],
			'anything else'     => [new RuntimeException('boom'), 502, ['error' => 'Unexpected error'], 'error'],
		];
	}//end failures()

	/**
	 * Each failure of the discovery is mapped to its status, and the noteworthy ones are logged.
	 *
	 * @dataProvider failures
	 *
	 * @param Throwable            $failure The failure.
	 * @param int                  $status  The expected status.
	 * @param array<string,string> $body    The expected body.
	 * @param string|null          $level   The log level it is logged at, if any.
	 *
	 * @return void
	 */
	public function testEachFailureIsMappedToItsStatus(Throwable $failure, int $status, array $body, ?string $level): void {
		$this->objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'src-1'));
		$this->adapter->method('listQuestionsForEditor')->willThrowException($failure);
		foreach (['info', 'warning', 'error'] as $method) {
			$this->logger->expects($method === $level ? $this->once() : $this->never())
				->method($method)
				->with($this->stringStartsWith('FormsBridgeController: '), ['message' => $failure->getMessage()]);
		}

		$response = $this->controller()->questions(7, 'src-1');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}//end testEachFailureIsMappedToItsStatus()

	/**
	 * A disabled Forms app stops the discovery before the source is looked up.
	 *
	 * @return void
	 */
	public function testADisabledFormsAppStopsBeforeTheSourceLookup(): void {
		$this->adapter->method('assertEnabled')->willThrowException(new FormsFeatureDisabledException());
		$this->objects->expects($this->never())->method('find');

		$this->assertSame(409, $this->controller()->forms('src-1')->getStatus());
	}//end testADisabledFormsAppStopsBeforeTheSourceLookup()
}//end class
