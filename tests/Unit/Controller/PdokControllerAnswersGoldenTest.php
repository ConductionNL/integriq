<?php

/**
 * The PDOK controller answers a missing query and an empty lookup the same way in every action.
 *
 * Pins the exact status and body of the 400 for a missing query (suggest,
 * lookup, free) and of the 503 / 404 / 200 a lookup and a reverse geocode
 * answer, so the shared answers can move without changing any of them.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Connectors\PdokConnector;
use OCA\Integriq\Controller\PdokController;
use OCA\Integriq\Service\ActionAuthServiceInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the PDOK controller's answers.
 */
final class PdokControllerAnswersGoldenTest extends TestCase {

	/**
	 * A controller over a connector answering every call with this payload.
	 *
	 * @param array<string,mixed> $payload What the connector answers.
	 *
	 * @return PdokController
	 */
	private function controller(array $payload): PdokController {
		$connector = $this->createMock(PdokConnector::class);
		foreach (['suggest', 'lookup', 'free', 'reverse'] as $method) {
			$connector->method($method)->willReturn($payload);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new PdokController(
			appName: 'integriq',
			request: $this->createMock(IRequest::class),
			pdokConnector: $connector,
			userSession: $session,
			actionAuth: $this->createMock(ActionAuthServiceInterface::class),
			l: $l10n
		);

	}//end controller()

	/**
	 * Assert a response's status and body.
	 *
	 * @param JSONResponse         $response The response.
	 * @param array{int, mixed}    $expected Status and body.
	 *
	 * @return void
	 */
	private function assertAnswer(JSONResponse $response, array $expected): void {
		$this->assertSame($expected, [$response->getStatus(), $response->getData()]);

	}//end assertAnswer()

	/**
	 * A blank query is refused with the missing-query envelope.
	 *
	 * @return void
	 */
	public function testABlankQueryIsRefused(): void {
		$expected = [Http::STATUS_BAD_REQUEST, ['error' => 'missing_query', 'message_key' => 'pdok.error.missing_query']];
		$controller = $this->controller(payload: ['numFound' => 1]);

		$this->assertAnswer($controller->suggestAction(q: ' '), $expected);
		$this->assertAnswer($controller->lookupAction(id: ''), $expected);
		$this->assertAnswer($controller->freeAction(q: "\t"), $expected);
		$this->assertAnswer(
			$controller->reverseAction(lat: null, lng: 4.9),
			[Http::STATUS_BAD_REQUEST, ['error' => 'missing_coordinates', 'message_key' => 'pdok.error.missing_coordinates']]
		);

	}//end testABlankQueryIsRefused()

	/**
	 * The connector payloads and the answer a lookup and a reverse give them.
	 *
	 * @return array<string, array{0: array<string,mixed>, 1: array{int, mixed}}>
	 */
	public static function payloads(): array {
		$found = ['docs' => [['id' => 'a']], 'numFound' => 1];
		$stale = ['docs' => [], 'numFound' => 0, 'stale' => true];

		return [
			'unavailable' => [['docs' => [], 'numFound' => 0], [Http::STATUS_SERVICE_UNAVAILABLE, ['error' => 'pdok_unavailable', 'message_key' => 'pdok.unavailable']]],
			'not stale false' => [['docs' => [], 'numFound' => 0, 'stale' => false], [Http::STATUS_SERVICE_UNAVAILABLE, ['error' => 'pdok_unavailable', 'message_key' => 'pdok.unavailable']]],
			'not found' => [$stale, [Http::STATUS_NOT_FOUND, ['error' => 'not_found', 'message_key' => 'pdok.error.not_found']]],
			'found' => [$found, [Http::STATUS_OK, $found]],
		];

	}//end payloads()

	/**
	 * A lookup and a reverse geocode answer the same way for the same payload.
	 *
	 * @param array<string,mixed> $payload  The connector payload.
	 * @param array{int, mixed}   $expected Status and body.
	 *
	 * @return void
	 */
	#[DataProvider('payloads')]
	public function testLookupAndReverseAnswer(array $payload, array $expected): void {
		$controller = $this->controller(payload: $payload);

		$this->assertAnswer($controller->lookupAction(id: 'adr-1'), $expected);
		$this->assertAnswer($controller->reverseAction(lat: 52.1, lng: 4.9), $expected);

	}//end testLookupAndReverseAnswer()

	/**
	 * Suggest and free answer the connector payload as is, even when empty.
	 *
	 * @return void
	 */
	public function testSuggestAndFreeAnswerThePayload(): void {
		$payload = ['docs' => [], 'numFound' => 0];
		$controller = $this->controller(payload: $payload);

		$this->assertAnswer($controller->suggestAction(q: 'Dam'), [Http::STATUS_OK, $payload]);
		$this->assertAnswer($controller->freeAction(q: 'Dam'), [Http::STATUS_OK, $payload]);

	}//end testSuggestAndFreeAnswerThePayload()
}//end class
