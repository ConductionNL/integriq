<?php

/**
 * Integriq LogPageListing.
 *
 * One page of an OpenRegister log schema, paged by `_limit` and `_page`, for
 * the controllers that list their run logs.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/specs/logs-and-statistics/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Service\SearchServiceInterface;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Lists one page of a log schema in register `integriq`.
 *
 * The using controller provides `$request`, `$orObjectService` and `$l`.
 *
 * @spec openspec/specs/logs-and-statistics/spec.md
 */
trait LogPageListing {

	/**
	 * One page of the given log schema, filtered by the request parameters.
	 *
	 * @param SearchServiceInterface $searchService Strips the special query parameters.
	 * @param string                 $schema        The log schema in register `integriq`.
	 *
	 * @return JSONResponse The page, or a 500 when the query fails.
	 *
	 * @spec openspec/specs/logs-and-statistics/spec.md
	 */
	private function logPage(SearchServiceInterface $searchService, string $schema): JSONResponse {
		try {
			$filters = $this->request->getParams();
			$limit   = (int)($filters['_limit'] ?? 20);
			$page    = (int)($filters['_page'] ?? 1);
			$offset  = (($page - 1) * $limit);
			unset($filters['_limit'], $filters['_page']);

			$filters = array_merge(['register' => 'integriq', 'schema' => $schema], $searchService->unsetSpecialQueryParams(filters: $filters));
			$matches = $this->orObjectService->findAll(config: ['filters' => $filters, 'limit' => $limit, 'offset' => $offset]);
			$logs      = ($matches['results'] ?? $matches);
			$total     = ($matches['total'] ?? count($logs));

			$pages       = 1;
			$currentPage = 1;
			if ($limit > 0) {
				$pages       = ceil($total / $limit);
				$currentPage = (floor($offset / $limit) + 1);
			}

			return new JSONResponse(
				[
					'results' => $logs,
					'page' => $currentPage,
					'pages' => $pages,
					'results_count' => count($logs),
					'total' => $total,
				]
			);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => $this->l->t('Failed to retrieve logs: %s', [$e->getMessage()])], 500);
		}//end try
	}//end logPage()
}//end trait
