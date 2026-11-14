<?php

/**
 * RouteDistanceService Unit Tests
 *
 * The road distance between two postcodes: both placed through PDOK, the
 * linked route planner asked, the one-way distance answered with one
 * decimal and the planner named, or a refusal with a code.
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

use OCA\Integriq\Adapters\Pdok\PdokGeocodingClient;
use OCA\Integriq\Event\RouteDistanceRequestedEvent;
use OCA\Integriq\EventListener\RouteDistanceRequestedListener;
use OCA\Integriq\Service\CallServiceInterface;
use OCA\Integriq\Service\ConnectionCallService;
use OCA\Integriq\Service\ConnectionStoreInterface;
use OCA\Integriq\Service\RouteDistanceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the route distance command.
 *
 * @covers \OCA\Integriq\Service\RouteDistanceService
 * @covers \OCA\Integriq\EventListener\RouteDistanceRequestedListener
 * @covers \OCA\Integriq\Event\RouteDistanceRequestedEvent
 * @uses   \OCA\Integriq\Service\ConnectionCallService
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-integriq-answers-the-road-distance-between-two-postcodes-req-cc-002
 */
class RouteDistanceServiceTest extends TestCase {

	private const ORS_ANSWER = __DIR__ . '/../../fixtures/connection-calls/openrouteservice-2611AB-3011AD.json';

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
	 * PDOK.
	 *
	 * @var PdokGeocodingClient&MockObject
	 */
	private $geocoder;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store       = $this->createMock(ConnectionStoreInterface::class);
		$this->callService = $this->createMock(CallServiceInterface::class);
		$this->geocoder    = $this->createMock(PdokGeocodingClient::class);
		$this->geocoder->method('find')->willReturnCallback(
			static fn (string $query, int $rows = 10): array => match ($query) {
				'postcode:2611AB' => [
					['postalCode' => '2611 AB', 'addressLocality' => 'Delft', 'location' => ['type' => 'Point', 'coordinates' => [4.358211, 52.011634]]],
				],
				'postcode:3011AD' => [
					['postalCode' => '3011AD', 'addressLocality' => 'Rotterdam', 'location' => ['type' => 'Point', 'coordinates' => [4.478923, 51.917522]]],
				],
				default => [],
			}
		);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return RouteDistanceService
	 */
	private function service(): RouteDistanceService {
		return new RouteDistanceService(
			new ConnectionCallService($this->store, $this->callService, new NullLogger()),
			$this->geocoder
		);
	}//end service()

	/**
	 * Humaniq's route planner connection, linked to a planner source.
	 *
	 * @param string              $slug          The planner source's slug.
	 * @param array<string,mixed> $configuration The source's configuration.
	 *
	 * @return ObjectEntity The linked source.
	 */
	private function linkPlanner(string $slug, array $configuration): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('src-' . $slug);
		$source->setObject(['slug' => $slug, 'configuration' => $configuration]);

		$this->store->method('findRows')->willReturn(
			[['uuid' => 'row-1', 'data' => ['app' => 'humaniq', 'key' => 'route-planner', 'title' => 'Route planner', 'source' => 'src-' . $slug]]]
		);
		$this->store->method('findSource')->with('src-' . $slug)->willReturn($source);

		return $source;
	}//end linkPlanner()

	/**
	 * A call log as the call engine returns it.
	 *
	 * @param int    $status The status.
	 * @param string $body   The raw body.
	 *
	 * @return ObjectEntity
	 */
	private static function callLog(int $status, string $body): ObjectEntity {
		$log = new ObjectEntity();
		$log->setObject(['statusCode' => $status, 'response' => ['body' => $body]]);
		return $log;
	}//end callLog()

	/**
	 * Delft to Rotterdam by car on OpenRouteService is 15.8 km one way.
	 *
	 * @return void
	 */
	public function testACommuteByCarIsAnsweredByOpenRouteService(): void {
		$source = $this->linkPlanner(slug: 'route-planner-openrouteservice', configuration: ['routeProvider' => 'openrouteservice']);
		$this->callService->expects($this->once())->method('call')
			->with(
				$source,
				'/v2/directions/driving-car',
				'GET',
				['query' => ['start' => '4.358211,52.011634', 'end' => '4.478923,51.917522']]
			)
			->willReturn(self::callLog(status: 200, body: (string)file_get_contents(self::ORS_ANSWER)));

		$event = new RouteDistanceRequestedEvent(sourceApp: 'humaniq', fromPostcode: '2611 AB', toPostcode: '3011ad', mode: 'car');
		(new RouteDistanceRequestedListener($this->service(), new NullLogger()))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getRefusal());
		$this->assertSame(
			['distanceKmOneWay' => 15.8, 'routeProvider' => 'openrouteservice', 'fromMunicipality' => 'Delft', 'toMunicipality' => 'Rotterdam'],
			$event->getResult()
		);
	}//end testACommuteByCarIsAnsweredByOpenRouteService()

	/**
	 * TomTom, recognised by its slug, is asked with lat,lon points and its own travel mode.
	 *
	 * @return void
	 */
	public function testAnEBikeRouteOnTomTomUsesItsBicycleMode(): void {
		$source = $this->linkPlanner(slug: 'route-planner-tomtom', configuration: []);
		$this->callService->expects($this->once())->method('call')
			->with(
				$source,
				'/1/calculateRoute/52.011634,4.358211:51.917522,4.478923/json',
				'GET',
				['query' => ['travelMode' => 'bicycle', 'routeType' => 'fastest']]
			)
			->willReturn(self::callLog(status: 200, body: '{"routes":[{"summary":{"lengthInMeters":17049}}]}'));

		$answer = $this->service()->distance(app: 'humaniq', fromPostcode: '2611AB', toPostcode: '3011 AD', mode: 'e-bike');

		$this->assertSame(17.0, $answer['distanceKmOneWay']);
		$this->assertSame('tomtom', $answer['routeProvider']);
	}//end testAnEBikeRouteOnTomTomUsesItsBicycleMode()

	/**
	 * A postcode that is not a Dutch postcode is refused by name, without asking PDOK.
	 *
	 * @return void
	 */
	public function testAPostcodeThatDoesNotExistIsUnknown(): void {
		$this->linkPlanner(slug: 'route-planner-openrouteservice', configuration: ['routeProvider' => 'openrouteservice']);
		$geocoder = $this->createMock(PdokGeocodingClient::class);
		$geocoder->expects($this->never())->method('find');
		$this->callService->expects($this->never())->method('call');

		$event = new RouteDistanceRequestedEvent(sourceApp: 'humaniq', fromPostcode: '0000 XX', toPostcode: '3011 AD');
		$service = new RouteDistanceService(new ConnectionCallService($this->store, $this->callService, new NullLogger()), $geocoder);
		(new RouteDistanceRequestedListener($service, new NullLogger()))->handle($event);

		$this->assertSame(['code' => 'unknown-postcode', 'reason' => 'The postcode 0000 XX cannot be placed.'], $event->getRefusal());
		$this->assertNull($event->getResult());
	}//end testAPostcodeThatDoesNotExistIsUnknown()

	/**
	 * A well-formed postcode PDOK has no address for is refused by name too.
	 *
	 * @return void
	 */
	public function testAPostcodePdokCannotPlaceIsUnknown(): void {
		$this->linkPlanner(slug: 'route-planner-openrouteservice', configuration: ['routeProvider' => 'openrouteservice']);
		$this->callService->expects($this->never())->method('call');

		$answer = $this->service()->distance(app: 'humaniq', fromPostcode: '2611 AB', toPostcode: '9999 ZZ');

		$this->assertSame(['refusal' => ['code' => 'unknown-postcode', 'reason' => 'The postcode 9999 ZZ cannot be placed.']], $answer);
	}//end testAPostcodePdokCannotPlaceIsUnknown()

	/**
	 * When PDOK does not answer, the refusal says so instead of calling the postcode unknown.
	 *
	 * @return void
	 */
	public function testAnUnansweredPdokIsNotAnUnknownPostcode(): void {
		$this->linkPlanner(slug: 'route-planner-openrouteservice', configuration: ['routeProvider' => 'openrouteservice']);
		$geocoder = $this->createMock(PdokGeocodingClient::class);
		$geocoder->method('find')->willReturn(null);

		$answer = (new RouteDistanceService(new ConnectionCallService($this->store, $this->callService, new NullLogger()), $geocoder))
			->distance(app: 'humaniq', fromPostcode: '2611 AB', toPostcode: '3011 AD');

		$this->assertSame('geocoder-unavailable', $answer['refusal']['code']);
	}//end testAnUnansweredPdokIsNotAnUnknownPostcode()

	/**
	 * A mode the planner has no profile for is refused before anything is placed or called.
	 *
	 * @return void
	 */
	public function testAModeWithoutAProfileIsUnsupported(): void {
		$this->linkPlanner(slug: 'route-planner-openrouteservice', configuration: ['routeProvider' => 'openrouteservice']);
		$this->callService->expects($this->never())->method('call');

		$answer = $this->service()->distance(app: 'humaniq', fromPostcode: '2611 AB', toPostcode: '3011 AD', mode: 'train');

		$this->assertSame(
			['refusal' => ['code' => 'unsupported-mode', 'reason' => 'The route planner has no profile for the mode "train".']],
			$answer
		);
	}//end testAModeWithoutAProfileIsUnsupported()

	/**
	 * A connection linked to something that is not a route planner is refused.
	 *
	 * @return void
	 */
	public function testASourceThatIsNoRoutePlannerIsRefused(): void {
		$this->linkPlanner(slug: 'rdw-open-data', configuration: []);
		$this->callService->expects($this->never())->method('call');

		$answer = $this->service()->distance(app: 'humaniq', fromPostcode: '2611 AB', toPostcode: '3011 AD');

		$this->assertSame('unsupported-provider', $answer['refusal']['code']);
	}//end testASourceThatIsNoRoutePlannerIsRefused()

	/**
	 * An unlinked route planner connection passes the not-linked refusal through.
	 *
	 * @return void
	 */
	public function testAnUnlinkedPlannerIsNotLinked(): void {
		$this->store->method('findRows')->willReturn(
			[['uuid' => 'row-1', 'data' => ['app' => 'humaniq', 'key' => 'route-planner', 'title' => 'Route planner']]]
		);

		$answer = $this->service()->distance(app: 'humaniq', fromPostcode: '2611 AB', toPostcode: '3011 AD');

		$this->assertSame('not-linked', $answer['refusal']['code']);
	}//end testAnUnlinkedPlannerIsNotLinked()

	/**
	 * A planner that answers an error or no route gives no distance.
	 *
	 * @return void
	 */
	public function testAPlannerErrorIsNoRoute(): void {
		$this->linkPlanner(slug: 'route-planner-openrouteservice', configuration: ['routeProvider' => 'openrouteservice']);
		$this->callService->method('call')->willReturn(self::callLog(status: 404, body: '{"error":{"code":2010}}'));

		$answer = $this->service()->distance(app: 'humaniq', fromPostcode: '2611 AB', toPostcode: '3011 AD');

		$this->assertSame(['refusal' => ['code' => 'no-route', 'reason' => 'The route planner gave no route (HTTP 404).']], $answer);
	}//end testAPlannerErrorIsNoRoute()

	/**
	 * Postcodes are normalised to `1234 AB`; anything else is not a postcode.
	 *
	 * @return void
	 */
	public function testPostcodesAreNormalised(): void {
		$this->assertSame('2611 AB', RouteDistanceService::normalisePostcode(postcode: ' 2611ab '));
		$this->assertSame('3011 AD', RouteDistanceService::normalisePostcode(postcode: '3011 AD'));
		$this->assertNull(RouteDistanceService::normalisePostcode(postcode: '0000 XX'));
		$this->assertNull(RouteDistanceService::normalisePostcode(postcode: '2611 A'));
		$this->assertNull(RouteDistanceService::normalisePostcode(postcode: ''));
	}//end testPostcodesAreNormalised()

	/**
	 * Another event is left alone.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->store->expects($this->never())->method('findRows');

		(new RouteDistanceRequestedListener($this->service(), new NullLogger()))->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
