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
 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
 * @spec openspec/specs/translation-service/spec.md#requirement-an-unconfigured-instance-says-so-req-trl-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\TranslationService;
use OCA\Integriq\Service\TranslationUnavailableException;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every answer, request and refusal of TranslationService::translate().
 */
class TranslationServiceGoldenTest extends TestCase {

	/**
	 * Calls made, as their config.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * Warnings logged.
	 *
	 * @var list<array{0:string,1:array<string,mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The service over one enabled source whose call answers or throws.
	 *
	 * @param array<string,mixed> $source The source object.
	 * @param string|\Exception   $answer The answer body, or the exception.
	 * @param int                 $status The status.
	 *
	 * @return TranslationService
	 */
	private function service(array $source, string|\Exception $answer, int $status = 200): TranslationService {
		$entity = new ObjectEntity();
		$entity->setObject($source + ['isEnabled' => true]);
		$store = $this->createMock(ConnectionStore::class);
		$store->method('findSourceBySlug')->willReturnCallback(fn (string $slug) => ($slug === 'libretranslate' ? $entity : null));
		$calls = $this->createMock(CallService::class);
		$calls->method('call')->willReturnCallback(
			function (...$args) use ($answer, $status): ObjectEntity {
				$this->calls[] = $args[3];
				if ($answer instanceof \Exception) {
					throw $answer;
				}

				$log = new ObjectEntity();
				$log->setObject(['statusCode' => $status, 'response' => ['body' => $answer]]);
				return $log;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new TranslationService($store, $calls, $logger);

	}//end service()

	/**
	 * The no-op answer, a translation with and without a name, and the request body.
	 *
	 * @return void
	 */
	public function testAnswersAreUnchanged(): void {
		$noop = $this->service(['slug' => 'libretranslate'], '{}')->translate('Hoi', ' NL ', 'nl');
		$this->assertSame(['success' => true, 'text' => 'Hoi', 'provider' => 'noop', 'message' => 'Nothing to translate.'], $noop);

		$named = $this->service(['slug' => 'libretranslate', 'name' => 'Libre'], '{"translatedText":"Hi"}')->translate('Hoi', 'NL', ' EN ');
		$this->assertSame(['success' => true, 'text' => 'Hi', 'provider' => 'libretranslate', 'message' => 'Translated through Libre.'], $named);
		$this->assertSame(['body' => '{"q":"Hoi","source":"nl","target":"en","format":"text"}', 'headers' => ['Content-Type' => 'application/json']], $this->calls[0]);

		$unnamed = $this->service(['slug' => 'libretranslate', 'configuration' => ['translationProvider' => 'deepl']], '{"translations":[{"text":"Hi"}]}')->translate('Hoi', 'nl', 'en');
		$this->assertSame(['success' => true, 'text' => 'Hi', 'provider' => 'libretranslate', 'message' => 'Translated through libretranslate.'], $unnamed);
		$this->assertSame('{"text":["Hoi"],"source_lang":"NL","target_lang":"EN"}', $this->calls[1]['body']);

	}//end testAnswersAreUnchanged()

	/**
	 * An unreachable source and an answer without a translation are refused with their messages.
	 *
	 * @return void
	 */
	public function testRefusalsAreUnchanged(): void {
		$messages = [];
		$cases = [
			[new RuntimeException('dns'), 200],
			['{"translatedText":""}', 200],
			['not json', 200],
			['{"translatedText":"Hi"}', 503],
		];
		foreach ($cases as [$answer, $status]) {
			try {
				$this->service(['slug' => 'libretranslate'], $answer, $status)->translate('Hoi', 'nl', 'en');
				$messages[] = 'translated';
			} catch (TranslationUnavailableException $exception) {
				$messages[] = $exception->getMessage() . ($exception->getPrevious() !== null ? ' [previous: ' . $exception->getPrevious()->getMessage() . ']' : '');
			}
		}

		$this->assertSame(
			[
				'The translation source "libretranslate" could not be reached: dns [previous: dns]',
				'The translation source "libretranslate" answered without a translation (HTTP 200).',
				'The translation source "libretranslate" answered without a translation (HTTP 200).',
				'The translation source "libretranslate" answered without a translation (HTTP 503).',
			],
			$messages
		);
		$this->assertSame(
			[
				['Integriq: translation source answered without a translation', ['source' => 'libretranslate', 'statusCode' => 200]],
				['Integriq: translation source answered without a translation', ['source' => 'libretranslate', 'statusCode' => 200]],
				['Integriq: translation source answered without a translation', ['source' => 'libretranslate', 'statusCode' => 503]],
			],
			$this->warnings
		);

	}//end testRefusalsAreUnchanged()
}//end class
