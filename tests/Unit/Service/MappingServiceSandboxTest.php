<?php

/**
 * Unit tests for the sandbox around the mapping Twig environment.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\Integriq\Twig\MappingExtension;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * A mapping template author must not reach PHP: the environment is sandboxed,
 * and every template the app ships still compiles under the policy.
 */
final class MappingServiceSandboxTest extends TestCase {

	/**
	 * Build the service under test with inert collaborators.
	 *
	 * @return MappingService
	 */
	private function service(): MappingService {
		return new MappingService(
			loader: new ArrayLoader(),
			callService: $this->createMock(originalClassName: CallService::class),
			fileService: $this->createMock(originalClassName: FileService::class),
			objectService: $this->createMock(originalClassName: ObjectService::class),
			orObjectService: $this->createMock(originalClassName: OrObjectService::class),
			synchronizationContractService: $this->createMock(originalClassName: SynchronizationContractService::class),
		);
	}//end service()

	/**
	 * Templates that reach PHP callables or objects.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function hostileTemplates(): array {
		return [
			'map-system' => ["{{ ['id']|map('system')|join }}"],
			'map-getenv' => ["{{ ['DB_PASSWORD']|map('getenv')|join }}"],
			'filter-callable' => ["{{ ['id']|filter('system')|join }}"],
			'reduce-callable' => ["{{ ['id']|reduce('system') }}"],
			'sort-callable' => ["{{ ['id', 'x']|sort('system')|join }}"],
			'constant-function' => ["{{ constant('PHP_VERSION') }}"],
			'include-tag' => ["{% include 'x' %}"],
			'template-from-string' => ["{{ include(template_from_string('x')) }}"],
		];
	}//end hostileTemplates()

	/**
	 * A hostile template is refused, through executeMapping and through renderTemplateString.
	 *
	 * @param string $template The template.
	 *
	 * @return void
	 */
	#[DataProvider('hostileTemplates')]
	public function testHostileTemplateIsRefused(string $template): void {
		$service = $this->service();

		try {
			$output = $service->executeMapping(
				mapping: ['name' => 'hostile', 'mapping' => ['x' => $template], 'passThrough' => false, 'unset' => [], 'cast' => []],
				input: []
			);
			$this->fail(message: 'executeMapping rendered a hostile template: ' . json_encode(value: $output));
		} catch (\Throwable $exception) {
			$this->assertStringNotContainsString(needle: 'uid=', haystack: $exception->getMessage());
		}

		$this->expectException(exception: \Throwable::class);
		$service->renderTemplateString(template: $template, context: []);
	}//end testHostileTemplateIsRefused()

	/**
	 * Ordinary templates keep rendering.
	 *
	 * @return void
	 */
	public function testOrdinaryTemplatesStillRender(): void {
		$output = $this->service()->executeMapping(
			mapping: [
				'name' => 'ordinary',
				'mapping' => [
					'upper' => '{{ naam|upper }}',
					'sum' => '{{ leeftijd + 1 }}',
					'list' => '{% for i in lijst %}{{ i }}{% endfor %}',
					'arrow' => '{{ lijst|join(",") }}',
					'fallback' => "{{ missing|default('none') }}",
					'encoded' => '{{ naam|b64enc }}',
				],
				'passThrough' => false,
				'unset' => [],
				'cast' => [],
			],
			input: ['naam' => 'Jan', 'leeftijd' => '41', 'lijst' => [1, 2]]
		);

		$this->assertSame(
			expected: ['upper' => 'JAN', 'sum' => '42', 'list' => '12', 'arrow' => '1,2', 'fallback' => 'none', 'encoded' => base64_encode(string: 'Jan')],
			actual: $output
		);
	}//end testOrdinaryTemplatesStillRender()

	/**
	 * Api-f3: the arrow filters are allowed, and the sandbox still refuses a
	 * string callable as their arrow (`map('system')` is in hostileTemplates).
	 *
	 * @return void
	 */
	public function testClosureArrowFiltersRender(): void {
		$output = $this->service()->executeMapping(
			mapping: [
				'name' => 'arrows',
				'mapping' => [
					'map' => "{{ [1, 2]|map(x => x * 2)|join(',') }}",
					'filter' => "{{ [1, 2, 3]|filter(x => x > 1)|join(',') }}",
					'reduce' => '{{ [1, 2, 3]|reduce((carry, x) => carry + x) }}',
					'sort' => "{{ [3, 1, 2]|sort((a, b) => a <=> b)|join(',') }}",
				],
				'passThrough' => false,
				'unset' => [],
				'cast' => [],
			],
			input: []
		);

		$this->assertSame(
			expected: ['map' => '2,4', 'filter' => '2,3', 'reduce' => '6', 'sort' => '1,2,3'],
			actual: $output
		);

		$this->expectException(exception: \Twig\Error\RuntimeError::class);
		$this->service()->renderTemplateString(template: "{{ ['id']|map('system')|join }}", context: []);
	}//end testClosureArrowFiltersRender()

	/**
	 * Every shipped mapping template that compiles without the sandbox also compiles with it.
	 *
	 * @return void
	 */
	public function testShippedMappingTemplatesCompileUnderSandbox(): void {
		$templates = $this->shippedTemplates();
		$this->assertNotEmpty(actual: $templates, message: 'no shipped mapping templates found');

		$twig = new ReflectionProperty(MappingService::class, 'twig');
		$twig->setAccessible(true);
		$sandboxed = $twig->getValue($this->service());

		$plain = new Environment(loader: new ArrayLoader());
		$plain->addExtension(extension: new MappingExtension());

		$refused = [];
		foreach ($templates as $where => $template) {
			try {
				$plain->createTemplate(template: $template);
			} catch (\Throwable) {
				// Already broken without the sandbox; not this policy's concern.
				continue;
			}

			try {
				$sandboxed->createTemplate(template: $template);
			} catch (\Throwable $exception) {
				$refused[] = $where . ': ' . $exception->getMessage();
			}
		}

		$this->assertSame(expected: [], actual: $refused);
	}//end testShippedMappingTemplatesCompileUnderSandbox()

	/**
	 * Collect the template strings of every mapping the app ships.
	 *
	 * @return array<string, string> Location => template.
	 */
	private function shippedTemplates(): array {
		$root = dirname(__DIR__, 3);
		$mappings = [];

		$registerFiles = array_merge(
			[$root . '/lib/Settings/integriq_register.json'],
			glob($root . '/lib/Settings/register.d/*.json')
		);
		foreach ($registerFiles as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			foreach (($data['components']['objects'] ?? []) as $index => $object) {
				if (($object['@self']['schema'] ?? null) === 'mapping') {
					$mappings[basename(path: $file) . '#' . $index] = ($object['mapping'] ?? []);
				}
			}
		}

		foreach (glob($root . '/lib/*mapping-presets.seed.json') as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			foreach (($data['presets'] ?? []) as $index => $preset) {
				$mappings[basename(path: $file) . '#' . $index] = $preset;
			}
		}

		foreach (glob($root . '/configurations/*/mappings/*.json') as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$mappings[basename(path: $file)] = ($data['mapping'] ?? []);
		}

		$templates = [];
		array_walk_recursive(
			$mappings,
			static function (mixed $value, mixed $key) use (&$templates): void {
				if (is_string($value) === true && (str_contains($value, '{{') === true || str_contains($value, '{%') === true)) {
					$templates[] = $value;
				}
			}
		);

		return array_combine(keys: array_map(callback: 'strval', array: array_keys($templates)), values: $templates);
	}//end shippedTemplates()
}//end class
