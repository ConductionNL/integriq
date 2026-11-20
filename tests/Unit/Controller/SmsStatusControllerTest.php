<?php

/**
 * Unit tests for SmsStatusController.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\SmsStatusController;
use OCA\Integriq\Exception\SmsProviderException;
use OCA\Integriq\Service\Sms\SmsStatusCallback;
use OCA\Integriq\Service\SmsStatusCallbackService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The public vendor callback route.
 *
 * @covers \OCA\Integriq\Controller\SmsStatusController
 * @uses   \OCA\Integriq\Service\Sms\SmsStatusCallback
 * @uses   \OCA\Integriq\Exception\SmsProviderException
 *
 * @spec openspec/changes/messaging-sms-providers-and-notifynl-mail/specs/notifynl-sms-channel/spec.md#requirement-vendor-delivery-status-reaches-the-stored-message-req-smsp-004
 */
class SmsStatusControllerTest extends TestCase {

	/**
	 * How often the controller read the body.
	 *
	 * @var int
	 */
	private int $bodyReads = 0;

	/**
	 * A controller over the given service, reading a fixed body.
	 *
	 * @param SmsStatusCallbackService $service The service.
	 *
	 * @return SmsStatusController
	 */
	private function controller(SmsStatusCallbackService $service): SmsStatusController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['provider' => 'twilio', 'token' => 't', '_route' => 'r', 'MessageSid' => 'SM1']);
		$request->method('getHeader')->willReturnMap([['X-Twilio-Signature', 'sig'], ['MessageBird-Signature-JWT', '']]);
		$request->method('getServerProtocol')->willReturn('https');
		$request->method('getServerHost')->willReturn('cloud.example.nl');
		$request->method('getRequestUri')->willReturn('/apps/integriq/api/sms/twilio/status/t');
		$reads = &$this->bodyReads;

		return new class('integriq', $request, $service, new NullLogger(), $reads) extends SmsStatusController {
			/**
			 * @param string                   $appName   The app id.
			 * @param IRequest                 $request   The request.
			 * @param SmsStatusCallbackService $callbacks The service.
			 * @param NullLogger               $logger    The logger.
			 * @param int                      $reads     The body-read counter.
			 */
			public function __construct(string $appName, IRequest $request, SmsStatusCallbackService $callbacks, NullLogger $logger, private int &$reads) {
				parent::__construct($appName, $request, $callbacks, $logger);
			}

			/**
			 * @return string
			 */
			protected function getRawContent(): string {
				$this->reads++;
				return 'MessageSid=SM1';
			}
		};

	}//end controller()

	/**
	 * A wrong token is refused before the body is read, and nothing is applied.
	 *
	 * @return void
	 */
	public function testAWrongTokenIsRefusedBeforeTheBodyIsRead(): void {
		$service = $this->createMock(SmsStatusCallbackService::class);
		$service->expects($this->once())->method('sourceFor')->with('twilio', 'wrong')->willReturn(null);
		$service->expects($this->never())->method('receive');

		$response = $this->controller(service: $service)->status(provider: 'twilio', token: 'wrong');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'refused'], $response->getData());
		$this->assertSame(0, $this->bodyReads);

	}//end testAWrongTokenIsRefusedBeforeTheBodyIsRead()

	/**
	 * A matched token hands the callback, as it arrived, to the service.
	 *
	 * @return void
	 */
	public function testAMatchedCallbackIsReceived(): void {
		$source = new ObjectEntity();
		$service = $this->createMock(SmsStatusCallbackService::class);
		$service->method('sourceFor')->willReturn($source);
		$seen = null;
		$service->expects($this->once())->method('receive')->willReturnCallback(
			function (ObjectEntity $given, SmsStatusCallback $callback) use (&$seen, $source): ?ObjectEntity {
				$this->assertSame($source, $given);
				$seen = $callback;
				return null;
			}
		);

		$response = $this->controller(service: $service)->status(provider: 'twilio', token: 't');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['received' => true], $response->getData());
		$this->assertSame('https://cloud.example.nl/apps/integriq/api/sms/twilio/status/t', $seen->url);
		$this->assertSame('MessageSid=SM1', $seen->rawBody);
		$this->assertSame(['MessageSid' => 'SM1'], $seen->params);
		$this->assertSame('sig', $seen->header(name: 'X-Twilio-Signature'));
		$this->assertNull($seen->signingKey);

	}//end testAMatchedCallbackIsReceived()

	/**
	 * A bad signature answers the same 401 as a wrong token.
	 *
	 * @return void
	 */
	public function testABadSignatureIsRefused(): void {
		$service = $this->createMock(SmsStatusCallbackService::class);
		$service->method('sourceFor')->willReturn(new ObjectEntity());
		$service->method('receive')->willThrowException(new SmsProviderException(message: 'no', errorCode: 'bad_signature'));

		$response = $this->controller(service: $service)->status(provider: 'twilio', token: 't');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'refused'], $response->getData());

	}//end testABadSignatureIsRefused()
}//end class
