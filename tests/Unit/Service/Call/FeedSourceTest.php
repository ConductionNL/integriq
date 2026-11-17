<?php

/**
 * Unit tests for FeedSource.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connectors-job-board-multiposting/specs/job-board-connectors/spec.md#requirement-werknl-linkedin-and-indeed-are-job-board-templates-req-jbc-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Call;

use OCA\Integriq\Service\Call\FeedSource;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A call to a feed source answers "published in feed" with the feed's link.
 */
#[CoversClass(FeedSource::class)]
final class FeedSourceTest extends TestCase {

	/**
	 * A feed source with a location.
	 *
	 * @param array $data The source data.
	 *
	 * @return ObjectEntity
	 */
	private function source(array $data): ObjectEntity {
		$source = new ObjectEntity();
		$source->setObject($data);

		return $source;
	}//end source()

	/**
	 * The answer is a 200 JSON body naming the status and the location as the link.
	 *
	 * @return void
	 */
	public function testTheAnswerIsPublishedInFeedAtTheLocation(): void {
		$feed = 'https://cloud.example.nl/index.php/apps/integriq/api/endpoint/jobfeeds/indeed.xml';

		$answer = (new FeedSource())->answer(source: $this->source(['type' => 'feed', 'location' => $feed]));

		$this->assertSame(200, $answer->getStatusCode());
		$this->assertSame(['application/json'], $answer->getHeader('Content-Type'));
		$this->assertSame('{"status":"published in feed","link":"' . $feed . '"}', (string)$answer->getBody());
	}//end testTheAnswerIsPublishedInFeedAtTheLocation()

	/**
	 * A source without a location answers an empty link rather than failing.
	 *
	 * @return void
	 */
	public function testASourceWithoutALocationAnswersAnEmptyLink(): void {
		$answer = (new FeedSource())->answer(source: $this->source(['type' => 'feed']));

		$this->assertSame(['status' => 'published in feed', 'link' => ''], json_decode((string)$answer->getBody(), true));
	}//end testASourceWithoutALocationAnswersAnEmptyLink()
}//end class
