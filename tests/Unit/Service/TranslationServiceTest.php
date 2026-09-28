<?php

/**
 * Unit tests for TranslationService, the service decidiq resolves by name.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
 * @spec openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-an-unconfigured-instance-says-so-req-trl-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\TranslationUnavailableException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\TranslationService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Drives translate() through the real ConnectionStore and CallService method names.
 */
class TranslationServiceTest extends TestCase {

	/**
	 * Calls handed to CallService::call(), in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Build a source entity as the register holds it.
	 *
	 * @param string $slug     The source slug.
	 * @param string $provider The configuration.translationProvider value.
	 * @param boolean $enabled  Whether the source is enabled.
	 *
	 * @return ObjectEntity
	 */
	private function source(string $slug, string $provider, bool $enabled=true): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-' . $slug);
		$entity->setObject(
			[
				'slug'          => $slug,
				'name'          => ($provider === 'deepl') ? 'DeepL translation' : 'LibreTranslate',
				'type'          => 'api',
				'location'      => 'https://translate.example.org',
				'isEnabled'     => $enabled,
				'configuration' => ['translationProvider' => $provider],
			]
		);
		return $entity;
	}//end source()

	/**
	 * Build the service over the given sources and one canned answer body.
	 *
	 * @param array<string, ObjectEntity> $sources    Sources by slug.
	 * @param string                      $answerBody The body the provider answers.
	 * @param integer                     $status     The HTTP status the provider answers.
	 *
	 * @return TranslationService
	 */
	private function makeService(array $sources, string $answerBody='', int $status=200): TranslationService {
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findSourceBySlug')->willReturnCallback(
			fn (string $slug) => ($sources[$slug] ?? null)
		);

		$callService = $this->createMock(CallService::class);
		$callService->method('call')->willReturnCallback(
			function (...$args) use ($answerBody, $status) {
				// PHPUnit hands the arguments over positionally, in call()'s order.
				$this->calls[] = ['source' => $args[0], 'endpoint' => $args[1], 'method' => $args[2], 'config' => $args[3]];
				$log = new ObjectEntity();
				$log->setObject(['statusCode' => $status, 'response' => ['statusCode' => $status, 'body' => $answerBody]]);
				return $log;
			}
		);

		return new TranslationService($store, $callService, new NullLogger());
	}//end makeService()

	/**
	 * DeepL gets its own request shape and its answer comes back as the text.
	 *
	 * @return void
	 */
	public function testTranslatesThroughAnEnabledDeeplSource(): void {
		$service = $this->makeService(
			['deepl-translation' => $this->source('deepl-translation', 'deepl')],
			json_encode(['translations' => [['detected_source_language' => 'NL', 'text' => 'The meeting is open.']]])
		);

		$result = $service->translate('De vergadering is geopend.', 'nl', 'en');

		$this->assertTrue($result['success']);
		$this->assertSame('The meeting is open.', $result['text']);
		$this->assertSame('deepl-translation', $result['provider']);
		$this->assertCount(1, $this->calls);
		$this->assertSame('/translate', $this->calls[0]['endpoint']);
		$this->assertSame('POST', $this->calls[0]['method']);
		$this->assertSame(
			['text' => ['De vergadering is geopend.'], 'source_lang' => 'NL', 'target_lang' => 'EN'],
			json_decode($this->calls[0]['config']['body'], true)
		);
	}//end testTranslatesThroughAnEnabledDeeplSource()

	/**
	 * LibreTranslate is used when DeepL is not enabled.
	 *
	 * @return void
	 */
	public function testFallsBackToAnEnabledLibretranslateSource(): void {
		$service = $this->makeService(
			[
				'deepl-translation' => $this->source('deepl-translation', 'deepl', false),
				'libretranslate'    => $this->source('libretranslate', 'libretranslate'),
			],
			json_encode(['translatedText' => 'The meeting is open.'])
		);

		$result = $service->translate('De vergadering is geopend.', 'NL', 'en');

		$this->assertSame('The meeting is open.', $result['text']);
		$this->assertSame('libretranslate', $result['provider']);
		$this->assertSame(
			['q' => 'De vergadering is geopend.', 'source' => 'nl', 'target' => 'en', 'format' => 'text'],
			json_decode($this->calls[0]['config']['body'], true)
		);
	}//end testFallsBackToAnEnabledLibretranslateSource()

	/**
	 * Equal locales and empty text never reach the provider.
	 *
	 * @return void
	 */
	public function testEqualLocalesAndEmptyTextMakeNoCall(): void {
		$service = $this->makeService(['deepl-translation' => $this->source('deepl-translation', 'deepl')]);

		$this->assertSame('Hallo', $service->translate('Hallo', 'nl', 'nl')['text']);
		$this->assertSame('', $service->translate('', 'nl', 'en')['text']);
		$this->assertSame([], $this->calls);
	}//end testEqualLocalesAndEmptyTextMakeNoCall()

	/**
	 * No enabled source is an exception, not the original text as a success.
	 *
	 * @return void
	 */
	public function testNoEnabledSourceThrowsAndMakesNoCall(): void {
		$service = $this->makeService(['deepl-translation' => $this->source('deepl-translation', 'deepl', false)]);

		try {
			$service->translate('Goedemorgen', 'nl', 'en');
			$this->fail('translate() returned without an enabled translation source');
		} catch (TranslationUnavailableException $e) {
			$this->assertStringContainsString('No translation source is enabled', $e->getMessage());
		}

		$this->assertSame([], $this->calls);
	}//end testNoEnabledSourceThrowsAndMakesNoCall()

	/**
	 * A provider error or an answer without a translation is an exception too.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutATranslationThrows(): void {
		$service = $this->makeService(
			['deepl-translation' => $this->source('deepl-translation', 'deepl')],
			json_encode(['message' => 'Wrong endpoint']),
			403
		);

		$this->expectException(TranslationUnavailableException::class);
		$service->translate('Goedemorgen', 'nl', 'en');
	}//end testAnAnswerWithoutATranslationThrows()
	/**
	 * The class decidiq resolves by name exists there and autowires.
	 *
	 * decidiq LogTranslationAdapter::OPENCONNECTOR_SERVICES names
	 * 'Service\\TranslationService' under integriq's namespace, checks
	 * method_exists($delegate, 'translate') and passes three strings.
	 *
	 * @return void
	 */
	public function testTheClassDecidiqLooksUpExistsAndAutowires(): void {
		$this->assertTrue(class_exists('OCA\\Integriq\\Service\\TranslationService'));

		$method = new \ReflectionMethod(TranslationService::class, 'translate');
		$this->assertTrue($method->isPublic());
		$this->assertSame(['text', 'sourceLocale', 'targetLocale'], array_map(fn ($p) => $p->getName(), $method->getParameters()));

		// Every constructor dependency is a concrete class or the PSR logger, both of which Nextcloud's container resolves without a registration.
		foreach ((new \ReflectionMethod(TranslationService::class, '__construct'))->getParameters() as $parameter) {
			$type = (string)$parameter->getType();
			$this->assertTrue(
				$type === \Psr\Log\LoggerInterface::class || (class_exists($type) === true && (new \ReflectionClass($type))->isInstantiable() === true),
				$type . ' is not autowirable'
			);
		}
	}//end testTheClassDecidiqLooksUpExistsAndAutowires()
}//end class
