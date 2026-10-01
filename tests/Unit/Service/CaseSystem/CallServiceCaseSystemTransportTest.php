<?php

/**
 * Unit tests for the transport under the case-system ZGW mapping.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-adding-a-document-maps-kind-and-confidentiality-onto-zgw-req-cso-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\CaseSystem;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Sends on the named source, and only under its location.
 */
class CallServiceCaseSystemTransportTest extends TestCase {

	private const LOCATION = 'https://zaken.example.nl/api/v1';

	/**
	 * A transport over a named source whose CallService answers the given log.
	 *
	 * @param array      $log   The call log CallService answers.
	 * @param array|null $calls Receives each CallService call.
	 *
	 * @return CallServiceCaseSystemTransport
	 */
	private function transport(array $log, ?array &$calls = null): CallServiceCaseSystemTransport {
		$calls = [];
		$source = new ObjectEntity();
		$source->setUuid('dddddddd-0000-4000-8000-000000000001');
		$source->setObject(['name' => 'Zaken API', 'location' => self::LOCATION . '/', 'isEnabled' => true]);

		$store = $this->createMock(ConnectionStore::class);
		$store->method('findSource')->willReturnCallback(fn (string $uuid) => ($uuid === $source->getUuid() ? $source : null));

		$callService = $this->createMock(CallService::class);
		$callService->method('call')->willReturnCallback(
			function (...$arguments) use ($log, &$calls) {
				$calls[] = $arguments;
				$entity = new ObjectEntity();
				$entity->setObject($log);

				return $entity;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(CallService::class)->willReturn($callService);

		return new CallServiceCaseSystemTransport(container: $container, store: $store);
	}//end transport()

	/**
	 * An absolute address under the location is sent as a path; the JSON answer is decoded.
	 *
	 * @return void
	 */
	public function testAnAddressUnderTheLocationIsSentAsAPath(): void {
		$transport = $this->transport(['response' => ['statusCode' => 200, 'body' => '{"identificatie":"Z-1"}', 'encoding' => 'UTF-8']], $calls);

		$answer = $transport->send(
			sourceId: 'dddddddd-0000-4000-8000-000000000001',
			method: 'GET',
			address: self::LOCATION . '/zaken/1',
			options: ['headers' => ['Accept-Crs' => 'EPSG:4326']]
		);

		$this->assertSame(200, $answer['status']);
		$this->assertSame(['identificatie' => 'Z-1'], $answer['data']);
		$this->assertSame('/zaken/1', $calls[0][1]);
		$this->assertSame('GET', $calls[0][2]);
		$this->assertSame(['headers' => ['Accept-Crs' => 'EPSG:4326']], $calls[0][3]);
	}//end testAnAddressUnderTheLocationIsSentAsAPath()

	/**
	 * An address on another host is refused and never sent.
	 *
	 * @return void
	 */
	public function testAnAddressOnAnotherHostIsRefused(): void {
		foreach (['https://evil.example/zaken/1', 'https://zaken.example.nl/api/v10/zaken/1', 'https://zaken.example.nl.evil.example/api/v1/zaken'] as $address) {
			$transport = $this->transport(['response' => ['statusCode' => 200, 'body' => '{}']], $calls);
			try {
				$transport->send(sourceId: 'dddddddd-0000-4000-8000-000000000001', method: 'GET', address: $address);
				$this->fail('Must refuse ' . $address);
			} catch (CaseSystemRefusal $refusal) {
				$this->assertSame(422, $refusal->getStatus());
			}

			$this->assertSame([], $calls, $address);
		}
	}//end testAnAddressOnAnotherHostIsRefused()

	/**
	 * A binary body logged in base64 comes back as its bytes.
	 *
	 * @return void
	 */
	public function testABase64LoggedBodyComesBackAsBytes(): void {
		$bytes = "%PDF\x00\xff";
		$transport = $this->transport(['response' => ['statusCode' => 200, 'body' => base64_encode($bytes), 'encoding' => 'base64']]);

		$this->assertSame($bytes, $transport->send(sourceId: 'dddddddd-0000-4000-8000-000000000001', method: 'GET', address: '/x')['raw']);
	}//end testABase64LoggedBodyComesBackAsBytes()

	/**
	 * A call CallService stopped before sending (a disabled source) carries its reason as the detail.
	 *
	 * @return void
	 */
	public function testAnEarlyStopCarriesItsReason(): void {
		$transport = $this->transport(['statusCode' => 409, 'statusMessage' => 'This source is not enabled']);

		$answer = $transport->send(sourceId: 'dddddddd-0000-4000-8000-000000000001', method: 'GET', address: '/zaken');

		$this->assertSame(409, $answer['status']);
		$this->assertSame(['detail' => 'This source is not enabled'], $answer['data']);
	}//end testAnEarlyStopCarriesItsReason()

	/**
	 * A source uuid that does not exist is a 409.
	 *
	 * @return void
	 */
	public function testAMissingSourceIsRefused(): void {
		$this->expectException(CaseSystemRefusal::class);
		$this->transport([])->send(sourceId: 'eeeeeeee-0000-4000-8000-000000000009', method: 'GET', address: '/zaken');
	}//end testAMissingSourceIsRefused()
}//end class
