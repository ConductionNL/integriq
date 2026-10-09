<?php

/**
 * An endpoint's output mapping reshapes its answer
 * (gateway-endpoint-transform-and-plugins, REQ-GTP-001).
 *
 * `outputMapping` sat on the endpoint schema and its edit form, and no line of
 * the runtime read it, so a consumer got the register's shape whatever the
 * endpoint declared. `dispatchAfterBeforeRules()` now hands the body the
 * `after` rules produced to `applyOutputMapping()` before it answers. These
 * tests run that step with a real MappingService and a real mapping object,
 * for one object and for a list. (The whole tail cannot run in a unit test:
 * FlowToken reads a JSONResponse's headers, which needs a live server.)
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/endpoint-runtime/spec.md#requirement-an-endpoints-output-mapping-reshapes-its-answer-req-gtp-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IConfig;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Tests for applying an endpoint's output mapping.
 */
class EndpointServiceOutputMappingTest extends TestCase {

	private const ZAAK_ID = '5c1f6a3e-2b4d-4e8f-9a1c-7d6e5f4a3b2c';

	/**
	 * The integriq object service double; it hands out the register mapper.
	 *
	 * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;


	/**
	 * Build the service with a real MappingService whose register holds one
	 * mapping: `zaaknummer` from `identificatie`, `omschrijving` kept.
	 *
	 * @return EndpointService The service.
	 */
	private function service(): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);

		$mappingRegister = ObjectServiceMockBuilder::withFind(
			$this,
			[
				'name' => 'Zaak voor partner',
				'mapping' => ['zaaknummer' => 'identificatie', 'omschrijving' => 'omschrijving'],
				'passThrough' => false,
			],
			'map-1'
		);
		$mappingService = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
			$this->createMock(ObjectService::class),
			$mappingRegister,
			$this->createMock(SynchronizationContractService::class),
		);

		// The Schema entity is not loadable here; the code under test reads
		// only getProperties() from it.
		$schema = new class {
			/**
			 * No uri-typed properties.
			 *
			 * @return array
			 */
			public function getProperties(): array {
				return [];
			}
		};
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$consumerScope = $this->createMock(\OCA\Integriq\Service\ConsumerScopeService::class);
		$consumerScope->method('isAllowed')->willReturn(true);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnArgument(0);

		return new EndpointService(
			$this->objectService,
			$this->createMock(CallService::class),
			$logger,
			$urlGenerator,
			$mappingService,
			ObjectServiceMockBuilder::make($this),
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new \OCA\Integriq\Service\WebhookSignatureService($logger),
			$this->createMock(\OCA\Integriq\Service\RateLimit\InboundRateLimitService::class),
			new CompositeFanoutRule(ObjectServiceMockBuilder::make($this), $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(\OCA\Integriq\Service\ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$consumerScope,
			$schemaMapper,
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
		);
	}//end service()

	/**
	 * Run the output-mapping step on a body.
	 *
	 * @param array $endpointData The endpoint.
	 * @param mixed $body The body the after rules produced.
	 *
	 * @return mixed The body the consumer gets.
	 */
	private function answer(array $endpointData, mixed $body): mixed {
		$method = new ReflectionMethod(EndpointService::class, 'applyOutputMapping');
		$method->setAccessible(true);
		return $method->invoke($this->service(), $endpointData, $body);
	}//end answer()

	/**
	 * The endpoint the scenario names.
	 *
	 * @param string|null $outputMapping The output mapping id.
	 *
	 * @return array The endpoint.
	 */
	private function zakenEndpoint(?string $outputMapping): array {
		return [
			'name' => 'Zaken voor partner',
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'endpointArray' => ['zaken', '{{id}}'],
			'outputMapping' => $outputMapping,
		];
	}//end zakenEndpoint()

	/**
	 * A zaak as the register answers it.
	 *
	 * @param string $identificatie Its identificatie.
	 *
	 * @return array The zaak.
	 */
	private function zaak(string $identificatie): array {
		return ['id' => self::ZAAK_ID, 'identificatie' => $identificatie, 'omschrijving' => 'Kapvergunning'];
	}//end zaak()

	/**
	 * GIVEN an output mapping that renames identificatie to zaaknummer, WHEN a
	 * consumer calls for one zaak, THEN the answer carries zaaknummer only.
	 *
	 * @return void
	 */
	public function testOneZaakIsAnsweredInThePartnersShape(): void {
		$this->assertSame(
			['zaaknummer' => 'ZAAK-2026-0042', 'omschrijving' => 'Kapvergunning'],
			$this->answer($this->zakenEndpoint('map-1'), $this->zaak('ZAAK-2026-0042'))
		);
	}//end testOneZaakIsAnsweredInThePartnersShape()

	/**
	 * A list answer maps each item and keeps its pagination fields.
	 *
	 * @return void
	 */
	public function testAListMapsEachItemAndKeepsItsPagination(): void {
		$list = [
			'count' => 2,
			'next' => 'https://example.org/zaken?page=2',
			'results' => [$this->zaak('ZAAK-2026-0042'), $this->zaak('ZAAK-2026-0043')],
		];

		$this->assertSame(
			[
				'count' => 2,
				'next' => 'https://example.org/zaken?page=2',
				'results' => [
					['zaaknummer' => 'ZAAK-2026-0042', 'omschrijving' => 'Kapvergunning'],
					['zaaknummer' => 'ZAAK-2026-0043', 'omschrijving' => 'Kapvergunning'],
				],
			],
			$this->answer($this->zakenEndpoint('map-1'), $list)
		);
	}//end testAListMapsEachItemAndKeepsItsPagination()

	/**
	 * Without an output mapping the register's own shape goes out, as before,
	 * and an empty answer (a DELETE) is left alone.
	 *
	 * @return void
	 */
	public function testWithoutAnOutputMappingTheRegistersShapeGoesOut(): void {
		$this->assertSame($this->zaak('ZAAK-2026-0042'), $this->answer($this->zakenEndpoint(null), $this->zaak('ZAAK-2026-0042')));
		$this->assertSame($this->zaak('ZAAK-2026-0042'), $this->answer($this->zakenEndpoint(''), $this->zaak('ZAAK-2026-0042')));
		$this->assertNull($this->answer($this->zakenEndpoint('map-1'), null));
	}//end testWithoutAnOutputMappingTheRegistersShapeGoesOut()

	/**
	 * The step is called from the request tail, after the `after` rules and
	 * before the answer is built. Read from the caller's source because the
	 * tail itself cannot run without a live server (see the class docblock).
	 *
	 * @return void
	 */
	public function testTheRequestTailAppliesTheOutputMappingAfterTheAfterRules(): void {
		$tail = new ReflectionMethod(EndpointService::class, 'dispatchAfterBeforeRules');
		$lines = file((string)$tail->getFileName());
		$body = implode('', array_slice($lines, $tail->getStartLine() - 1, $tail->getEndLine() - $tail->getStartLine() + 1));

		$afterRules = strpos($body, "timing: 'after'");
		$mapping = strpos($body, '$this->applyOutputMapping(');
		$answer = strpos($body, 'return new JSONResponse(data: $answerBody');

		$this->assertNotFalse($afterRules);
		$this->assertNotFalse($mapping, 'dispatchAfterBeforeRules() must call applyOutputMapping().');
		$this->assertNotFalse($answer, 'The answer must be built from the mapped body.');
		$this->assertTrue($afterRules < $mapping && $mapping < $answer);
	}//end testTheRequestTailAppliesTheOutputMappingAfterTheAfterRules()
}//end class
