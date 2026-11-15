<?php

/**
 * The ZGW registry binding: its default, its usable flag and the reachability test.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Gateway;

use OCA\Integriq\Gateway\ZgwRegistryBinding;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Gateway\ZgwRegistryBinding
 */
class ZgwRegistryBindingTest extends TestCase {

	/**
	 * App config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * HTTP client mock.
	 *
	 * @var IClient&MockObject
	 */
	private IClient&MockObject $client;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The binding under test.
	 *
	 * @var ZgwRegistryBinding
	 */
	private ZgwRegistryBinding $binding;

	/**
	 * Build the binding over mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->client    = $this->createMock(originalClassName: IClient::class);
		$clientService   = $this->createMock(originalClassName: IClientService::class);
		$clientService->method('newClient')->willReturn(value: $this->client);
		$this->logger  = $this->createMock(originalClassName: LoggerInterface::class);
		$this->binding = new ZgwRegistryBinding(appConfig: $this->appConfig, clientService: $clientService, logger: $this->logger);
	}//end setUp()

	/**
	 * Expect exactly one write of the usable flag.
	 *
	 * @param string $flag The expected flag.
	 *
	 * @return void
	 */
	private function expectUsable(string $flag): void {
		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('integriq', ZgwRegistryBinding::USABLE_KEY, $flag);
	}//end expectUsable()

	/**
	 * A response with the given status.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return IResponse
	 */
	private function response(int $status): IResponse {
		$response = $this->createMock(originalClassName: IResponse::class);
		$response->method('getStatusCode')->willReturn(value: $status);

		return $response;
	}//end response()

	/**
	 * With nothing stored the binding is OpenRegister on this instance.
	 *
	 * @return void
	 */
	public function testCurrentDefaultsToOpenRegister(): void {
		$this->appConfig->method('getValueString')
			->with('integriq', ZgwRegistryBinding::BINDING_KEY, '')
			->willReturn(value: '');

		$this->assertSame(expected: ['kind' => 'openregister', 'name' => 'OpenRegister', 'baseUrl' => ''], actual: $this->binding->current());
	}//end testCurrentDefaultsToOpenRegister()

	/**
	 * A stored binding without a kind is ignored, as is a value that is not JSON.
	 *
	 * @return void
	 */
	public function testCurrentIgnoresAStoredValueWithoutAKind(): void {
		$this->appConfig->method('getValueString')->willReturnOnConsecutiveCalls('{"kind":"","baseUrl":"https://x"}', 'not json');

		$this->assertSame(expected: 'openregister', actual: $this->binding->current()['kind']);
		$this->assertSame(expected: 'openregister', actual: $this->binding->current()['kind']);
	}//end testCurrentIgnoresAStoredValueWithoutAKind()

	/**
	 * A stored external binding is returned as stored.
	 *
	 * @return void
	 */
	public function testCurrentReturnsTheStoredBinding(): void {
		$stored = ['kind' => 'external', 'name' => 'Open Zaak', 'baseUrl' => 'https://zaken.example/api/v1'];
		$this->appConfig->method('getValueString')->willReturn(value: json_encode($stored));

		$this->assertSame(expected: $stored, actual: $this->binding->current());
	}//end testCurrentReturnsTheStoredBinding()

	/**
	 * Only the stored flag "1" means usable.
	 *
	 * @return void
	 */
	public function testIsUsableReadsTheFlag(): void {
		$this->appConfig->method('getValueString')
			->with('integriq', ZgwRegistryBinding::USABLE_KEY, '0')
			->willReturnOnConsecutiveCalls('1', '0', 'yes');

		$this->assertTrue(condition: $this->binding->isUsable());
		$this->assertFalse(condition: $this->binding->isUsable());
		$this->assertFalse(condition: $this->binding->isUsable());
	}//end testIsUsableReadsTheFlag()

	/**
	 * The OpenRegister binding is usable without any request.
	 *
	 * @return void
	 */
	public function testTheOpenRegisterBindingIsUsableWithoutARequest(): void {
		$this->client->expects($this->never())->method('get');
		$this->expectUsable(flag: '1');

		$result = $this->binding->test(binding: ['kind' => 'openregister']);

		$this->assertTrue(condition: $result['ok']);
		$this->assertSame(expected: 'The binding resolves to OpenRegister on this instance.', actual: $result['message']);
		$this->assertSame(expected: ['kind' => 'openregister'], actual: $result['binding']);
	}//end testTheOpenRegisterBindingIsUsableWithoutARequest()

	/**
	 * Without an argument the stored binding is tested.
	 *
	 * @return void
	 */
	public function testWithoutAnArgumentTheStoredBindingIsTested(): void {
		$this->appConfig->method('getValueString')->willReturn(value: '');
		$this->expectUsable(flag: '1');

		$result = $this->binding->test();

		$this->assertTrue(condition: $result['ok']);
		$this->assertSame(expected: 'openregister', actual: $result['binding']['kind']);
	}//end testWithoutAnArgumentTheStoredBindingIsTested()

	/**
	 * An external binding without a base URL is not usable and sends nothing.
	 *
	 * @return void
	 */
	public function testAnExternalBindingWithoutABaseUrlIsNotUsable(): void {
		$this->client->expects($this->never())->method('get');
		$this->expectUsable(flag: '0');

		$result = $this->binding->test(binding: ['kind' => 'external']);

		$this->assertFalse(condition: $result['ok']);
		$this->assertSame(expected: 'An external ZGW binding needs a base URL.', actual: $result['message']);
	}//end testAnExternalBindingWithoutABaseUrlIsNotUsable()

	/**
	 * The test asks the registry for its zaken, once, with a timeout and a JSON accept header.
	 *
	 * @return void
	 */
	public function testAReachableRegistryIsUsable(): void {
		$this->client->expects($this->once())
			->method('get')
			->with('https://zaken.example/api/zaken', ['timeout' => 10, 'headers' => ['Accept' => 'application/json']])
			->willReturn(value: $this->response(status: 200));
		$this->expectUsable(flag: '1');

		$result = $this->binding->test(binding: ['kind' => 'external', 'baseUrl' => 'https://zaken.example/api/']);

		$this->assertTrue(condition: $result['ok']);
		$this->assertSame(expected: 'The ZGW registry at "https://zaken.example/api/" answered.', actual: $result['message']);
	}//end testAReachableRegistryIsUsable()

	/**
	 * A 401 is a reachable registry asking for credentials, so it counts as usable.
	 *
	 * @return void
	 */
	public function testARegistryAskingForCredentialsIsUsable(): void {
		$this->client->method('get')->willReturn(value: $this->response(status: 401));
		$this->expectUsable(flag: '1');

		$this->assertTrue(condition: $this->binding->test(binding: ['kind' => 'external', 'baseUrl' => 'https://z.example'])['ok']);
	}//end testARegistryAskingForCredentialsIsUsable()

	/**
	 * A 404 or a server error makes the binding unusable and names the status.
	 *
	 * @return void
	 */
	public function testANotFoundOrServerErrorIsNotUsable(): void {
		$this->client->method('get')->willReturnOnConsecutiveCalls($this->response(status: 404), $this->response(status: 500));
		$this->appConfig->expects($this->exactly(count: 2))
			->method('setValueString')
			->with('integriq', ZgwRegistryBinding::USABLE_KEY, '0');

		$first  = $this->binding->test(binding: ['kind' => 'external', 'baseUrl' => 'https://z.example']);
		$second = $this->binding->test(binding: ['kind' => 'external', 'baseUrl' => 'https://z.example']);

		$this->assertFalse(condition: $first['ok']);
		$this->assertSame(expected: 'The ZGW registry at "https://z.example" answered HTTP 404.', actual: $first['message']);
		$this->assertFalse(condition: $second['ok']);
		$this->assertSame(expected: 'The ZGW registry at "https://z.example" answered HTTP 500.', actual: $second['message']);
	}//end testANotFoundOrServerErrorIsNotUsable()

	/**
	 * A 499 is not a server error, so the binding stays usable.
	 *
	 * @return void
	 */
	public function testAClientErrorBelowFiveHundredOtherThanNotFoundIsUsable(): void {
		$this->client->method('get')->willReturn(value: $this->response(status: 499));
		$this->expectUsable(flag: '1');

		$this->assertTrue(condition: $this->binding->test(binding: ['kind' => 'external', 'baseUrl' => 'https://z.example'])['ok']);
	}//end testAClientErrorBelowFiveHundredOtherThanNotFoundIsUsable()

	/**
	 * An unreachable registry is not usable, is logged, and the message names the error.
	 *
	 * @return void
	 */
	public function testAnUnreachableRegistryIsNotUsableAndLogged(): void {
		$this->client->method('get')->willThrowException(exception: new RuntimeException('connection refused'));
		$this->expectUsable(flag: '0');
		$this->logger->expects($this->once())
			->method('warning')
			->with('zgw.binding.unreachable', ['baseUrl' => 'https://z.example', 'error' => 'connection refused']);

		$result = $this->binding->test(binding: ['kind' => 'external', 'baseUrl' => 'https://z.example']);

		$this->assertFalse(condition: $result['ok']);
		$this->assertSame(expected: 'The ZGW registry at "https://z.example" could not be reached: connection refused.', actual: $result['message']);
		$this->assertSame(expected: ['kind' => 'external', 'baseUrl' => 'https://z.example'], actual: $result['binding']);
	}//end testAnUnreachableRegistryIsNotUsableAndLogged()
}//end class
