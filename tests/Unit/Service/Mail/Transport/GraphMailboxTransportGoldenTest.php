<?php

/**
 * Golden test for the Graph mailbox transport's message mapping.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Mail\Transport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Mail\Transport;

use OCA\Integriq\Service\Mail\Transport\GraphMailboxTransport;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

/**
 * Pins every request a fetch makes and every parsed message it returns,
 * against a golden file.
 */
class GraphMailboxTransportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/../../../../fixtures/mail/graph-messages.json';

	/**
	 * Every fetch matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryFetchMatchesTheGoldenFile(): void {
		$messages = [
			'value' => [
				[
					'id' => 'm-1', 'internetMessageId' => ' <abc@x.nl> ', 'subject' => 'Hallo', 'receivedDateTime' => '2026-01-01T10:00:00Z',
					'from' => ['emailAddress' => ['address' => 'a@x.nl']],
					'toRecipients' => [['emailAddress' => ['address' => 'b@x.nl']], ['emailAddress' => ['name' => 'no address']], ['emailAddress' => ['address' => 'c@x.nl']]],
					'body' => ['contentType' => 'HTML', 'content' => '<p>Hoi <script>x()</script><b>daar</b></p>'], 'bodyPreview' => 'Hoi daar',
					'hasAttachments' => true,
				],
				'not-a-message',
				['id' => 'm 2', 'body' => ['content' => 'plain'], 'hasAttachments' => 'yes'],
				['internetMessageId' => '<>', 'hasAttachments' => true, 'receivedDateTime' => 12],
			],
		];
		$attachments = ['value' => [['name' => 'a.txt', 'contentType' => 'text/plain', 'contentBytes' => base64_encode('hello')], ['contentBytes' => '!!'], 'x']];
		$requests    = [];
		$client      = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url, array $options) use (&$requests, $messages, $attachments): IResponse {
				$requests[] = [$url, $options];
				$response   = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn(json_encode(str_ends_with($url, '/attachments') === true ? $attachments : $messages));
				return $response;
			}
		);
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$fetched = (new GraphMailboxTransport($service))->fetch(
			['mailbox' => ' box@x.nl ', 'accessToken' => ' tok ', 'folder' => 'Post In', 'baseUrl' => 'https://graph.example/v1/'],
			' 2026-01-01T00:00:00Z '
		);
		$actual = [
			'requests' => $requests,
			'messages' => array_map(
				static fn ($message): array => [
					$message->getMessageId(), $message->getFrom(), $message->getTo(), $message->getSubject(), $message->getReceivedAt(),
					$message->getBodyText(), $message->getBodyHtml(), $message->getAttachments(), $message->getWarning(),
				],
				$fetched
			),
		];

		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::GOLDEN), true), json_decode((string) json_encode($actual), true));

	}//end testEveryFetchMatchesTheGoldenFile()
}//end class
