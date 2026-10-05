<?php

/**
 * An UnsubscribeShortLinkMapper over an array, for unit tests.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\Integriq\Db\UnsubscribeShortLink;
use OCA\Integriq\Db\UnsubscribeShortLinkMapper;
use OCP\IDBConnection;

/**
 * Keeps short links by id.
 */
class InMemoryShortLinkMapper extends UnsubscribeShortLinkMapper {

	/**
	 * The links, by short id.
	 *
	 * @var array<string,UnsubscribeShortLink>
	 */
	public array $rows = [];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db A connection double; nothing reaches it.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db);

	}//end __construct()

	/**
	 * Store one link.
	 *
	 * @param UnsubscribeShortLink $link The link.
	 *
	 * @return UnsubscribeShortLink The link.
	 */
	public function store(UnsubscribeShortLink $link): UnsubscribeShortLink {
		$link->setId(count($this->rows) + 1);
		$this->rows[$link->getShortId()] = $link;

		return $link;

	}//end store()

	/**
	 * One link by id.
	 *
	 * @param string $shortId The id.
	 *
	 * @return UnsubscribeShortLink|null The link.
	 */
	public function findByShortId(string $shortId): ?UnsubscribeShortLink {
		return ($this->rows[$shortId] ?? null);

	}//end findByShortId()

}//end class
