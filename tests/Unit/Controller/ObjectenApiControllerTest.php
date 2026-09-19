<?php

/**
 * The wire contract of the Objecten and Objecttypen routes.
 *
 * The handlers already have their own suites, and this one deliberately does
 * not repeat them. What only the controller can answer is the ORDER: whether
 * the token verdict is reached before anything opens a register, and whether a
 * refusal comes back as the standard's status rather than as data. A handler
 * test cannot see that, because a handler is only ever called once the
 * controller has decided to call it.
 *
 * The read fixtures are wired through `readObjects` so a call that gets past
 * the guard is VISIBLE: without it, an endpoint that refused correctly and an
 * endpoint that answered nothing would both read as an empty body.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\ObjectenApiController;
use OCA\Integriq\Service\Objecten\ObjectEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjectenTokenService;
use OCA\Integriq\Service\Objecten\ObjectRecordTranslator;
use OCA\Integriq\Service\Objecten\ObjecttypeEndpointHandler;
use OCA\Integriq\Service\Objecten\ObjecttypeRegistry;
use OCA\Integriq\Service\Objecten\ObjectWriteHandler;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Covers the contract of every route in ObjectenApiController.
 */
class ObjectenApiControllerTest extends TestCase {

	/**
	 * What the fake write path was asked to do.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Two objecttypes, so a token scoped to one can be refused on the other.
	 *
	 * @return ObjecttypeRegistry The registry.
	 */
	private function registry(): ObjecttypeRegistry {
		$registry = new ObjecttypeRegistry();
		$registry->load(
			[
				['uuid' => 'aaa', 'name' => 'melding', 'register' => 'meldingen', 'schema' => 'melding', 'versions' => ['1']],
				['uuid' => 'bbb', 'name' => 'klacht', 'register' => 'klachten', 'schema' => 'klacht'],
			]
		);

		return $registry;
	}//end registry()

	/**
	 * One melding, so a read that gets through returns something recognisable.
	 *
	 * @param string $register The register.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(string $register): array {
		if ($register !== 'meldingen') {
			return [];
		}

		return [
			[
				'@self' => ['id' => 'm-1', 'created' => '2026-02-01T09:00:00+01:00'],
				'straatnaam' => 'Kerkstraat',
			],
		];
	}//end objects()

	/**
	 * A request answering one `type` parameter and one Authorization header.
	 *
	 * @param string $authorization The header the caller presents.
	 * @param string $type          The objecttype the caller names.
	 * @param array  $params        Any further parameters.
	 *
	 * @return IRequest The request double.
	 */
	private function request(string $authorization, string $type = '', array $params = []): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($authorization);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($type, $params): mixed {
				if ($key === 'type') {
					return $type;
				}

				return ($params[$key] ?? $default);
			}
		);
		$request->method('getServerHost')->willReturn('example.nl');

		return $request;
	}//end request()

	/**
	 * The controller over the fixtures, with a token that may read and write `aaa`.
	 *
	 * @param IRequest $request The request double.
	 *
	 * @return ObjectenApiController The controller.
	 */
	private function controller(IRequest $request): ObjectenApiController {
		$tokens = new ObjectenTokenService(
			objecttypes: $this->registry(),
			credentialRead: static fn (string $reference): ?string => ($reference === 'cred-1' ? 'the-key' : null)
		);
		$tokens->load(
			[
				[
					'credential' => 'cred-1',
					'principal' => 'leverancier',
					'permissions' => ['aaa' => ObjectenTokenService::READ_WRITE],
				],
			]
		);

		return new ObjectenApiController(
			request: $request,
			tokens: $tokens,
			types: new ObjecttypeEndpointHandler(
				objecttypes: $this->registry(),
				schemaRead: static fn (string $register, string $schema): array => ['type' => 'object', 'title' => $schema]
			),
			objects: new ObjectEndpointHandler(
				objecttypes: $this->registry(),
				translator: new ObjectRecordTranslator(),
				objectRead: fn (string $register, string $schema): array => $this->objects(register: $register)
			),
			writes: new ObjectWriteHandler(
				objecttypes: $this->registry(),
				translator: new ObjectRecordTranslator(),
				objectWrite: function (string $register, string $schema, ?string $uuid, array $data, string $principal): array {
					$this->written[] = ['uuid' => $uuid, 'principal' => $principal];

					return array_merge(['@self' => ['id' => ($uuid ?? 'new-1'), 'created' => '2026-03-01T09:00:00+01:00']], $data);
				},
				objectDelete: function (string $register, string $schema, string $uuid, string $principal): void {
					$this->written[] = ['deleted' => $uuid, 'principal' => $principal];
				},
				announce: static function (array $notification): void {
				}
			)
		);
	}//end controller()

	/**
	 * Every route answers 401 when no token is presented.
	 *
	 * The guard is the only thing standing between a #[PublicPage] route and an
	 * unauthenticated read of a register, so it is asserted per route rather
	 * than once: a route that forgot the call would pass a single-route test.
	 *
	 * @return void
	 */
	public function testEveryRouteRefusesAnUnauthenticatedCaller(): void {
		$request = $this->request(authorization: '', type: 'aaa', params: ['record' => ['data' => []]]);
		$controller = $this->controller(request: $request);

		$answers = [
			'objecttypes' => $controller->objecttypes(),
			'objecttype' => $controller->objecttype(uuid: 'aaa'),
			'objecttypeVersion' => $controller->objecttypeVersion(uuid: 'aaa', version: '1'),
			'objects' => $controller->objects(),
			'object' => $controller->object(uuid: 'm-1'),
			'search' => $controller->search(),
			'createObject' => $controller->createObject(),
			'replaceObject' => $controller->replaceObject(uuid: 'm-1'),
			'updateObject' => $controller->updateObject(uuid: 'm-1'),
			'deleteObject' => $controller->deleteObject(uuid: 'm-1'),
		];

		foreach ($answers as $route => $response) {
			$this->assertSame(401, $response->getStatus(), $route . ' answered an unauthenticated caller');
		}

		$this->assertSame([], $this->written, 'nothing was written for a caller who never authenticated');
	}//end testEveryRouteRefusesAnUnauthenticatedCaller()

	/**
	 * A token scoped to one objecttype is refused with 403 on another.
	 *
	 * The control is the same call on the objecttype the token does name: a
	 * refusal that also refuses the allowed type proves nothing.
	 *
	 * @return void
	 */
	public function testATokenScopedToOneObjecttypeIsRefusedOnAnother(): void {
		$refused = $this->controller(request: $this->request(authorization: 'Token the-key', type: 'bbb'))->objects();
		$this->assertSame(403, $refused->getStatus());

		$allowed = $this->controller(request: $this->request(authorization: 'Token the-key', type: 'aaa'))->objects();
		$this->assertSame(200, $allowed->getStatus(), 'the control: the objecttype the token names still works');
	}//end testATokenScopedToOneObjecttypeIsRefusedOnAnother()

	/**
	 * The read routes answer the standard's shape once the token is accepted.
	 *
	 * @return void
	 */
	public function testTheReadRoutesAnswerOnceTheTokenIsAccepted(): void {
		$controller = $this->controller(request: $this->request(authorization: 'Token the-key', type: 'aaa'));

		$this->assertSame(200, $controller->objecttypes()->getStatus());
		$this->assertSame(200, $controller->objecttype(uuid: 'aaa')->getStatus());
		$this->assertSame(200, $controller->objecttypeVersion(uuid: 'aaa', version: '1')->getStatus());
		$this->assertSame(200, $controller->object(uuid: 'm-1')->getStatus());

		$list = $controller->objects();
		$this->assertSame(200, $list->getStatus());
		$this->assertSame(1, $list->getData()['count']);
	}//end testTheReadRoutesAnswerOnceTheTokenIsAccepted()

	/**
	 * The search route answers a posted geometry.
	 *
	 * @return void
	 */
	public function testTheSearchRouteAnswersAPostedGeometry(): void {
		$request = $this->request(
			authorization: 'Token the-key',
			type: 'aaa',
			params: ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [5.1214, 52.0907], 'radius' => 500]]]
		);

		$this->assertSame(200, $this->controller(request: $request)->search()->getStatus());
	}//end testTheSearchRouteAnswersAPostedGeometry()

	/**
	 * A create is attributed to the token's principal, not to nobody.
	 *
	 * @return void
	 */
	public function testACreateIsAttributedToTheTokensPrincipal(): void {
		$request = $this->request(
			authorization: 'Token the-key',
			type: 'aaa',
			params: ['record' => ['data' => ['straatnaam' => 'Kerkstraat']]]
		);

		$this->assertSame(201, $this->controller(request: $request)->createObject()->getStatus());
		$this->assertSame('leverancier', $this->written[0]['principal']);
	}//end testACreateIsAttributedToTheTokensPrincipal()

	/**
	 * A replace and a partial update both land on the named object.
	 *
	 * @return void
	 */
	public function testAReplaceAndAPartialUpdateLandOnTheNamedObject(): void {
		$request = $this->request(
			authorization: 'Token the-key',
			type: 'aaa',
			params: ['record' => ['data' => ['straatnaam' => 'Biltstraat']]]
		);
		$controller = $this->controller(request: $request);

		$this->assertSame(200, $controller->replaceObject(uuid: 'm-1')->getStatus());
		$this->assertSame(200, $controller->updateObject(uuid: 'm-1')->getStatus());
		$this->assertSame(['m-1', 'm-1'], array_column($this->written, 'uuid'));
	}//end testAReplaceAndAPartialUpdateLandOnTheNamedObject()

	/**
	 * A delete removes the object, and answers 204.
	 *
	 * @return void
	 */
	public function testADeleteRemovesTheObject(): void {
		$controller = $this->controller(request: $this->request(authorization: 'Token the-key', type: 'aaa'));

		$this->assertSame(204, $controller->deleteObject(uuid: 'm-1')->getStatus());
		$this->assertSame('m-1', $this->written[0]['deleted']);
	}//end testADeleteRemovesTheObject()

	/**
	 * 🔴 A delete of a uuid the objecttype does not hold never reaches the delete.
	 *
	 * The token grants a permission PER OBJECTTYPE, so the uuid is the one value
	 * the caller can still substitute freely. Before this was resolved first, a
	 * uuid belonging to another type went straight to the write path, which is
	 * the shape gate 7 reads as an unscoped object lookup.
	 *
	 * @return void
	 */
	public function testADeleteOfAUuidThisObjecttypeDoesNotHoldIsRefused(): void {
		$controller = $this->controller(request: $this->request(authorization: 'Token the-key', type: 'aaa'));

		$this->assertSame(404, $controller->deleteObject(uuid: 'k-1')->getStatus());
		$this->assertSame([], $this->written, 'nothing was deleted for a uuid this objecttype does not hold');
	}//end testADeleteOfAUuidThisObjecttypeDoesNotHoldIsRefused()

	/**
	 * An unknown objecttype answers 404 rather than 403.
	 *
	 * The split is deliberate and the requirement names it: 403 for a type the
	 * token may not use, 404 for one that is not published here. Answering 403
	 * for both would make the endpoint an existence oracle for objecttypes.
	 *
	 * @return void
	 */
	public function testAnUnknownObjecttypeAnswers404(): void {
		$controller = $this->controller(request: $this->request(authorization: 'Token the-key', type: 'zzz'));

		$this->assertSame(404, $controller->objects()->getStatus());
	}//end testAnUnknownObjecttypeAnswers404()
}//end class
