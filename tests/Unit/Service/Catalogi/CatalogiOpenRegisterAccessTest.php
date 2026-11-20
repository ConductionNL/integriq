<?php

/**
 * CatalogiOpenRegisterAccess and CatalogiWiring unit tests: the production seams.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Catalogi
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
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Catalogi;

use OCA\Integriq\Service\Catalogi\CatalogiBinding;
use OCA\Integriq\Service\Catalogi\CatalogiGate;
use OCA\Integriq\Service\Catalogi\CatalogiOpenRegisterAccess;
use OCA\Integriq\Service\Catalogi\CatalogiTokenService;
use OCA\Integriq\Service\Catalogi\CatalogiWiring;
use OCA\Integriq\Service\Catalogi\InformatieobjecttypeEndpointHandler;
use OCA\Integriq\Service\Catalogi\WooStarterSet;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * What the facade asks of OpenRegister, and that every service is built with it.
 */
#[CoversClass(CatalogiOpenRegisterAccess::class)]
#[CoversClass(CatalogiWiring::class)]
#[UsesClass(CatalogiBinding::class)]
#[UsesClass(CatalogiGate::class)]
#[UsesClass(CatalogiTokenService::class)]
#[UsesClass(InformatieobjecttypeEndpointHandler::class)]
#[UsesClass(WooStarterSet::class)]
class CatalogiOpenRegisterAccessTest extends TestCase {

	/**
	 * The OpenRegister doubles, by class name.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Stored objects, by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $objects = [];

	/**
	 * Calls the doubles saw.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Schemas that resolve.
	 *
	 * @var array<int, string>
	 */
	public array $schemas = ['documentType'];

	/**
	 * Concepts by scheme uri, or null for a missing scheme.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $concepts = null;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$test = $this;
		$this->services = [
			CatalogiOpenRegisterAccess::OBJECT_SERVICE => new class($test) {
				public function __construct(private readonly CatalogiOpenRegisterAccessTest $test) {}
				public function findAll(array $config, bool $rbac = true, bool $multi = true): array {
					$this->test->calls[] = ['findAll', $config, $rbac, $multi];
					return ['results' => ($this->test->objects[$config['filters']['schema']] ?? [])];
				}
				public function saveObject(array $object, ?array $extend = [], mixed $register = null, mixed $schema = null): void {
					$this->test->calls[] = ['saveObject', $object, $register, $schema];
				}
			},
			CatalogiOpenRegisterAccess::SCHEMA_MAPPER => new class($test) {
				public function __construct(private readonly CatalogiOpenRegisterAccessTest $test) {}
				public function find(string $id, array $extend = [], bool $rbac = true, bool $multi = true): object {
					if (in_array($id, $this->test->schemas, true) === false) {
						throw new RuntimeException('DoesNotExistException');
					}
					return new \stdClass();
				}
			},
			CatalogiOpenRegisterAccess::CONCEPTS => new class($test) {
				public function __construct(private readonly CatalogiOpenRegisterAccessTest $test) {}
				public function scheme(string $uri): ?array {
					return ($this->test->concepts === null) ? null : ['uri' => $uri];
				}
				public function conceptsOf(string $uri): array {
					return (array)$this->test->concepts;
				}
			},
		];
	}//end setUp()

	/**
	 * The access over the doubles.
	 *
	 * @return CatalogiOpenRegisterAccess
	 */
	private function access(): CatalogiOpenRegisterAccess {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $name): object => $this->services[$name]);

		return new CatalogiOpenRegisterAccess(container: $container, logger: new NullLogger());
	}//end access()

	/**
	 * The binding is read from integriq's register in the system context, and
	 * only a binding whose schema resolves counts.
	 *
	 * @return void
	 */
	public function testTheBindingResolvesOnlyWithItsSchema(): void {
		self::assertNull($this->access()->binding(), 'no binding');

		$this->objects['catalogi_binding'] = [['publishedUuid' => 'cat-1', 'register' => 'documents', 'schema' => 'documentType']];
		$binding = $this->access()->binding();
		self::assertSame('cat-1', $binding?->uuid);
		self::assertSame(['findAll', ['filters' => ['register' => 'integriq', 'schema' => 'catalogi_binding'], 'limit' => 5000], false, false], $this->calls[1]);

		$this->schemas = [];
		self::assertNull($this->access()->binding(), 'a schema that does not resolve');
	}//end testTheBindingResolvesOnlyWithItsSchema()

	/**
	 * Types are read from, and created in, the bound register and schema.
	 *
	 * @return void
	 */
	public function testTypesAreReadAndCreatedInTheBoundSchema(): void {
		$binding = CatalogiBinding::fromObject(row: ['publishedUuid' => 'cat-1', 'register' => 'documents', 'schema' => 'documentType']);
		$this->objects['documentType'] = [['id' => 'type-1', 'name' => 'Besluit']];

		self::assertSame([['id' => 'type-1', 'name' => 'Besluit']], $this->access()->types(binding: $binding));
		$this->access()->createType(binding: $binding, stored: ['name' => 'Notulen']);
		self::assertSame(['saveObject', ['name' => 'Notulen'], 'documents', 'documentType'], $this->calls[1]);
	}//end testTypesAreReadAndCreatedInTheBoundSchema()

	/**
	 * The Woo categories come from the TOOI scheme; a missing or empty scheme is null.
	 *
	 * @return void
	 */
	public function testWooCategoriesComeFromTheConceptRegister(): void {
		self::assertNull($this->access()->wooCategories(), 'scheme missing');

		$this->concepts = [];
		self::assertNull($this->access()->wooCategories(), 'scheme without concepts');

		$this->concepts = ['https://x/c_1' => ['prefLabel' => 'besluiten'], 'https://x/c_2' => ['prefLabel' => ' '], 'https://x/c_3' => 'junk'];
		self::assertSame([['uri' => 'https://x/c_1', 'label' => 'besluiten']], $this->access()->wooCategories());

		$this->services[CatalogiOpenRegisterAccess::CONCEPTS] = new \stdClass();
		self::assertNull($this->access()->wooCategories(), 'a concept register without the method');
	}//end testWooCategoriesComeFromTheConceptRegister()

	/**
	 * Every facade service is registered and built with its seams on the access.
	 *
	 * @return void
	 */
	public function testEveryHandlerIsBuiltWithItsSeams(): void {
		$factories = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerService')->willReturnCallback(
			static function (string $name, callable $factory) use (&$factories): void {
				$factories[$name] = $factory;
			}
		);
		CatalogiWiring::register(context: $context);
		self::assertSame([CatalogiGate::class, InformatieobjecttypeEndpointHandler::class, WooStarterSet::class], array_keys($factories));

		$this->objects['catalogi_binding'] = [['publishedUuid' => 'cat-1', 'register' => 'documents', 'schema' => 'documentType']];
		$this->objects['documentType'] = [['id' => 'own', 'category' => 'besluiten']];
		$this->concepts = ['https://x/c_1' => ['prefLabel' => 'besluiten'], 'https://x/c_2' => ['prefLabel' => 'convenanten']];
		$access = $this->access();
		$tokens = $this->createMock(CatalogiTokenService::class);
		$tokens->method('verdict')->willReturn(['status' => 200, 'concepts' => false]);

		$built = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use (&$built, $factories, $access, $tokens, &$container): object {
				return match ($name) {
					CatalogiOpenRegisterAccess::class => $access,
					CatalogiTokenService::class => $tokens,
					default => ($built[$name] ??= $factories[$name]($container)),
				};
			}
		);

		$types = $container->get(InformatieobjecttypeEndpointHandler::class)->index(authorization: 'Bearer x', baseUrl: 'https://h/v1', query: []);
		self::assertSame(['own'], array_column($types['body']['results'], 'uuid'), 'the handler reads through the access');

		self::assertSame(['created' => 1, 'skipped' => 1], $container->get(WooStarterSet::class)->run()['body']);
		self::assertSame('saveObject', end($this->calls)[0]);
	}//end testEveryHandlerIsBuiltWithItsSeams()

}//end class
