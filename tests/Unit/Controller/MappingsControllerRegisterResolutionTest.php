<?php

/**
 * The default register is the one this instance actually carries.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\MappingsController;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\SourceMappingService;
use OCA\Integriq\Tests\Helpers\SaveCallShape;
use OCA\Integriq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Migrated and unmigrated instances, told apart.
 *
 * ## Why the migrated case is the only one that catches this
 *
 * Reinstating the pinned literal reddens the migrated-instance test below and
 * the static guard in {@see \OCA\Integriq\Tests\Unit\Support\RegisterSlugPinTest}.
 * It does NOT redden the unmigrated-instance test, because on an unmigrated
 * instance the pinned literal happens to be the right answer. That is why this
 * defect survived: every test anyone had written was, in effect, the unmigrated
 * case, and the one existing test of this endpoint
 * ({@see MappingsControllerSaveObjectTest}) names a register explicitly and so
 * never reaches the default at all.
 *
 * Watched failing, not assumed. With `register: $register` reverted to
 * `register: ($data['register'] ?? 'openconnector')` and the resolution branch
 * removed, four of ten tests reddened and they were the right four. The exact
 * measurement is recorded in
 * {@see testAMigratedInstanceIsWrittenWithItsCurrentSlug}.
 *
 * ## Why the write is refused rather than defaulted
 *
 * The absence has to be visible. Before the resolution this path wrote into
 * `openconnector` regardless, and OpenRegister answers a write to a register
 * that is not there without raising, so the endpoint returned 200 carrying an
 * object nobody could read back. An empty success and a real success are the
 * same response.
 */
class MappingsControllerRegisterResolutionTest extends TestCase {

	/**
	 * The register slug handed to saveObject, or null when nothing was written.
	 *
	 * @var string|null
	 */
	private ?string $writtenRegister = null;

	/**
	 * How many writes reached the ObjectService.
	 *
	 * @var int
	 */
	private int $writeCount = 0;

	/**
	 * An instance that has run this app's rename writes with the current slug.
	 *
	 * 🔴 THE ONE THAT CATCHES IT. Mutation measured before this file was
	 * committed, by deleting the resolution branch and putting
	 * `register: ($data['register'] ?? 'openconnector')` back. Ten tests ran,
	 * four reddened and they were the right four:
	 *
	 *  - this test: expected 'integriq', got 'openconnector';
	 *  - `testAnInstanceWithoutTheRegisterRefusesTheWrite`: 1 write where 0 were
	 *    allowed, and a 200 where the caller needed a 409;
	 *  - `testAnUnavailableResolverRefusesTheWrite`: same, by the same route;
	 *  - `RegisterSlugPinTest::testNoSourceFilePinsASupersededRegisterSlug`,
	 *    naming `lib/Controller/MappingsController.php:338`, the superseded slug
	 *    and the canonical one. Line 338 was checked against the mutated file
	 *    and is the pinned line itself, which is what
	 *    `FILE_IGNORE_NEW_LINES` buys over `FILE_SKIP_EMPTY_LINES`.
	 *
	 * Six stayed GREEN under that same mutation, and two of those matter:
	 * `testAnUnmigratedInstanceIsWrittenWithItsOldSlug`, because on an
	 * unmigrated instance the pinned literal happens to be right, and
	 * `MappingsControllerSaveObjectTest`, which is the whole of what this
	 * endpoint had before and names its register explicitly.
	 *
	 * @return void
	 */
	public function testAMigratedInstanceIsWrittenWithItsCurrentSlug(): void {
		$response = $this->saveWithoutNamingARegister(presentSlugs: ['integriq']);

		$this->assertSame('integriq', $this->writtenRegister);
		$this->assertSame(200, $response->getStatus());
	}//end testAMigratedInstanceIsWrittenWithItsCurrentSlug()

	/**
	 * An instance that has not run it writes with the old slug.
	 *
	 * This case passes both before and after the fix. It is here to prove the
	 * resolution did not simply swap one literal for another, which would have
	 * moved the breakage to the other half of the estate rather than removing
	 * it.
	 *
	 * @return void
	 */
	public function testAnUnmigratedInstanceIsWrittenWithItsOldSlug(): void {
		$response = $this->saveWithoutNamingARegister(presentSlugs: ['openconnector']);

		$this->assertSame('openconnector', $this->writtenRegister);
		$this->assertSame(200, $response->getStatus());
	}//end testAnUnmigratedInstanceIsWrittenWithItsOldSlug()

	/**
	 * An instance carrying the register under NEITHER slug writes nothing, and says so.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutTheRegisterRefusesTheWrite(): void {
		$response = $this->saveWithoutNamingARegister(presentSlugs: []);

		$this->assertSame(0, $this->writeCount, 'Nothing may be written when the register is absent.');
		$this->assertSame(409, $response->getStatus(), 'The absence must reach the caller as an error.');
		$this->assertArrayHasKey('error', $response->getData());
	}//end testAnInstanceWithoutTheRegisterRefusesTheWrite()

	/**
	 * A caller that names a register is obeyed, and the resolver is never asked.
	 *
	 * The register picker's options are live `openregister_registers` rows,
	 * fetched by the same controller's getObjects(), so a slug arriving in the
	 * request already carries this instance's own naming. Resolving it a second
	 * time would only add a way to be wrong: `zaken` is not a fleet register and
	 * has no alias list, so a resolution of it against an instance that has one
	 * is a question with no useful answer.
	 *
	 * The container double throws, which is what proves the resolver was not
	 * consulted: if this path asked for it, the request would come back 409.
	 *
	 * @return void
	 */
	public function testANamedRegisterIsUsedAsGiven(): void {
		$response = $this->save(
			params: [
				'object'   => ['title' => 'Mapped result'],
				'register' => 'zaken',
				'schema'   => 'zaak',
			],
			container: $this->throwingContainer()
		);

		$this->assertSame('zaken', $this->writtenRegister);
		$this->assertSame(200, $response->getStatus());
	}//end testANamedRegisterIsUsedAsGiven()

	/**
	 * A resolver that cannot be resolved refuses the write rather than guessing.
	 *
	 * Reachable on an OpenRegister old enough to predate the published contract:
	 * `getOpenRegisters()` answers, so the 412 above does not fire, and the
	 * container then has nothing to hand back. Treated as an absent register on
	 * purpose, because in both cases this instance cannot say where the write
	 * should go, and the alternative is the literal that caused all of this.
	 *
	 * @return void
	 */
	public function testAnUnavailableResolverRefusesTheWrite(): void {
		$response = $this->save(
			params: ['object' => ['title' => 'Mapped result']],
			container: $this->throwingContainer()
		);

		$this->assertSame(0, $this->writeCount, 'Nothing may be written when the slug cannot be determined.');
		$this->assertSame(409, $response->getStatus());
	}//end testAnUnavailableResolverRefusesTheWrite()

	/**
	 * Save a mapping result without naming a register, against a fixed instance state.
	 *
	 * @param list<string> $presentSlugs The register slugs this instance carries.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The endpoint's response.
	 */
	private function saveWithoutNamingARegister(array $presentSlugs): \OCP\AppFramework\Http\JSONResponse {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($presentSlugs): object {
				if ($id === RegisterSlugResolverInterface::class) {
					return new FakeSlugResolver($presentSlugs);
				}

				throw new RuntimeException('unexpected container lookup: ' . $id);
			}
		);

		return $this->save(params: ['object' => ['title' => 'Mapped result']], container: $container);
	}//end saveWithoutNamingARegister()

	/**
	 * A container that answers nothing, standing in for an OpenRegister without the contract.
	 *
	 * @return ContainerInterface The double.
	 */
	private function throwingContainer(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		return $container;
	}//end throwingContainer()

	/**
	 * Run saveObject() over the given request body and container.
	 *
	 * @param array<string, mixed> $params    The request parameters.
	 * @param ContainerInterface   $container The app container double.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse The endpoint's response.
	 */
	private function save(array $params, ContainerInterface $container): \OCP\AppFramework\Http\JSONResponse {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		// The REAL ObjectService type (its test stub), because
		// SourceMappingService::getOpenRegisters() declares that return type and
		// an anonymous double is rejected outright.
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(SaveCallShape::callback(
			function (...$args): ObjectEntity {
				$this->writeCount++;
				// Positional: named arguments reach a mock callback in
				// declaration order, and `register` is the second parameter of
				// ObjectService::saveObject.
				$this->writtenRegister = ($args[1] ?? null);

				return new ObjectEntity();
			}
		));

		$sourceMapping = $this->createMock(SourceMappingService::class);
		$sourceMapping->method('getOpenRegisters')->willReturn($objectService);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$controller = new MappingsController(
			'integriq',
			$request,
			$this->createMock(MappingService::class),
			$sourceMapping,
			$l,
			$this->createMock(IUserSession::class),
			$this->createMock(ActionAuthService::class),
			$this->createMock(LoggerInterface::class),
			$container
		);

		$response = $controller->saveObject();
		$this->assertNotNull($response, 'saveObject must answer');

		return $response;
	}//end save()
}//end class
