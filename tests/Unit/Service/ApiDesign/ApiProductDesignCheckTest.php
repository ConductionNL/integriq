<?php

/**
 * Tests for ApiProductDesignCheck.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ApiDesign
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ApiDesign;

use OCA\Integriq\Service\ApiDesign\ApiDesignFindingStore;
use OCA\Integriq\Service\ApiDesign\ApiDesignRuleServiceInterface;
use OCA\Integriq\Service\ApiDesign\ApiProductDesignCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A publish service that returns a fixed document and records its calls.
 */
class FixedDocumentPublisher {

	/**
	 * The calls: product uuid and server URL.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $document The document.
	 */
	public function __construct(public array $document) {

	}//end __construct()

	/**
	 * The product's document.
	 *
	 * @param ObjectEntity $product   The product.
	 * @param string       $serverUrl The server URL.
	 *
	 * @return array<string, mixed>
	 */
	public function productDocument(ObjectEntity $product, string $serverUrl): array {
		$this->calls[] = [(string)$product->getUuid(), $serverUrl];

		return $this->document;

	}//end productDocument()
}//end class

/**
 * Checking a product: document, rule sets, stored findings, order and the publish decision.
 */
#[CoversClass(ApiProductDesignCheck::class)]
#[UsesClass(ApiDesignFindingStore::class)]
class ApiProductDesignCheckTest extends TestCase {

	/**
	 * Objects double.
	 *
	 * @var InMemoryDesignObjectService
	 */
	private InMemoryDesignObjectService $objects;

	/**
	 * Rule service mock.
	 *
	 * @var ApiDesignRuleServiceInterface&MockObject
	 */
	private ApiDesignRuleServiceInterface&MockObject $rules;

	/**
	 * Publish service double.
	 *
	 * @var FixedDocumentPublisher
	 */
	private FixedDocumentPublisher $publisher;

	/**
	 * Set up a product and its doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new InMemoryDesignObjectService();
		$this->objects->put('api_product', 'p1', ['name' => 'Besluiten API', 'version' => '1.2.0', 'status' => 'deprecated']);
		$this->objects->put('api_design_ruleset', 'custom', ['name' => 'gemeente-voorbeeld', 'rules' => [['id' => 'g/1']]]);
		$this->rules = $this->createMock(ApiDesignRuleServiceInterface::class);
		$this->publisher = new FixedDocumentPublisher(document: ['openapi' => '3.0.0', 'paths' => ['/besluiten/' => []]]);

	}//end setUp()

	/**
	 * The check under test.
	 *
	 * @param object|null $publisher What the container answers for the publish service; null throws.
	 *
	 * @return ApiProductDesignCheck
	 */
	private function check(?object $publisher): ApiProductDesignCheck {
		$container = $this->createMock(ContainerInterface::class);
		if ($publisher === null) {
			$container->method('get')->willThrowException(new RuntimeException('not there'));
		} else {
			$container->method('get')->with(ApiProductDesignCheck::PUBLISH_SERVICE)->willReturn($publisher);
		}

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->with('/apps/integriq/api')->willReturn('https://nc.example.nl/apps/integriq/api');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-11-14T12:00:00+00:00'));

		return new ApiProductDesignCheck(
			rules: $this->rules,
			store: new ApiDesignFindingStore(objectService: $this->objects),
			objectService: $this->objects,
			container: $container,
			urlGenerator: $urls,
			time: $time
		);

	}//end check()

	/**
	 * The product's published document is checked against the built-in and stored rule sets;
	 * findings are stored and listed errors first.
	 *
	 * @return void
	 */
	public function testAProductIsCheckedAndItsFindingsStoredErrorsFirst(): void {
		$this->rules->method('builtInRuleSets')->willReturn([['name' => 'nl', 'rules' => []]]);
		$this->rules->expects(self::once())
			->method('check')
			->with(
				['openapi' => '3.0.0', 'paths' => ['/besluiten/' => []]],
				[['name' => 'nl', 'rules' => []], ['name' => 'gemeente-voorbeeld', 'rules' => [['id' => 'g/1']]]]
			)
			->willReturn([
				'findings' => [
					['ruleset' => 'g', 'ruleId' => 'g/1', 'severity' => 'warning', 'path' => '/paths/a', 'status' => 'open'],
					['ruleset' => 'nl', 'ruleId' => '/core/no-trailing-slash', 'severity' => 'error', 'path' => '/paths/~1besluiten~1', 'status' => 'open'],
				],
				'manual' => [['ruleId' => '/core/doc-language']],
			]);

		$result = $this->check(publisher: $this->publisher)->checkProduct(productId: 'p1');

		self::assertSame(['/core/no-trailing-slash', 'g/1'], array_column($result['findings'], 'ruleId'));
		self::assertSame([['ruleId' => '/core/doc-language']], $result['manual']);
		self::assertSame([['p1', 'https://nc.example.nl/apps/integriq/api']], $this->publisher->calls);
		$stored = array_values($this->objects->objects['api_design_finding']);
		self::assertCount(2, $stored);
		self::assertSame(['1.2.0', '1.2.0'], array_column($stored, 'productVersion'));
		self::assertSame('2026-11-14T12:00:00+00:00', $stored[0]['checkedAt']);

	}//end testAProductIsCheckedAndItsFindingsStoredErrorsFirst()

	/**
	 * The publish decision is the rule service's blocking subset of the fresh findings.
	 *
	 * @return void
	 */
	public function testBlockingFindingsComeFromAFreshCheck(): void {
		$open = ['ruleset' => 'nl', 'ruleId' => '/core/a', 'severity' => 'error', 'path' => '/p', 'status' => 'open'];
		$this->rules->method('builtInRuleSets')->willReturn([]);
		$this->rules->method('check')->willReturn(['findings' => [$open], 'manual' => []]);
		$this->rules->expects(self::once())
			->method('blockingFindings')
			->with(self::callback(static fn (array $findings): bool => count($findings) === 1 && $findings[0]['ruleId'] === '/core/a'))
			->willReturnArgument(0);

		$product = $this->objects->find(id: 'p1', schema: 'api_product');
		$blocking = $this->check(publisher: $this->publisher)->blockingFindings(product: $product);

		self::assertSame('/core/a', $blocking[0]['ruleId']);

	}//end testBlockingFindingsComeFromAFreshCheck()

	/**
	 * Without the publish service, or with one that cannot write a document, the check refuses.
	 *
	 * @return void
	 */
	public function testWithoutAPublishServiceTheCheckRefuses(): void {
		$this->rules->expects(self::never())->method('check');

		try {
			$this->check(publisher: null)->checkProduct(productId: 'p1');
			self::fail('A product was checked without a document.');
		} catch (RuntimeException $e) {
			self::assertStringContainsString('cannot be written', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->check(publisher: new \stdClass())->checkProduct(productId: 'p1');

	}//end testWithoutAPublishServiceTheCheckRefuses()

	/**
	 * An unknown product is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownProductIsRefused(): void {
		$this->expectExceptionMessage('There is no such API product.');

		$this->check(publisher: $this->publisher)->checkProduct(productId: 'nope');

	}//end testAnUnknownProductIsRefused()
}//end class
