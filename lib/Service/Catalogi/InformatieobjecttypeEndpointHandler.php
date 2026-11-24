<?php

/**
 * The Catalogi API 1.3 informatieobjecttypen, read only.
 *
 * Lists are paged by 100 in the standard envelope (`count`, `next`,
 * `previous`, `results`) and filter on `catalogus` and `status`
 * (`definitief` by default, `concept`, `alles`). A concept type is served only
 * to a client allowed to read concepts that asks for it; by uuid it is 404
 * otherwise. Every `url` and `catalogus` is the absolute URL served here.
 * Writes answer 405. The document types are read through a callable, so the
 * handler is tested without OpenRegister; {@see CatalogiWiring} hands it the
 * production side.
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
 * Serves the informatieobjecttypen of the bound schema.
 *
 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
 */
class InformatieobjecttypeEndpointHandler {

	/**
	 * The page size of a list.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Reads the stored document types of a binding.
	 *
	 * @var callable(CatalogiBinding): array<int, array<string, mixed>>
	 */
	private $typesReader;

	/**
	 * Constructor.
	 *
	 * @param CatalogiGate                                              $gate        Binding, token and scope.
	 * @param callable(CatalogiBinding): array<int, array<string, mixed>> $typesReader Reads the stored types.
	 */
	public function __construct(
		private readonly CatalogiGate $gate,
		callable $typesReader,
	) {
		$this->typesReader = $typesReader;

	}//end __construct()

	/**
	 * `GET /informatieobjecttypen`.
	 *
	 * @param string               $authorization The `Authorization` header.
	 * @param string               $baseUrl       The absolute URL of `/catalogi/api/v1`.
	 * @param array<string, mixed> $query         `catalogus`, `status`, `page`.
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

		$binding = $gate['binding'];
		$catalogus = trim((string)($query['catalogus'] ?? ''));
		$types = [];
		if ($catalogus === '' || $catalogus === $this->catalogusUrl(binding: $binding, baseUrl: $baseUrl)) {
			$status = trim((string)($query['status'] ?? 'definitief'));
			foreach ($this->types(binding: $binding, baseUrl: $baseUrl) as $type) {
				if (self::visible(concept: $type['concept'], status: $status, concepts: $gate['concepts']) === true) {
					$types[] = $type;
				}
			}
		}

		$page = $this->page(items: $types, page: (int)($query['page'] ?? 1), listUrl: $baseUrl . '/informatieobjecttypen', query: $query);

		return ['status' => 200, 'body' => $page];
	}//end index()

	/**
	 * `GET /informatieobjecttypen/{uuid}`.
	 *
	 * @param string $authorization The `Authorization` header.
	 * @param string $uuid          The type.
	 * @param string $baseUrl       The absolute URL of `/catalogi/api/v1`.
	 * @param string $status        `definitief` (default), `concept` or `alles`.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function show(string $authorization, string $uuid, string $baseUrl, string $status = 'definitief'): array {
		$gate = $this->gate->open(authorization: $authorization);
		if ($gate['refusal'] !== null) {
			return $gate['refusal'];
		}

		foreach ($this->types(binding: $gate['binding'], baseUrl: $baseUrl) as $type) {
			// A concept type answers 404 by uuid unless a concept reader asks
			// for concepts with the status filter (REQ-ZCS-002).
			$servable = ($type['concept'] === false || self::visible(concept: true, status: $status, concepts: $gate['concepts']) === true);
			if ($type['uuid'] === $uuid && $servable === true) {
				return ['status' => 200, 'body' => $type];
			}
		}

		return (new CatalogiProblem(status: 404, code: 'not-found', title: 'No such informatieobjecttype.'))->answer();
	}//end show()

	/**
	 * Every write: the facade is read only.
	 *
	 * @return array{status: int, body: array<string, mixed>}
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function write(): array {
		return (new CatalogiProblem(status: 405, code: 'method-not-allowed', title: 'This catalogue is read only.'))->answer();
	}//end write()

	/**
	 * The URL of the bound catalogue.
	 *
	 * @param CatalogiBinding $binding The binding.
	 * @param string          $baseUrl The absolute URL of `/catalogi/api/v1`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function catalogusUrl(CatalogiBinding $binding, string $baseUrl): string {
		return $baseUrl . '/catalogussen/' . $binding->uuid;
	}//end catalogusUrl()

	/**
	 * Every stored type as a resource, in a stable order.
	 *
	 * @param CatalogiBinding $binding The binding.
	 * @param string          $baseUrl The absolute URL of `/catalogi/api/v1`.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function types(CatalogiBinding $binding, string $baseUrl): array {
		$types = [];
		foreach (($this->typesReader)($binding) as $object) {
			$mapped = $binding->mapType(object: $object);
			if ($mapped['uuid'] === '') {
				continue;
			}

			$types[] = [
				'url' => $baseUrl . '/informatieobjecttypen/' . $mapped['uuid'],
				'uuid' => $mapped['uuid'],
				'catalogus' => $this->catalogusUrl(binding: $binding, baseUrl: $baseUrl),
				'omschrijving' => $mapped['omschrijving'],
				'vertrouwelijkheidaanduiding' => $mapped['vertrouwelijkheidaanduiding'],
				'informatieobjectcategorie' => $mapped['informatieobjectcategorie'],
				'beginGeldigheid' => $mapped['beginGeldigheid'],
				'concept' => $mapped['concept'],
				'besluittypen' => [],
			];
		}

		usort($types, static fn (array $a, array $b): int => [$a['omschrijving'], $a['uuid']] <=> [$b['omschrijving'], $b['uuid']]);

		return $types;
	}//end types()

	/**
	 * Whether a type is listed under a status filter.
	 *
	 * @param bool   $concept  Whether the type is a concept.
	 * @param string $status   `definitief`, `concept` or `alles`.
	 * @param bool   $concepts Whether the client may read concepts.
	 *
	 * @return bool
	 */
	private static function visible(bool $concept, string $status, bool $concepts): bool {
		if ($concept === false) {
			return $status !== 'concept';
		}

		return $concepts === true && in_array($status, ['concept', 'alles'], true) === true;
	}//end visible()

	/**
	 * One page of a list in the standard envelope.
	 *
	 * @param list<array<string, mixed>> $items   Every matching item.
	 * @param int                        $page    The page asked for, from 1.
	 * @param string                     $listUrl The list's absolute URL.
	 * @param array<string, mixed>       $query   The query, carried into next and previous.
	 *
	 * @return array{count: int, next: string|null, previous: string|null, results: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/zgw-catalogi-served/specs/zgw-catalogi-facade/spec.md#requirement-integriq-serves-catalogussen-and-informatieobjecttypen-read-only-req-zcs-001
	 */
	public function page(array $items, int $page, string $listUrl, array $query): array {
		$page = max(1, $page);
		$count = count($items);
		$params = array_filter(
			['catalogus' => ($query['catalogus'] ?? null), 'status' => ($query['status'] ?? null)],
			static fn ($value): bool => $value !== null && $value !== ''
		);

		$next = null;
		if ($page * self::PAGE_SIZE < $count) {
			$next = $listUrl . '?' . http_build_query($params + ['page' => ($page + 1)]);
		}

		$previous = null;
		if ($page > 1) {
			$previous = $listUrl . '?' . http_build_query($params + ['page' => ($page - 1)]);
		}

		return [
			'count' => $count,
			'next' => $next,
			'previous' => $previous,
			'results' => array_slice($items, (($page - 1) * self::PAGE_SIZE), self::PAGE_SIZE),
		];
	}//end page()

}//end class
