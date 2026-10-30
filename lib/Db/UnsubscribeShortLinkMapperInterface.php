<?php

/**
 * Contract of the UnsubscribeShortLinkMapper.
 *
 * @category Contract
 * @package  OCA\Integriq\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Db;

/**
 * Public operations of {@see UnsubscribeShortLinkMapper} that other components call.
 */
interface UnsubscribeShortLinkMapperInterface {
	/**
	 * Store one short link.
	 *
	 * @param UnsubscribeShortLink $link The link.
	 *
	 * @return UnsubscribeShortLink The stored link.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function store(UnsubscribeShortLink $link): UnsubscribeShortLink;

	/**
	 * The link behind a short id, or null.
	 *
	 * @param string $shortId The id.
	 *
	 * @return UnsubscribeShortLink|null The link.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-the-unsubscribe-link-fits-the-channel-and-changes-nothing-on-get-req-ooa-006
	 */
	public function findByShortId(string $shortId): ?UnsubscribeShortLink;
}
