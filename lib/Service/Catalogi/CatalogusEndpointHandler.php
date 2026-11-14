<?php

/**
 * The Catalogi API 1.3 catalogussen, read only: the one bound catalogue.
 *
 * The catalogue lists its definitive informatieobjecttypen by URL, and every
 * URL is the absolute one served here, so following it returns the same
 * resource.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Catalogi
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Catalogi;

/**
 * Serves the bound catalogue.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */
class CatalogusEndpointHandler {

	/**
	 * Constructor.
	 *
	 * @param CatalogiGate                        $gate  Binding, token and scope.
	 * @param InformatieobjecttypeEndpointHandler $types The types the catalogue lists.
	 */
	public function __construct(
		private readonly CatalogiGate $gate,
		private readonly InformatieobjecttypeEndpointHandler $types,
	) {

	}//end __construct()

	/**
	 * `GET /catalogussen`.
	 *
	 * @param string               $authorization The `Authorization` header.
	 * @param string               $baseUrl       The absolute URL of `/catalogi/api/v1`.
	 * @param array<string, mixed> $query         `domein`, `rsin`, `page`.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function index(string $authorization, string $baseUrl, array $query): array {
		$gate = $this->gate->open(authorization: $authorization);
		if ($gate['refusal'] !== null) {
			return $gate['refusal'];
		}

		$catalogue = $this->catalogue(binding: $gate['binding'], authorization: $authorization, baseUrl: $baseUrl);
		$items = [];
		if (self::matches(catalogue: $catalogue, query: $query) === true) {
			$items[] = $catalogue;
		}

		$page = $this->types->page(items: $items, page: (int)($query['page'] ?? 1), listUrl: $baseUrl . '/catalogussen', query: []);

		return ['status' => 200, 'body' => $page];
	}//end index()

	/**
	 * `GET /catalogussen/{uuid}`.
	 *
	 * @param string $authorization The `Authorization` header.
	 * @param string $uuid          The catalogue.
	 * @param string $baseUrl       The absolute URL of `/catalogi/api/v1`.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function show(string $authorization, string $uuid, string $baseUrl): array {
		$gate = $this->gate->open(authorization: $authorization);
		if ($gate['refusal'] !== null) {
			return $gate['refusal'];
		}

		if ($gate['binding']->uuid !== $uuid) {
			return (new CatalogiProblem(status: 404, code: 'not-found', title: 'No such catalogus.'))->answer();
		}

		return ['status' => 200, 'body' => $this->catalogue(binding: $gate['binding'], authorization: $authorization, baseUrl: $baseUrl)];
	}//end show()

	/**
	 * The catalogue resource.
	 *
	 * @param CatalogiBinding $binding       The binding.
	 * @param string          $authorization The `Authorization` header, for the type list.
	 * @param string          $baseUrl       The absolute URL of `/catalogi/api/v1`.
	 *
	 * @return array<string, mixed>
	 */
	private function catalogue(CatalogiBinding $binding, string $authorization, string $baseUrl): array {
		$types = $this->types->index(authorization: $authorization, baseUrl: $baseUrl, query: ['page' => 1]);
		$urls = array_column(($types['body']['results'] ?? []), 'url');

		return [
			'url' => $this->types->catalogusUrl(binding: $binding, baseUrl: $baseUrl),
			'domein' => $binding->catalog['domein'],
			'rsin' => $binding->catalog['rsin'],
			'naam' => $binding->catalog['naam'],
			'informatieobjecttypen' => $urls,
			'zaaktypen' => [],
			'besluittypen' => [],
		];
	}//end catalogue()

	/**
	 * Whether the catalogue matches the `domein` and `rsin` filters.
	 *
	 * @param array<string, mixed> $catalogue The catalogue.
	 * @param array<string, mixed> $query     The query.
	 *
	 * @return bool
	 */
	private static function matches(array $catalogue, array $query): bool {
		foreach (['domein', 'rsin'] as $filter) {
			$wanted = trim((string)($query[$filter] ?? ''));
			if ($wanted !== '' && $wanted !== (string)$catalogue[$filter]) {
				return false;
			}
		}

		return true;
	}//end matches()

}//end class
