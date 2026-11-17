<?php

/**
 * The answer of a source that boards pull from as a feed.
 *
 * A feed source is not called over HTTP: the other side reads the feed when
 * it chooses. A call to it answers that the item is published in the feed,
 * with the source's location (the feed URL) as its link, so a flow that
 * posts to push and feed boards alike records the same outcome for both.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Call;

use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Answers a call to a feed source in-process.
 *
 * @spec openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002
 */
final class FeedSource {

	/**
	 * The source type of a feed source.
	 */
	public const SOURCE_TYPE = 'feed';

	/**
	 * The status a call to a feed source answers.
	 */
	public const PUBLISHED = 'published in feed';

	/**
	 * The answer to a call to a feed source: published in the feed at the source's location.
	 *
	 * @param ObjectEntity $source The feed source.
	 *
	 * @return Response A 200 JSON answer with the status and the feed's link.
	 *
	 * @spec openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002
	 */
	public function answer(ObjectEntity $source): Response {
		$body = [
			'status' => self::PUBLISHED,
			'link' => (string)($source->getObject()['location'] ?? ''),
		];

		return new Response(
			status: 200,
			headers: ['Content-Type' => 'application/json'],
			body: json_encode($body, (JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
		);

	}//end answer()
}//end class
