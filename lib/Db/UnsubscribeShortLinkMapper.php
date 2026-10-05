<?php

/**
 * Integriq UnsubscribeShortLinkMapper.
 *
 * Stores and resolves the short ids an SMS unsubscribe text carries.
 *
 * @category Db
 * @package  OCA\Integriq\Db
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * The short link table.
 *
 * @template-extends QBMapper<UnsubscribeShortLink>
 *
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
 */
class UnsubscribeShortLinkMapper extends QBMapper {

	/**
	 * The table, unprefixed.
	 *
	 * @var string
	 */
	public const TABLE = 'integriq_unsubscribe_short';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: UnsubscribeShortLink::class);

	}//end __construct()

	/**
	 * Store one short link.
	 *
	 * @param UnsubscribeShortLink $link The link.
	 *
	 * @return UnsubscribeShortLink The stored link.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function store(UnsubscribeShortLink $link): UnsubscribeShortLink {
		return $this->insert(entity: $link);

	}//end store()

	/**
	 * The link behind a short id, or null.
	 *
	 * @param string $shortId The id.
	 *
	 * @return UnsubscribeShortLink|null The link.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function findByShortId(string $shortId): ?UnsubscribeShortLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('short_id', $qb->createNamedParameter($shortId)));

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException) {
			return null;
		}

	}//end findByShortId()

}//end class
