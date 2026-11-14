<?php

/**
 * The page of an Objecten API list or search that a request asks for.
 *
 * The objecttype and base url the records are rendered with, and the page
 * size and number read from `pageSize` and `page`: a size between 1 and
 * {@see ObjectEndpointHandler::MAX_PAGE_SIZE} (default
 * {@see ObjectEndpointHandler::PAGE_SIZE}), a number of at least 1. Built by
 * {@see ObjectEndpointHandler} per request.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
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
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

/**
 * One requested page.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
final class ObjectPage {

	/**
	 * The page size, between 1 and the maximum.
	 *
	 * @var int
	 */
	public readonly int $size;

	/**
	 * The page number, from 1.
	 *
	 * @var int
	 */
	public readonly int $number;

	/**
	 * The base url the record urls are built on.
	 *
	 * @var string
	 */
	public string $baseUrl = '';

	/**
	 * Constructor.
	 *
	 * @param string $type The objecttype the records are rendered as.
	 * @param array<string,mixed> $query The query or body, with optional `pageSize` and `page`.
	 */
	public function __construct(
		public readonly string $type,
		array $query,
	) {
		$size = (int)($query['pageSize'] ?? ObjectEndpointHandler::PAGE_SIZE);
		$this->size = max(1, min($size, ObjectEndpointHandler::MAX_PAGE_SIZE));
		$this->number = max(1, (int)($query['page'] ?? 1));
	}//end __construct()

	/**
	 * How many objects come before this page.
	 *
	 * @return int The offset.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function offset(): int {
		return (($this->number - 1) * $this->size);
	}//end offset()

	/**
	 * The next page number, or null when this page reaches the total.
	 *
	 * @param int $total How many objects the whole list holds.
	 *
	 * @return int|null The next page.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function next(int $total): ?int {
		if (($this->number * $this->size) < $total) {
			return ($this->number + 1);
		}

		return null;
	}//end next()

	/**
	 * The previous page number, or null on the first page.
	 *
	 * @return int|null The previous page.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function previous(): ?int {
		if ($this->number > 1) {
			return ($this->number - 1);
		}

		return null;
	}//end previous()
}//end class
