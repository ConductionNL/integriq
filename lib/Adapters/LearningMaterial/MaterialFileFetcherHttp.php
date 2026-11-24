<?php

/**
 * Integriq learning material file fetcher (HTTP).
 *
 * Fetches a file over https only, with a timeout and a size limit.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\LearningMaterial;

use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Fetches a record's file from the network.
 *
 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
 */
final class MaterialFileFetcherHttp implements MaterialFileFetcher {
	public const TIMEOUT_SECONDS = 30;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The HTTP client factory.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function __construct(
		private readonly IClientService $clientService,
	) {
	}//end __construct()

	/**
	 * Fetch one file, following at most three https redirects.
	 *
	 * @param string $url    The file's https address.
	 * @param string $format The file's format as the record names it.
	 *
	 * @return array{content: string, mimeType: string} The bytes and their media type.
	 *
	 * @throws SourceUnavailableException When the file could not be fetched.
	 * @throws LearningMaterialRefusedException When the file is larger than MAX_BYTES.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public function fetch(string $url, string $format): array {
		$options = [
			'timeout'         => self::TIMEOUT_SECONDS,
			'http_errors'     => false,
			'allow_redirects' => ['max' => 3, 'protocols' => ['https']],
		];
		try {
			$response = $this->clientService->newClient()->get($url, $options);
		} catch (Throwable $error) {
			throw new SourceUnavailableException(source: LomRecordReader::SOURCE, lastSuccess: null);
		}

		if ($response->getStatusCode() !== 200) {
			throw new SourceUnavailableException(source: LomRecordReader::SOURCE, lastSuccess: null);
		}

		$declared = (int)$response->getHeader('Content-Length');
		$content  = (string)$response->getBody();
		if ($declared > self::MAX_BYTES || strlen($content) > self::MAX_BYTES) {
			throw self::tooLarge();
		}

		$mimeType = trim(explode(';', $response->getHeader('Content-Type'))[0]);
		if ($mimeType === '') {
			$mimeType = $format;
		}

		return ['content' => $content, 'mimeType' => $mimeType];
	}//end fetch()

	/**
	 * The refusal for a file over the limit.
	 *
	 * @return LearningMaterialRefusedException The refusal.
	 *
	 * @spec openspec/specs/edurep-source/spec.md#requirement-an-open-file-of-a-record-is-fetched-for-a-copy
	 */
	public static function tooLarge(): LearningMaterialRefusedException {
		return new LearningMaterialRefusedException(
			refusalCode: 'file-too-large',
			details: ['reason' => 'Dit bestand is groter dan 25 MB en kan alleen als koppeling worden toegevoegd.', 'maxBytes' => self::MAX_BYTES]
		);
	}//end tooLarge()
}//end class
