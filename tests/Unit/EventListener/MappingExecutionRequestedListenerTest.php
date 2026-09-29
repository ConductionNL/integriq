<?php

/**
 * Tests for MappingExecutionRequestedListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\MappingExecutionRequestedEvent;
use OCA\Integriq\EventListener\MappingExecutionRequestedListener;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Loader\ArrayLoader;

/**
 * A sibling runs an integriq mapping by slug, through the real MappingService
 * and the mapping integriq seeds (read from its register fragment, not copied).
 */
class MappingExecutionRequestedListenerTest extends TestCase {

	/**
	 * OpenRegister's object service; only the lookups are doubled.
	 *
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService $orObjectService;

	/**
	 * The listener, over a real MappingService.
	 *
	 * @var MappingExecutionRequestedListener
	 */
	private MappingExecutionRequestedListener $listener;

	/**
	 * The mappings OpenRegister holds, by slug.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $stored = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->stored = [
			'woo-index-publication' => self::seededWooMapping(),
			'lowercase-keys' => ['name' => 'Lowercase Keys', 'mapping' => ['out' => 'in'], 'passThrough' => false],
		];

		$this->orObjectService = $this->createMock(OrObjectService::class);
		$this->orObjectService->method('find')->willReturnCallback(
			function (...$args): ObjectEntity {
				$id = (string)($args['id'] ?? $args[0]);
				if (isset($this->stored[$id]) === false) {
					// What OpenRegister does for an identifier it cannot resolve.
					throw new DoesNotExistException('Object not found');
				}

				return $this->entity(data: $this->stored[$id]);
			}
		);
		$this->orObjectService->method('findAll')->willReturn(['results' => []]);

		$mappingService = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->orObjectService,
			$this->createMock(SynchronizationContractService::class),
		);

		$this->listener = new MappingExecutionRequestedListener(mappingService: $mappingService, logger: new NullLogger());

	}//end setUp()

	/**
	 * The mapping the fragment seeds, without its @self envelope.
	 *
	 * @return array<string,mixed>
	 */
	private static function seededWooMapping(): array {
		$fragment = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/mapping-woo-index-field-mapping.json'),
			true,
			flags: JSON_THROW_ON_ERROR
		);
		foreach ($fragment['components']['schemas']['mapping']['x-openregister-seed'] as $seed) {
			if (($seed['@self']['slug'] ?? '') === 'woo-index-publication') {
				unset($seed['@self']);
				return $seed;
			}
		}

		self::fail('The fragment seeds no woo-index-publication mapping.');

	}//end seededWooMapping()

	/**
	 * An OpenRegister object carrying the data.
	 *
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		return $entity;

	}//end entity()

	/**
	 * Dispatch a request and return the event.
	 *
	 * @param string $slug The mapping slug.
	 * @param array<string,mixed> $input The input.
	 * @param string $app The asking app.
	 *
	 * @return MappingExecutionRequestedEvent
	 */
	private function dispatch(string $slug, array $input, string $app = 'opencatalogi'): MappingExecutionRequestedEvent {
		$event = new MappingExecutionRequestedEvent(mappingSlug: $slug, input: $input, sourceApp: $app, correlationId: 'sitemap-1');
		$this->listener->handle($event);
		return $event;

	}//end dispatch()

	/**
	 * The seeded mapping is a valid mapping object and lets opencatalogi in.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-integriq-seeds-an-editable-woo-index-mapping-req-woom-003
	 */
	public function testTheSeededMappingIsValidAndCallableByOpencatalogi(): void {
		$seed = self::seededWooMapping();

		$this->assertSame(['opencatalogi'], $seed['callableBy']);
		$this->assertSame(['publisher', 'officieleTitel', 'informatiecategorie', 'soortHandeling'], array_keys($seed['mapping']));
		$this->assertSame([], RegisterSchemaValidator::errors('mapping', $seed));

	}//end testTheSeededMappingIsValidAndCallableByOpencatalogi()

	/**
	 * opencatalogi maps a publication onto the Woo-index fields.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function testOpencatalogiMapsAPublication(): void {
		$event = $this->dispatch(
			slug: 'woo-index-publication',
			input: [
				'title' => 'Besluit parkeerbeleid',
				'tooiIdentifier' => 'gm0363',
				'tooiCategorieUri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532',
				'soortHandeling' => 'vaststelling',
			]
		);

		$this->assertTrue($event->isHandled());
		$this->assertNull($event->getRefusal());
		$output = $event->getOutput();
		$this->assertSame('Besluit parkeerbeleid', $output['officieleTitel']);
		$this->assertSame('gm0363', $output['publisher']);
		$this->assertSame('https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532', $output['informatiecategorie']);
		$this->assertSame('vaststelling', $output['soortHandeling']);

	}//end testOpencatalogiMapsAPublication()

	/**
	 * The official title falls back to the name, and a missing handling stays empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-integriq-seeds-an-editable-woo-index-mapping-req-woom-003
	 */
	public function testTheTitleFallsBackToTheNameAndAMissingHandlingStaysEmpty(): void {
		$output = $this->dispatch(slug: 'woo-index-publication', input: ['name' => 'Parkeerbeleid 2026'])->getOutput();

		$this->assertSame('Parkeerbeleid 2026', $output['officieleTitel']);
		$this->assertEmpty($output['soortHandeling'] ?? null);
		// A missing value stays empty; it never comes back as the rule's own text.
		$this->assertEmpty($output['publisher'] ?? null);

	}//end testTheTitleFallsBackToTheNameAndAMissingHandlingStaysEmpty()

	/**
	 * An unknown slug is refused with not-found and no output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function testAnUnknownSlugIsRefused(): void {
		$event = $this->dispatch(slug: 'does-not-exist', input: []);

		$this->assertFalse($event->isHandled());
		$this->assertSame('not-found', $event->getRefusal()['code']);

	}//end testAnUnknownSlugIsRefused()

	/**
	 * A mapping without callableBy does not run for a sibling.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
	 */
	public function testAMappingWithoutCallableByIsNotCallable(): void {
		$event = $this->dispatch(slug: 'lowercase-keys', input: ['in' => 'x']);

		$this->assertFalse($event->isHandled());
		$this->assertSame('not-allowed', $event->getRefusal()['code']);

	}//end testAMappingWithoutCallableByIsNotCallable()

	/**
	 * An app the mapping does not list is refused, even for the Woo mapping.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-mapping-names-the-apps-allowed-to-run-it-by-event-req-woom-002
	 */
	public function testAnAppNotListedIsRefused(): void {
		$event = $this->dispatch(slug: 'woo-index-publication', input: ['title' => 'x'], app: 'dossiq');

		$this->assertSame('not-allowed', $event->getRefusal()['code']);

	}//end testAnAppNotListedIsRefused()

	/**
	 * When find() throws for an identifier, the reference fallback still runs.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-index-mapping/spec.md#requirement-a-sibling-app-runs-a-mapping-by-slug-through-a-typed-event-req-woom-001
	 */
	public function testTheIdentifierFallbackRunsWhenFindThrows(): void {
		$orObjectService = $this->createMock(OrObjectService::class);
		$orObjectService->method('find')->willThrowException(new DoesNotExistException('Object not found'));
		$orObjectService->method('findAll')->willReturnCallback(
			function (...$args): array {
				$filters = (($args['config'] ?? $args[0])['filters'] ?? []);
				if (($filters['reference'] ?? null) === 'woo-index-publication') {
					return ['results' => [$this->entity(data: self::seededWooMapping())]];
				}

				return ['results' => []];
			}
		);
		$listener = new MappingExecutionRequestedListener(
			mappingService: new MappingService(
				new ArrayLoader([]),
				$this->createMock(CallService::class),
				$this->createMock(FileService::class),
				$this->createMock(ObjectService::class),
				$orObjectService,
				$this->createMock(SynchronizationContractService::class),
			),
			logger: new NullLogger()
		);
		$event = new MappingExecutionRequestedEvent(mappingSlug: 'woo-index-publication', input: ['title' => 'T'], sourceApp: 'opencatalogi');

		$listener->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame('T', $event->getOutput()['officieleTitel']);

	}//end testTheIdentifierFallbackRunsWhenFindThrows()
}//end class
