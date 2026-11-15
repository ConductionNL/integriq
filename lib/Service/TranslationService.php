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
 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a text to the first enabled translation source and returns its answer.
 *
 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
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
	 * @spec openspec/specs/translation-service/spec.md#requirement-a-sibling-app-can-translate-text-through-integriq-req-trl-001
	 * @spec openspec/specs/translation-service/spec.md#requirement-an-unconfigured-instance-says-so-req-trl-002
	 */
	public function translate(string $text, string $sourceLocale, string $targetLocale): array {
		$pair = new LanguagePair(sourceLocale: $sourceLocale, targetLocale: $targetLocale);

		if ($text === '' || $pair->from === $pair->to) {
			return [
				'success'  => true,
				'text'     => $text,
				'provider' => 'noop',
				'message'  => 'Nothing to translate.',
			];
		}

		$run = new TranslationRun(source: $this->findEnabledSource(), pair: $pair);
		$callLog = $this->post(run: $run, body: $this->requestBody(run: $run, text: $text));

		return $this->translated(run: $run, callLog: $callLog);
	}//end translate()

	/**
	 * The request body in the source's provider protocol: DeepL, else LibreTranslate.
	 *
	 * @param TranslationRun $run  The translation.
	 * @param string         $text The text to translate.
	 *
	 * @return array<string,mixed>
	 */
	private function requestBody(TranslationRun $run, string $text): array {
		$from = $run->pair->from;
		$to = $run->pair->to;
		if ($run->provider() === 'deepl') {
			return ['text' => [$text], 'source_lang' => strtoupper($from), 'target_lang' => strtoupper($to)];
		}

		return ['q' => $text, 'source' => $from, 'target' => $to, 'format' => 'text'];
	}//end requestBody()

	/**
	 * POST the body to the source's `/translate` endpoint.
	 *
	 * @param TranslationRun      $run  The translation.
	 * @param array<string,mixed> $body The request body.
	 *
	 * @return ObjectEntity The call log.
	 *
	 * @throws TranslationUnavailableException When the source cannot be reached.
	 */
	private function post(TranslationRun $run, array $body): ObjectEntity {
		try {
			return $this->callService->call(
				source: $run->source,
				endpoint: '/translate',
				method: 'POST',
				config: [
					'body'    => json_encode($body, JSON_THROW_ON_ERROR),
					'headers' => ['Content-Type' => 'application/json'],
				]
			);
		} catch (Throwable $e) {
			throw new TranslationUnavailableException(
				message: 'The translation source "' . $run->slug() . '" could not be reached: ' . $e->getMessage(),
				previous: $e
			);
		}
	}//end post()

	/**
	 * The answer for the translation the source gave.
	 *
	 * @param TranslationRun $run     The translation.
	 * @param ObjectEntity   $callLog The call log.
	 *
	 * @return array{success: bool, text: string, provider: string, message: string}
	 *
	 * @throws TranslationUnavailableException When the source answered without a translation.
	 */
	private function translated(TranslationRun $run, ObjectEntity $callLog): array {
		$translated = $this->readTranslation(callLog: $callLog, provider: $run->provider());
		if ($translated === null) {
			throw $this->noTranslation(run: $run, callLog: $callLog);
		}

		return [
			'success'  => true,
			'text'     => $translated,
			'provider' => $run->slug(),
			'message'  => 'Translated through ' . $run->name() . '.',
		];
	}//end translated()

	/**
	 * Log and build the refusal of an answer without a translation.
	 *
	 * @param TranslationRun $run     The translation.
	 * @param ObjectEntity   $callLog The call log.
	 *
	 * @return TranslationUnavailableException
	 */
	private function noTranslation(TranslationRun $run, ObjectEntity $callLog): TranslationUnavailableException {
		$status = (int)($callLog->getObject()['statusCode'] ?? 0);
		$this->logger->warning(
			'Integriq: translation source answered without a translation',
			['source' => $run->slug(), 'statusCode' => $status]
		);

		return new TranslationUnavailableException(
			message: 'The translation source "' . $run->slug() . '" answered without a translation (HTTP ' . $status . ').'
		);
	}//end noTranslation()

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
