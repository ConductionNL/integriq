<?php

/**
 * Integriq OpenApiImportController.
 *
 * Previews and imports a vendor's OpenAPI or Swagger document as a source and
 * endpoints.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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

namespace OCA\Integriq\Controller;

use InvalidArgumentException;
use OCA\Integriq\Exception\EgressRefusedException;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentNormaliser;
use OCA\Integriq\Service\OpenApi\OpenApiImportService;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Both routes are administrative: creating sources and endpoints is what an
 * integriq administrator does, so they are gated by #[AuthorizedAdminSetting]
 * like the other administrative routes, and never #[NoAdminRequired]. A
 * document comes in the body (`document`, as an object or as JSON/YAML text)
 * or by `url`, fetched through the egress guard.
 *
 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
 */
class OpenApiImportController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName    The app name.
	 * @param IRequest                  $request    The request.
	 * @param OpenApiImportService      $importer   Previews and imports.
	 * @param OpenApiDocumentNormaliser $normaliser Decodes a document given as text.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OpenApiImportService $importer,
		private readonly OpenApiDocumentNormaliser $normaliser,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What an import would create. Writes nothing.
	 *
	 * @return JSONResponse 200 with the preview, 400 for a document, URL or prefix that cannot be used.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function preview(): JSONResponse {
		try {
			$preview = $this->importer->preview(
				document: $this->document(),
				prefix: (string) $this->request->getParam('prefix', ''),
				serverIndex: self::optionalInt(value: $this->request->getParam('serverIndex')),
				offset: max(0, (int) $this->request->getParam('offset', 0)),
				limit: self::optionalInt(value: $this->request->getParam('limit'))
			);
		} catch (EgressRefusedException $exception) {
			return new JSONResponse(['error' => 'egress_refused', 'message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => 'invalid_document', 'message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($preview);
	}//end preview()

	/**
	 * Create the source and one endpoint per chosen operation.
	 *
	 * @return JSONResponse 201 when anything was created, 200 when every chosen operation was skipped,
	 *                      400 for a document, URL, prefix or selection that cannot be used.
	 *
	 * @spec openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001
	 */
	#[AuthorizedAdminSetting(IntegriqAdmin::class)]
	public function import(): JSONResponse {
		$operations = $this->request->getParam('operations', []);
		if (is_array($operations) === false) {
			$operations = [];
		}

		try {
			$result = $this->importer->import(
				document: $this->document(),
				prefix: (string) $this->request->getParam('prefix', ''),
				operationKeys: array_values(array_filter($operations, 'is_string')),
				serverIndex: self::optionalInt(value: $this->request->getParam('serverIndex')),
				name: (string) $this->request->getParam('name', '')
			);
		} catch (EgressRefusedException $exception) {
			return new JSONResponse(['error' => 'egress_refused', 'message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (InvalidArgumentException $exception) {
			return new JSONResponse(['error' => 'invalid_import', 'message' => $exception->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$status = Http::STATUS_OK;
		if ($result['created'] > 0) {
			$status = Http::STATUS_CREATED;
		}

		return new JSONResponse($result, $status);
	}//end import()

	/**
	 * The document from the body, or fetched from the given URL.
	 *
	 * @return array<string,mixed> The decoded document.
	 *
	 * @throws EgressRefusedException When the URL may not be fetched.
	 * @throws InvalidArgumentException When neither a document nor a URL is given.
	 */
	private function document(): array {
		$document = $this->request->getParam('document');
		if (is_array($document) === true && $document !== []) {
			return $document;
		}

		if (is_string($document) === true && trim($document) !== '') {
			return $this->normaliser->decode(text: $document);
		}

		$url = trim((string) $this->request->getParam('url', ''));
		if ($url !== '') {
			return $this->importer->fetch(url: $url);
		}

		throw new InvalidArgumentException('Give the document, or the URL to fetch it from.');
	}//end document()

	/**
	 * An optional integer parameter.
	 *
	 * @param mixed $value The parameter.
	 *
	 * @return int|null The integer, or null when none was given.
	 */
	private static function optionalInt(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		return (int) $value;
	}//end optionalInt()
}//end class
