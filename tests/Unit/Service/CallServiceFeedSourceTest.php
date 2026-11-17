<?php

/**
 * Unit tests for the feed source type, driven through the real CallService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
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

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Loader\ArrayLoader;

/**
 * A call to a feed source is answered in-process and logged like any call.
 */
class CallServiceFeedSourceTest extends TestCase {

	private const SOURCE_UUID = 'eeeeeeee-0000-4000-8000-000000000001';

	/**
	 * Unresolvable on purpose: an HTTP attempt would fail instead of answering.
	 */
	private const FEED = 'https://jobfeed.invalid/index.php/apps/integriq/api/endpoint/jobfeeds/indeed.xml';

	/**
	 * Objects CallService saved, in order.
	 *
	 * @var array<int,array{object:array,schema:mixed}>
	 */
	private array $saved = [];

	/**
	 * The real CallService over a recording object store.
	 *
	 * @return CallService
	 */
	private function callService(): CallService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], ObjectSaveOptions $options = new ObjectSaveOptions()) {
				$this->saved[] = ['object' => $object, 'schema' => $options->target->schema];
				$entity = new ObjectEntity();
				$entity->setUuid('cccccccc-0000-4000-8000-00000000000' . count($this->saved));
				$entity->setObject(is_array($object) === true ? $object : []);

				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		return new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$brokered,
			new SensitiveFieldRegistry(),
			new BodyCapturePolicy(appConfig: $appConfig),
		);
	}//end callService()

	/**
	 * A feed source.
	 *
	 * @param boolean $enabled Whether the source is enabled.
	 *
	 * @return ObjectEntity
	 */
	private function source(bool $enabled = true): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid(self::SOURCE_UUID);
		$source->setObject(
			[
				'name' => 'Indeed vacancy feed',
				'slug' => 'jobboard-indeed',
				'type' => 'feed',
				'location' => self::FEED,
				'isEnabled' => $enabled,
			]
		);

		return $source;
	}//end source()

	/**
	 * The call answers "published in feed" with the feed URL and writes one call log naming the source.
	 *
	 * @return void
	 */
	public function testACallAnswersPublishedInFeedWithTheFeedLink(): void {
		$log = $this->callService()->call(
			source: $this->source(),
			endpoint: '/vacancies/V-2026-0001',
			method: 'POST',
			config: ['body' => '{"title":"Vue developer"}', 'headers' => ['Content-Type' => 'application/json']]
		)->getObject();

		$this->assertSame(200, (int)$log['response']['statusCode']);
		$this->assertSame(['status' => 'published in feed', 'link' => self::FEED], json_decode((string)$log['response']['body'], true));

		$logs = array_values(array_filter($this->saved, fn ($row) => $row['schema'] === 'call_log'));
		$this->assertCount(1, $logs);
		$this->assertSame(self::SOURCE_UUID, $logs[0]['object']['source']);
		$this->assertSame(200, $logs[0]['object']['statusCode']);
		$this->assertSame([], RegisterSchemaValidator::errors('call_log', $logs[0]['object']));
	}//end testACallAnswersPublishedInFeedWithTheFeedLink()

	/**
	 * An asynchronous caller receives the same answer as a fulfilled promise.
	 *
	 * @return void
	 */
	public function testAnAsynchronousCallReceivesTheSameAnswer(): void {
		$log = $this->callService()->callAsync(source: $this->source(), endpoint: '/vacancies/V-2026-0001', method: 'POST')->wait();

		$this->assertSame(200, (int)$log->getObject()['response']['statusCode']);
		$this->assertSame('published in feed', json_decode((string)$log->getObject()['response']['body'], true)['status']);
	}//end testAnAsynchronousCallReceivesTheSameAnswer()

	/**
	 * A dormant feed source is refused like any disabled source, before an answer.
	 *
	 * @return void
	 */
	public function testADisabledFeedSourceIsRefused(): void {
		$log = $this->callService()->call(source: $this->source(enabled: false), endpoint: '/vacancies/V-2026-0001', method: 'POST')->getObject();

		$this->assertSame(409, $log['statusCode']);
		$this->assertSame('This source is not enabled', $log['statusMessage']);
		$this->assertArrayNotHasKey('response', $log);
	}//end testADisabledFeedSourceIsRefused()
}//end class
