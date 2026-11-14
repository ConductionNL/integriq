<?php

/**
 * ConnectionCallService Unit Tests
 *
 * A sibling app calls a connection it declared: integriq finds the source an
 * administrator linked, calls it through the call engine and answers the
 * status and body, or refuses with a code and never throws.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\ConnectionCallRequestedEvent;
use OCA\Integriq\EventListener\ConnectionCallRequestedListener;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\ConnectionCallService;
use OCA\Integriq\Service\ConnectionStoreInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for the connection call command.
 *
 * @covers \OCA\Integriq\Service\ConnectionCallService
 * @covers \OCA\Integriq\EventListener\ConnectionCallRequestedListener
 * @covers \OCA\Integriq\Event\ConnectionCallRequestedEvent
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */
class ConnectionCallServiceTest extends TestCase {

	private const RDW_ANSWER = __DIR__ . '/../../fixtures/connection-calls/rdw-vehicle-GB123X.json';

	/**
	 * The connection rows and sources.
	 *
	 * @var ConnectionStoreInterface&MockObject
	 */
	private $store;

	/**
	 * The call engine.
	 *
	 * @var CallServiceInterface&MockObject
	 */
	private $callService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store       = $this->createMock(ConnectionStoreInterface::class);
		$this->callService = $this->createMock(CallServiceInterface::class);
	}//end setUp()

	/**
	 * The listener over a real service.
	 *
	 * @return ConnectionCallRequestedListener
	 */
	private function listener(): ConnectionCallRequestedListener {
		return new ConnectionCallRequestedListener(
			new ConnectionCallService($this->store, $this->callService, new NullLogger()),
			new NullLogger()
		);
	}//end listener()

	/**
	 * Humaniq's declared vehicle connection, linked or not.
	 *
	 * @param string|null $sourceId The linked source's uuid.
	 *
	 * @return void
	 */
	private function declareVehicleConnection(?string $sourceId): void {
		$row = ['app' => 'humaniq', 'key' => 'rdw-voertuigen', 'title' => 'RDW vehicle register'];
		if ($sourceId !== null) {
			$row['source'] = $sourceId;
		}

		$this->store->method('findRows')->with('humaniq')->willReturn(
			[
				['uuid' => 'row-1', 'data' => ['app' => 'humaniq', 'key' => 'brp-personen', 'title' => 'BRP', 'source' => 'src-brp']],
				['uuid' => 'row-2', 'data' => $row],
			]
		);
	}//end declareVehicleConnection()

	/**
	 * A source entity with a slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return ObjectEntity
	 */
	private static function source(string $slug): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('src-' . $slug);
		$source->setObject(['slug' => $slug, 'location' => 'https://opendata.rdw.nl/resource']);
		return $source;
	}//end source()

	/**
	 * A call log as the call engine returns it.
	 *
	 * @param int    $status The status.
	 * @param string $body   The raw response body.
	 *
	 * @return ObjectEntity
	 */
	private static function callLog(int $status, string $body): ObjectEntity {
		$log = new ObjectEntity();
		$log->setObject(['statusCode' => $status, 'response' => ['statusCode' => $status, 'body' => $body]]);
		return $log;
	}//end callLog()

	/**
	 * Humaniq looks up a plate on the linked RDW source and gets the vehicle back.
	 *
	 * @return void
	 */
	public function testALinkedConnectionAnswersTheVehicleRecord(): void {
		$this->declareVehicleConnection(sourceId: 'src-rdw-open-data');
		$source = self::source(slug: 'rdw-open-data');
		$this->store->expects($this->once())->method('findSource')->with('src-rdw-open-data')->willReturn($source);
		$this->callService->expects($this->once())->method('call')
			->with($source, '/m9d7-ebf2.json', 'GET', ['query' => ['kenteken' => 'GB123X']])
			->willReturn(self::callLog(status: 200, body: (string)file_get_contents(self::RDW_ANSWER)));

		$event = new ConnectionCallRequestedEvent(
			sourceApp: 'humaniq',
			connectionKey: 'rdw-voertuigen',
			method: 'get',
			path: '/m9d7-ebf2.json',
			query: ['kenteken' => 'GB123X']
		);
		$this->listener()->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getRefusal());
		$result = $event->getResult();
		$this->assertSame(200, $result['status']);
		$this->assertSame('rdw-open-data', $result['sourceSlug']);
		$this->assertSame('VOLKSWAGEN', $result['body'][0]['merk']);
		$this->assertSame('20210415', $result['body'][0]['datum_eerste_toelating']);
		$this->assertSame('34250', $result['body'][0]['catalogusprijs']);
	}//end testALinkedConnectionAnswersTheVehicleRecord()

	/**
	 * A declared connection without a source is refused with its title, and nothing is called.
	 *
	 * @return void
	 */
	public function testAConnectionWithoutASourceIsNotLinked(): void {
		$this->declareVehicleConnection(sourceId: null);
		$this->store->expects($this->never())->method('findSource');
		$this->callService->expects($this->never())->method('call');

		$event = new ConnectionCallRequestedEvent(sourceApp: 'humaniq', connectionKey: 'rdw-voertuigen', path: '/m9d7-ebf2.json');
		$this->listener()->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getResult());
		$this->assertSame(
			['code' => 'not-linked', 'reason' => 'The connection "RDW vehicle register" is not linked to a source yet.'],
			$event->getRefusal()
		);
	}//end testAConnectionWithoutASourceIsNotLinked()

	/**
	 * A linked source that no longer exists counts as not linked.
	 *
	 * @return void
	 */
	public function testALinkedSourceThatIsGoneIsNotLinked(): void {
		$this->declareVehicleConnection(sourceId: 'src-deleted');
		$this->store->method('findSource')->willReturn(null);
		$this->callService->expects($this->never())->method('call');

		$answer = (new ConnectionCallService($this->store, $this->callService, new NullLogger()))
			->call(app: 'humaniq', connectionKey: 'rdw-voertuigen', path: '/x');

		$this->assertSame('not-linked', $answer['refusal']['code']);
	}//end testALinkedSourceThatIsGoneIsNotLinked()

	/**
	 * A key the app did not declare is refused as not declared.
	 *
	 * @return void
	 */
	public function testAnUndeclaredKeyIsNotDeclared(): void {
		$this->declareVehicleConnection(sourceId: 'src-rdw-open-data');
		$this->callService->expects($this->never())->method('call');

		$answer = (new ConnectionCallService($this->store, $this->callService, new NullLogger()))
			->call(app: 'humaniq', connectionKey: 'kvk', path: '/x');

		$this->assertSame(
			['refusal' => ['code' => 'not-declared', 'reason' => 'The app "humaniq" declares no connection "kvk".']],
			$answer
		);
	}//end testAnUndeclaredKeyIsNotDeclared()

	/**
	 * A path that climbs out of the source's location is refused before anything is read or called.
	 *
	 * @return void
	 */
	public function testAPathWithDotDotIsInvalidAndNothingIsCalled(): void {
		$this->store->expects($this->never())->method('findRows');
		$this->callService->expects($this->never())->method('call');

		$event = new ConnectionCallRequestedEvent(sourceApp: 'humaniq', connectionKey: 'rdw-voertuigen', path: '/resource/../admin');
		$this->listener()->handle($event);

		$this->assertSame('invalid', $event->getRefusal()['code']);
	}//end testAPathWithDotDotIsInvalidAndNothingIsCalled()

	/**
	 * Each way out of the location, and a method other than GET or POST, is invalid.
	 *
	 * @return void
	 */
	public function testRequestsThatLeaveTheLocationAreInvalid(): void {
		foreach (['https://evil.test/x', '//evil.test/x', 'relative', '/a?b=c', '/a#b', '/%2e%2e/x', '/a\\b', "/a\nb", '/user@host'] as $path) {
			$this->assertNotNull(ConnectionCallService::requestRefusal(method: 'GET', path: $path, query: []), $path);
		}

		$this->assertNotNull(ConnectionCallService::requestRefusal(method: 'DELETE', path: '/x', query: []));
		$this->assertNotNull(ConnectionCallService::requestRefusal(method: 'GET', path: '/x', query: ['a' => ['nested']]));
		$this->assertNotNull(ConnectionCallService::requestRefusal(method: 'GET', path: '/x', query: ['positional']));
		$this->assertNull(ConnectionCallService::requestRefusal(method: 'POST', path: '/m9d7-ebf2.json', query: ['kenteken' => 'GB123X', 'n' => 1]));
		$this->assertNull(ConnectionCallService::requestRefusal(method: 'GET', path: '', query: []));
		$this->assertNull(ConnectionCallService::requestRefusal(method: 'GET', path: '/1/calculateRoute/52.0,4.3:51.9,4.4/json', query: []));
	}//end testRequestsThatLeaveTheLocationAreInvalid()

	/**
	 * An array body on a POST is sent as JSON.
	 *
	 * @return void
	 */
	public function testAPostArrayBodyIsSentAsJson(): void {
		$this->declareVehicleConnection(sourceId: 'src-rdw-open-data');
		$source = self::source(slug: 'rdw-open-data');
		$this->store->method('findSource')->willReturn($source);
		$this->callService->expects($this->once())->method('call')
			->with(
				$source,
				'/search',
				'POST',
				['body' => '{"kenteken":"GB123X"}', 'headers' => ['Content-Type' => 'application/json']]
			)
			->willReturn(self::callLog(status: 201, body: 'accepted'));

		$answer = (new ConnectionCallService($this->store, $this->callService, new NullLogger()))
			->call(app: 'humaniq', connectionKey: 'rdw-voertuigen', method: 'POST', path: '/search', body: ['kenteken' => 'GB123X']);

		$this->assertSame(['status' => 201, 'body' => 'accepted', 'sourceSlug' => 'rdw-open-data'], $answer);
	}//end testAPostArrayBodyIsSentAsJson()

	/**
	 * A failing call engine is a refusal, never an exception for the caller.
	 *
	 * @return void
	 */
	public function testAFailedCallIsRefusedNotThrown(): void {
		$this->declareVehicleConnection(sourceId: 'src-rdw-open-data');
		$this->store->method('findSource')->willReturn(self::source(slug: 'rdw-open-data'));
		$this->callService->method('call')->willThrowException(new RuntimeException('connection refused'));

		$event = new ConnectionCallRequestedEvent(sourceApp: 'humaniq', connectionKey: 'rdw-voertuigen', path: '/m9d7-ebf2.json');
		$this->listener()->handle($event);

		$this->assertSame(
			['code' => 'call-failed', 'reason' => 'The call to the source "rdw-open-data" failed: connection refused'],
			$event->getRefusal()
		);
	}//end testAFailedCallIsRefusedNotThrown()

	/**
	 * A failure before the call is caught by the listener and still answered.
	 *
	 * @return void
	 */
	public function testTheListenerAnswersAnUnexpectedFailure(): void {
		$this->store->method('findRows')->willThrowException(new RuntimeException('register unavailable'));

		$event = new ConnectionCallRequestedEvent(sourceApp: 'humaniq', connectionKey: 'rdw-voertuigen');
		$this->listener()->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(['code' => 'call-failed', 'reason' => 'register unavailable'], $event->getRefusal());
	}//end testTheListenerAnswersAnUnexpectedFailure()

	/**
	 * Another event is left alone.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->store->expects($this->never())->method('findRows');

		$this->listener()->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
