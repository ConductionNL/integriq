<?php

/**
 * Unit tests for Microsoft365Adapter.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Adapter;

use OCA\OpenConnector\Service\Adapter\Saas\Microsoft365Adapter;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Microsoft 365 reference adapter (REQ-SPC-001).
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-4
 */
class Microsoft365AdapterTest extends TestCase {

	/**
	 * @var CredentialBrokerService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $credentialBroker;

	/**
	 * @var Microsoft365Adapter
	 */
	private Microsoft365Adapter $adapter;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->credentialBroker = $this->createMock(CredentialBrokerService::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('cred-uuid-m365');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->adapter = new Microsoft365Adapter(
			credentialBroker: $this->credentialBroker,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n
		);
	}//end setUp()

	/**
	 * Declares calendar-read/mail-metadata-read, and NOT a mail-write/send
	 * capability (this change scopes the reference adapter to reads only).
	 *
	 * @return void
	 */
	public function testCapabilitiesAreReadOnly(): void {
		$capabilities = $this->adapter->getCapabilities();

		$this->assertSame(['calendar-read', 'mail-metadata-read'], $capabilities);
		$this->assertNotContains('mail-send', $capabilities);
		$this->assertNotContains('mail-write', $capabilities);
	}//end testCapabilitiesAreReadOnly()

	/**
	 * `listCalendarEvents()` calls `/v1.0/me/events` and normalises the response.
	 *
	 * @return void
	 */
	public function testListCalendarEventsNormalisesGraphResponse(): void {
		$graphResponse = [
			'value' => [
				[
					'id' => 'event-1',
					'subject' => 'Standup',
					'start' => ['dateTime' => '2026-01-01T09:00:00'],
					'end' => ['dateTime' => '2026-01-01T09:15:00'],
					'organizer' => ['emailAddress' => ['address' => 'alice@example.com']],
				],
			],
		];

		$this->credentialBroker->expects($this->once())
			->method('request')
			->with('cred-uuid-m365', 'openconnector', 'GET', '/v1.0/me/events', [], null, null)
			->willReturn(['status' => 200, 'headers' => [], 'body' => json_encode($graphResponse)]);

		$events = $this->adapter->listCalendarEvents();

		$this->assertCount(1, $events);
		$this->assertSame('Standup', $events[0]['subject']);
		$this->assertSame('alice@example.com', $events[0]['organizer']);
	}//end testListCalendarEventsNormalisesGraphResponse()

	/**
	 * `listMailMetadata()` requests a `$select`-restricted field set — never
	 * the message body — and normalises the response.
	 *
	 * @return void
	 */
	public function testListMailMetadataRestrictsFieldSelection(): void {
		$graphResponse = [
			'value' => [
				[
					'id' => 'msg-1',
					'subject' => 'Invoice',
					'from' => ['emailAddress' => ['address' => 'billing@example.com']],
					'receivedDateTime' => '2026-01-01T00:00:00Z',
					'hasAttachments' => true,
				],
			],
		];

		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				'cred-uuid-m365',
				'openconnector',
				'GET',
				$this->logicalAnd(
					$this->stringContains('/v1.0/me/messages'),
					$this->stringContains('$select=id,subject,from,receivedDateTime,hasAttachments')
				),
				[],
				null,
				null
			)
			->willReturn(['status' => 200, 'headers' => [], 'body' => json_encode($graphResponse)]);

		$messages = $this->adapter->listMailMetadata();

		$this->assertCount(1, $messages);
		$this->assertSame('Invoice', $messages[0]['subject']);
		$this->assertTrue($messages[0]['hasAttachments']);
		$this->assertArrayNotHasKey('body', $messages[0]);
	}//end testListMailMetadataRestrictsFieldSelection()

	/**
	 * `list()` defaults to calendar events; `resource=mail` switches to mail metadata.
	 *
	 * @return void
	 */
	public function testListSwitchesBetweenCalendarAndMail(): void {
		$this->credentialBroker->method('request')
			->willReturn(['status' => 200, 'headers' => [], 'body' => json_encode(['value' => []])]);

		$this->assertSame([], $this->adapter->list('register', 'schema', 'object-id'));
		$this->assertSame([], $this->adapter->list('register', 'schema', 'object-id', ['resource' => 'mail']));
	}//end testListSwitchesBetweenCalendarAndMail()
}//end class
