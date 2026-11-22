<?php

/**
 * Integriq ApiDocumentExport.
 *
 * Fills `external_api.openApiDocument` from the export each discovered API's
 * gateway offers, so an administrator can bring that API behind integriq.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ExternalApi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ExternalApi;

use InvalidArgumentException;
use OCA\Integriq\Adapters\Support\SupplierCallException;
use OCA\Integriq\Adapters\Support\SupplierSourceCall;
use OCA\Integriq\Service\OpenApi\OpenApiCredentialShapes;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentNormaliser;
use OCA\Integriq\Service\OpenApi\OpenApiDocumentReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Fetches the OpenAPI document of every API one gateway listed, after its discovery run.
 *
 * Design D4 of gateway-federated-api-discovery: the list is synchronized
 * first, then this step asks the same gateway source for each API's export
 * and stores it on the `external_api` object. An API whose `version` matches
 * the version its stored document was exported at is left alone, and an API
 * the gateway no longer lists (`sourceEndedAt` set) is skipped.
 *
 * The export is read from the source's answer at `documentPosition`, or, for
 * a gateway that answers with a short-lived link (Azure API Management's
 * `*-link` export formats), from the URL at `linkPosition`, fetched without
 * the gateway's credentials through the egress guard. Security schemes in the
 * document are kept as shapes only, as on an import.
 *
 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
 */
class ApiDocumentExport {

	/**
	 * The register the inventory lives in.
	 */
	public const REGISTER = 'integriq';

	/**
	 * The inventory's schema.
	 */
	public const SCHEMA = 'external_api';

	/**
	 * The most APIs one run reads.
	 */
	public const DEFAULT_LIMIT = 200;

	/**
	 * Keeps a document's security schemes as shapes only.
	 *
	 * @var OpenApiCredentialShapes
	 */
	private readonly OpenApiCredentialShapes $credentials;

	/**
	 * Constructor.
	 *
	 * @param SupplierSourceCall        $sourceCall    Calls the gateway's source and decodes its JSON answer.
	 * @param OrObjectService           $objectService Reads and writes the inventory.
	 * @param OpenApiDocumentReader     $reader        Fetches a linked export through the egress guard.
	 * @param OpenApiDocumentNormaliser $normaliser    Says whether a document is OpenAPI.
	 */
	public function __construct(
		private readonly SupplierSourceCall $sourceCall,
		private readonly OrObjectService $objectService,
		private readonly OpenApiDocumentReader $reader,
		private readonly OpenApiDocumentNormaliser $normaliser,
	) {
		$this->credentials = new OpenApiCredentialShapes();
	}//end __construct()

	/**
	 * Fetch and store the exports of one gateway's APIs.
	 *
	 * @param ApiDocumentExportSettings $settings What to fetch, from where.
	 *
	 * @return array{stored: list<string>, unchanged: list<string>, failed: array<string, string>}
	 *
	 * @spec openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003
	 */
	public function run(ApiDocumentExportSettings $settings): array {
		$report = ['stored' => [], 'unchanged' => [], 'failed' => []];
		foreach ($this->listedApis(settings: $settings) as $api) {
			$data = (array)$api->getObject();
			$name = (string)($data['name'] ?? $api->getUuid());
			if ($settings->force === false && self::isUnchanged(data: $data) === true) {
				$report['unchanged'][] = $name;
				continue;
			}

			try {
				$document = $this->export(settings: $settings, data: $data);
			} catch (SupplierCallException | InvalidArgumentException $exception) {
				$report['failed'][$name] = $exception->getMessage();
				continue;
			}

			$this->objectService->saveObject(
				object: array_merge(
					$data,
					['openApiDocument' => $document, 'openApiDocumentVersion' => (string)($data['version'] ?? '')]
				),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: self::REGISTER, schema: self::SCHEMA),
					uuid: (string)$api->getUuid()
				)
			);
			$report['stored'][] = $name;
		}//end foreach

		return $report;
	}//end run()

	/**
	 * The APIs the gateway still lists.
	 *
	 * @param ApiDocumentExportSettings $settings The run's settings.
	 *
	 * @return list<ObjectEntity>
	 */
	private function listedApis(ApiDocumentExportSettings $settings): array {
		$found = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => self::SCHEMA, 'gateway' => $settings->gateway],
				'limit' => $settings->limit,
			]
		);

		$apis = [];
		foreach ((array)($found['results'] ?? $found) as $candidate) {
			if ($candidate instanceof ObjectEntity === false) {
				continue;
			}

			$data = (array)$candidate->getObject();
			if (($data['gateway'] ?? null) !== $settings->gateway || empty($data['sourceEndedAt']) === false) {
				continue;
			}

			$apis[] = $candidate;
		}

		return $apis;
	}//end listedApis()

	/**
	 * Whether the stored document was exported at the version the gateway reports now.
	 *
	 * @param array<string, mixed> $data The external_api object.
	 *
	 * @return bool
	 */
	private static function isUnchanged(array $data): bool {
		if (is_array($data['openApiDocument'] ?? null) === false || $data['openApiDocument'] === []) {
			return false;
		}

		if (array_key_exists('openApiDocumentVersion', $data) === false) {
			return false;
		}

		return (string)$data['openApiDocumentVersion'] === (string)($data['version'] ?? '');
	}//end isUnchanged()

	/**
	 * The export of one API, as the document to store.
	 *
	 * @param ApiDocumentExportSettings $settings The run's settings.
	 * @param array<string, mixed>      $data     The external_api object.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws SupplierCallException When the gateway refuses or fails the export.
	 * @throws InvalidArgumentException When the answer holds no OpenAPI document.
	 */
	private function export(ApiDocumentExportSettings $settings, array $data): array {
		$apiId = trim((string)($data['gatewayApiId'] ?? ''));
		if ($apiId === '') {
			throw new InvalidArgumentException('The API has no gateway id to export it by.');
		}

		$answer = $this->sourceCall->json(
			slug: $settings->gateway,
			endpoint: $settings->endpointFor(apiId: $apiId),
			config: ['query' => $settings->query]
		);

		if ($settings->linkPosition !== '') {
			$link = self::at(answer: $answer, position: $settings->linkPosition);
			if (is_string($link) === false || str_starts_with($link, 'https://') === false) {
				throw new InvalidArgumentException('The export answer has no https link at '.$settings->linkPosition.'.');
			}

			$document = $this->reader->fetch(url: $link);
		} else {
			$document = self::at(answer: $answer, position: $settings->documentPosition);
		}

		if (is_array($document) === false) {
			throw new InvalidArgumentException('The export answer has no document at '.$settings->documentPosition.'.');
		}

		$this->normaliser->versionOf(document: $document);

		return $this->credentials->withoutSecretShapes(document: $document);
	}//end export()

	/**
	 * The value at a dot path in an answer; the whole answer for an empty path.
	 *
	 * @param array<mixed> $answer   The decoded answer.
	 * @param string       $position The dot path.
	 *
	 * @return mixed
	 */
	private static function at(array $answer, string $position): mixed {
		$value = $answer;
		foreach (array_filter(explode('.', $position), static fn (string $key): bool => $key !== '') as $key) {
			if (is_array($value) === false || array_key_exists($key, $value) === false) {
				return null;
			}

			$value = $value[$key];
		}

		return $value;
	}//end at()
}//end class
