<?php

/**
 * Integriq OpenApiDocumentReader.
 *
 * Reads a vendor's OpenAPI or Swagger document from text or from a URL the
 * egress guard allows, without following redirects.
 *
 * @category Service
 * @package  OCA\Integriq\Service\OpenApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\OpenApi;

use InvalidArgumentException;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\Security\EgressGuardInterface;
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Reads an OpenAPI document given as text or as a URL.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
class OpenApiDocumentReader {

	/**
	 * The largest document a URL import reads, in bytes.
	 *
	 * @var integer
	 */
	public const MAX_DOCUMENT_BYTES = 10485760;

	/**
	 * Constructor.
	 *
	 * @param EgressGuardInterface      $egressGuard   Refuses a URL integriq may not call.
	 * @param IClientService            $clientService Fetches the document.
	 * @param OpenApiDocumentNormaliser $normaliser    Decodes JSON or YAML.
	 */
	public function __construct(
		private readonly EgressGuardInterface $egressGuard,
		private readonly IClientService $clientService,
		private readonly OpenApiDocumentNormaliser $normaliser,
	) {
	}//end __construct()

	/**
	 * Decode a document given as text, JSON or YAML.
	 *
	 * @param string $text The document.
	 *
	 * @return array<string,mixed> The decoded document.
	 *
	 * @throws InvalidArgumentException When it is neither, or not an object.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function decode(string $text): array {
		return $this->normaliser->decode(text: $text);
	}//end decode()

	/**
	 * Fetch a document from a URL, through the egress guard.
	 *
	 * Redirects are not followed, so a public URL cannot bounce the fetch onto an internal address.
	 *
	 * @param string $url The document URL.
	 *
	 * @return array<string,mixed> The decoded document.
	 *
	 * @throws EgressRefusedException When the URL is on an internal address or not https.
	 * @throws InvalidArgumentException When the fetch fails or the answer is not a document.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	public function fetch(string $url): array {
		$this->egressGuard->assertAllowed(url: $url);

		try {
			$response = $this->clientService->newClient()->get(
				$url,
				[
					'allow_redirects' => false,
					'timeout' => 30,
					'http_errors' => false,
					'headers' => ['Accept' => 'application/json, application/yaml, text/yaml'],
				]
			);
		} catch (Throwable $exception) {
			throw new InvalidArgumentException('The document could not be fetched: '.$exception->getMessage());
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new InvalidArgumentException('The document URL answered HTTP '.$status.'.');
		}

		$body = (string) $response->getBody();
		if (strlen($body) > self::MAX_DOCUMENT_BYTES) {
			throw new InvalidArgumentException('The document is larger than '.self::MAX_DOCUMENT_BYTES.' bytes.');
		}

		return $this->normaliser->decode(text: $body);
	}//end fetch()
}//end class
