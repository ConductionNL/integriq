<?php

/**
 * Integriq Translation Service.
 *
 * Translates plain text through an outside translation service configured as
 * an integriq source (DeepL or LibreTranslate). Sibling apps resolve this class
 * by name: decidiq's LogTranslationAdapter looks up `Service\TranslationService`
 * under integriq's namespace and calls translate(), so the class name and the
 * return shape are a cross-app contract.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\TranslationUnavailableException;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a text to the first enabled translation source and returns its answer.
 *
 * @spec openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
 */
class TranslationService {

	/**
	 * Translation source slugs, in the order they are tried.
	 *
	 * @var string[]
	 */
	public const SOURCE_SLUGS = [
		'deepl-translation',
		'libretranslate',
	];

	/**
	 * Constructor.
	 *
	 * @param ConnectionStore $connectionStore Reads a source by slug in system context.
	 * @param CallService     $callService     Calls the source.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly ConnectionStore $connectionStore,
		private readonly CallService $callService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Translate a text from one language into another.
	 *
	 * @param string $text         The text to translate.
	 * @param string $sourceLocale ISO 639-1 code of the text's language.
	 * @param string $targetLocale ISO 639-1 code of the wanted language.
	 *
	 * @return array{success: bool, text: string, provider: string, message: string}
	 *
	 * @throws TranslationUnavailableException When no translation source is enabled or it answers without a translation.
	 *
	 * @spec openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
	 * @spec openspec/changes/connectors-translation-service/specs/translation-service/spec.md#requirement-an-unconfigured-instance-says-so-req-trl-002
	 */
	public function translate(string $text, string $sourceLocale, string $targetLocale): array {
		$from = strtolower(trim($sourceLocale));
		$to   = strtolower(trim($targetLocale));

		if ($text === '' || $from === $to) {
			return [
				'success'  => true,
				'text'     => $text,
				'provider' => 'noop',
				'message'  => 'Nothing to translate.',
			];
		}

		$source   = $this->findEnabledSource();
		$data     = $source->getObject();
		$slug     = (string)($data['slug'] ?? '');
		$provider = (string)($data['configuration']['translationProvider'] ?? $slug);

		if ($provider === 'deepl') {
			$body = ['text' => [$text], 'source_lang' => strtoupper($from), 'target_lang' => strtoupper($to)];
		} else {
			$body = ['q' => $text, 'source' => $from, 'target' => $to, 'format' => 'text'];
		}

		try {
			$callLog = $this->callService->call(
				source: $source,
				endpoint: '/translate',
				method: 'POST',
				config: [
					'body'    => json_encode($body, JSON_THROW_ON_ERROR),
					'headers' => ['Content-Type' => 'application/json'],
				]
			);
		} catch (Throwable $e) {
			throw new TranslationUnavailableException(
				message: 'The translation source "' . $slug . '" could not be reached: ' . $e->getMessage(),
				previous: $e
			);
		}

		$translated = $this->readTranslation(callLog: $callLog, provider: $provider);
		if ($translated === null) {
			$status = (int)($callLog->getObject()['statusCode'] ?? 0);
			$this->logger->warning(
				'Integriq: translation source answered without a translation',
				['source' => $slug, 'statusCode' => $status]
			);
			throw new TranslationUnavailableException(
				message: 'The translation source "' . $slug . '" answered without a translation (HTTP ' . $status . ').'
			);
		}

		return [
			'success'  => true,
			'text'     => $translated,
			'provider' => $slug,
			'message'  => 'Translated through ' . (string)($data['name'] ?? $slug) . '.',
		];
	}//end translate()

	/**
	 * Find the first enabled translation source.
	 *
	 * @return ObjectEntity
	 *
	 * @throws TranslationUnavailableException When none is enabled.
	 */
	private function findEnabledSource(): ObjectEntity {
		foreach (self::SOURCE_SLUGS as $slug) {
			$source = $this->connectionStore->findSourceBySlug(slug: $slug);
			if ($source !== null && ($source->getObject()['isEnabled'] ?? false) === true) {
				return $source;
			}
		}

		throw new TranslationUnavailableException(
			message: 'No translation source is enabled. Enable the "deepl-translation" or "libretranslate" source.'
		);
	}//end findEnabledSource()

	/**
	 * Read the translated text out of a call log.
	 *
	 * @param ObjectEntity $callLog  The call log CallService returned.
	 * @param string       $provider The provider shape, deepl or libretranslate.
	 *
	 * @return string|null The translation, or null when the answer holds none.
	 */
	private function readTranslation(ObjectEntity $callLog, string $provider): ?string {
		$data   = $callLog->getObject();
		$status = (int)($data['statusCode'] ?? 0);
		if ($status < 200 || $status >= 300) {
			return null;
		}

		$decoded = json_decode((string)($data['response']['body'] ?? ''), true);
		if (is_array($decoded) === false) {
			return null;
		}

		$translated = $decoded['translatedText'] ?? null;
		if ($provider === 'deepl') {
			$translated = $decoded['translations'][0]['text'] ?? null;
		}

		if (is_string($translated) === false || $translated === '') {
			return null;
		}

		return $translated;
	}//end readTranslation()
}//end class
