<?php

/**
 * Unit tests for ImapMailboxTransport.
 *
 * The imap extension is a function API and is usually absent on test hosts.
 * PHP resolves an unqualified function call against the caller's namespace
 * before the global one, so the stubs declared below (in the transport's own
 * namespace) stand in for the IMAP server: they record what the transport
 * asks for and answer from a per-test script. Only that boundary is faked;
 * the message parser is the real one.
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

namespace OCA\Integriq\Service\Mail\Transport {
	if (function_exists(__NAMESPACE__ . '\\imap_open') === false) {

		/**
		 * Stub for imap_open(): records the call and answers from the script.
		 *
		 * @param string $mailbox The mailbox string.
		 * @param string $user The username.
		 * @param string $password The password.
		 * @param int $flags The open flags.
		 * @param int $retries The retry count.
		 *
		 * @return mixed The scripted connection, or false.
		 */
		function imap_open(string $mailbox, string $user, string $password, int $flags = 0, int $retries = 0): mixed {
			$GLOBALS['__integriq_imap_stub']['calls'][] = ['open', $mailbox, $user, $password, $flags, $retries];

			return ($GLOBALS['__integriq_imap_stub']['open'] ?? false);
		}//end imap_open()

		/**
		 * Stub for imap_search(): records the criteria and answers from the script.
		 *
		 * @param mixed $connection The connection.
		 * @param string $criteria The search criteria.
		 *
		 * @return array<int,int>|false The scripted message numbers.
		 */
		function imap_search(mixed $connection, string $criteria): array|false {
			$GLOBALS['__integriq_imap_stub']['calls'][] = ['search', $connection, $criteria];
			if (isset($GLOBALS['__integriq_imap_stub']['searchThrows']) === true) {
				throw $GLOBALS['__integriq_imap_stub']['searchThrows'];
			}

			return ($GLOBALS['__integriq_imap_stub']['search'] ?? false);
		}//end imap_search()

		/**
		 * Stub for imap_fetchheader().
		 *
		 * @param mixed $connection The connection.
		 * @param int $number The message number.
		 *
		 * @return string|false The scripted header.
		 */
		function imap_fetchheader(mixed $connection, int $number): string|false {
			$GLOBALS['__integriq_imap_stub']['calls'][] = ['fetchheader', $connection, $number];

			return ($GLOBALS['__integriq_imap_stub']['headers'][$number] ?? false);
		}//end imap_fetchheader()

		/**
		 * Stub for imap_body().
		 *
		 * @param mixed $connection The connection.
		 * @param int $number The message number.
		 *
		 * @return string|false The scripted body.
		 */
		function imap_body(mixed $connection, int $number): string|false {
			$GLOBALS['__integriq_imap_stub']['calls'][] = ['body', $connection, $number];

			return ($GLOBALS['__integriq_imap_stub']['bodies'][$number] ?? false);
		}//end imap_body()

		/**
		 * Stub for imap_close().
		 *
		 * @param mixed $connection The connection.
		 *
		 * @return bool Always true.
		 */
		function imap_close(mixed $connection): bool {
			$GLOBALS['__integriq_imap_stub']['calls'][] = ['close', $connection];

			return true;
		}//end imap_close()
	}//end if
}//end namespace

namespace OCA\Integriq\Tests\Unit\Service\Mail\Transport {

	use OCA\Integriq\Exception\MailboxTransportException;
	use OCA\Integriq\Service\Mail\EmlParser;
	use OCA\Integriq\Service\Mail\MessageParser;
	use OCA\Integriq\Service\Mail\MsgParser;
	use OCA\Integriq\Service\Mail\Transport\ImapMailboxTransport;
	use PHPUnit\Framework\TestCase;
	use RuntimeException;

	/**
	 * The IMAP binding against a scripted IMAP function boundary.
	 *
	 * @covers \OCA\Integriq\Service\Mail\Transport\ImapMailboxTransport
	 * @uses   \OCA\Integriq\Service\Mail\MessageParser
	 * @uses   \OCA\Integriq\Service\Mail\EmlParser
	 * @uses   \OCA\Integriq\Service\Mail\MsgParser
	 * @uses   \OCA\Integriq\Service\Mail\CompoundFileReader
	 * @uses   \OCA\Integriq\Service\Mail\MailHeaders
	 * @uses   \OCA\Integriq\Service\Mail\MimePart
	 * @uses   \OCA\Integriq\Service\Mail\HtmlSanitizer
	 * @uses   \OCA\Integriq\Service\Mail\ParsedMessage
	 */
	class ImapMailboxTransportTest extends TestCase {

		/**
		 * The real parser the transport hands raw messages to.
		 *
		 * @var MessageParser
		 */
		private MessageParser $parser;

		/**
		 * Reset the IMAP script and build the parser.
		 *
		 * @return void
		 */
		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['__integriq_imap_stub'] = ['calls' => []];
			$this->parser = new MessageParser(new EmlParser(), new MsgParser());

		}//end setUp()

		/**
		 * Drop the IMAP script.
		 *
		 * @return void
		 */
		protected function tearDown(): void {
			unset($GLOBALS['__integriq_imap_stub']);
			parent::tearDown();

		}//end tearDown()

		/**
		 * A transport that reports the extension as present (or not), so the
		 * fetch path runs whether or not this host has imap installed.
		 *
		 * @param bool $usable What isUsable() should answer.
		 *
		 * @return ImapMailboxTransport The subject.
		 */
		private function transport(bool $usable = true): ImapMailboxTransport {
			return new class($this->parser, $usable) extends ImapMailboxTransport {

				/**
				 * Constructor.
				 *
				 * @param MessageParser $parser The parser.
				 * @param bool $usable The usability answer.
				 */
				public function __construct(MessageParser $parser, private readonly bool $usable) {
					parent::__construct($parser);

				}//end __construct()

				/**
				 * The scripted usability.
				 *
				 * @return bool The answer.
				 */
				public function isUsable(): bool {
					return $this->usable;

				}//end isUsable()
			};

		}//end transport()

		/**
		 * The calls of one kind the transport made.
		 *
		 * @param string $kind The stub name.
		 *
		 * @return array<int,array<int,mixed>> The calls.
		 */
		private function calls(string $kind): array {
			return array_values(
				array_filter(
					$GLOBALS['__integriq_imap_stub']['calls'],
					static fn (array $call): bool => $call[0] === $kind
				)
			);

		}//end calls()

		/**
		 * The protocol id is imap and usability follows the extension.
		 *
		 * @return void
		 */
		public function testProtocolAndUsabilityFollowTheExtension(): void {
			$transport = new ImapMailboxTransport($this->parser);

			$this->assertSame('imap', $transport->getProtocol());
			$this->assertSame(function_exists('imap_open'), $transport->isUsable());

		}//end testProtocolAndUsabilityFollowTheExtension()

		/**
		 * Without the extension the transport refuses before touching the server.
		 *
		 * @return void
		 */
		public function testFetchRefusesWhenTheExtensionIsAbsent(): void {
			try {
				$this->transport(usable: false)->fetch(['host' => 'imap.example.org', 'username' => 'u'], null);
				$this->fail('Expected a MailboxTransportException.');
			} catch (MailboxTransportException $exception) {
				$this->assertSame(
					'This host has no imap extension, so an IMAP mailbox cannot be polled here.',
					$exception->getMessage()
				);
			}

			$this->assertSame([], $GLOBALS['__integriq_imap_stub']['calls']);

		}//end testFetchRefusesWhenTheExtensionIsAbsent()

		/**
		 * A missing host or username (blank after trimming) is refused unopened.
		 *
		 * @return void
		 */
		public function testFetchRefusesAMailboxWithoutHostOrUsername(): void {
			foreach ([['username' => 'u'], ['host' => '  ', 'username' => 'u'], ['host' => 'imap.example.org', 'username' => ' ']] as $configuration) {
				try {
					$this->transport()->fetch($configuration, null);
					$this->fail('Expected a MailboxTransportException.');
				} catch (MailboxTransportException $exception) {
					$this->assertSame('An IMAP mailbox needs a host and a username.', $exception->getMessage());
				}
			}

			$this->assertSame([], $this->calls('open'));

		}//end testFetchRefusesAMailboxWithoutHostOrUsername()

		/**
		 * A refused connection becomes a transport exception, and nothing is closed.
		 *
		 * @return void
		 */
		public function testFetchReportsARefusedConnection(): void {
			$GLOBALS['__integriq_imap_stub']['open'] = false;

			try {
				$this->transport()->fetch(['host' => 'imap.example.org', 'username' => 'u', 'password' => 'p'], null);
				$this->fail('Expected a MailboxTransportException.');
			} catch (MailboxTransportException $exception) {
				$this->assertSame('The IMAP server refused the connection or the credentials.', $exception->getMessage());
			}

			$this->assertCount(1, $this->calls('open'));
			$this->assertSame([], $this->calls('close'));

		}//end testFetchReportsARefusedConnection()

		/**
		 * The defaults give an SSL connection on port 993 to INBOX, with the
		 * trimmed host and username and the untrimmed password, one retry.
		 *
		 * @return void
		 */
		public function testDefaultsBuildAnSslInboxMailboxString(): void {
			$GLOBALS['__integriq_imap_stub']['open'] = 'conn';

			$this->transport()->fetch(['host' => ' imap.example.org ', 'username' => ' user ', 'password' => ' secret '], null);

			$this->assertSame(
				[['open', '{imap.example.org:993/imap/ssl}INBOX', 'user', ' secret ', 0, 1]],
				$this->calls('open')
			);

		}//end testDefaultsBuildAnSslInboxMailboxString()

		/**
		 * Encryption, port, folder and certificate validation shape the flags.
		 *
		 * @return void
		 */
		public function testConfigurationShapesTheMailboxString(): void {
			$GLOBALS['__integriq_imap_stub']['open'] = 'conn';
			$base = ['host' => 'mail.example.org', 'username' => 'u'];

			$this->transport()->fetch($base + ['port' => '143', 'encryption' => 'TLS', 'folder' => ' Zaken '], null);
			$this->transport()->fetch($base + ['encryption' => 'none', 'validateCert' => false], null);
			$this->transport()->fetch($base + ['validateCert' => 'false'], null);

			$this->assertSame(
				[
					'{mail.example.org:143/imap/tls}Zaken',
					'{mail.example.org:993/imap/notls/novalidate-cert}INBOX',
					'{mail.example.org:993/imap/ssl}INBOX',
				],
				array_map(static fn (array $call): string => $call[1], $this->calls('open'))
			);

		}//end testConfigurationShapesTheMailboxString()

		/**
		 * No cursor, a blank cursor or an unparsable one searches ALL; a parsable
		 * cursor searches SINCE its date.
		 *
		 * @return void
		 */
		public function testTheCursorSelectsTheSearchCriteria(): void {
			$GLOBALS['__integriq_imap_stub']['open'] = 'conn';
			$configuration = ['host' => 'imap.example.org', 'username' => 'u'];

			$this->transport()->fetch($configuration, null);
			$this->transport()->fetch($configuration, '   ');
			$this->transport()->fetch($configuration, 'not a date at all');
			$this->transport()->fetch($configuration, '2026-09-15T12:00:00+00:00');

			$criteria = array_map(static fn (array $call): string => $call[2], $this->calls('search'));

			$this->assertSame(['ALL', 'ALL', 'ALL'], array_slice($criteria, 0, 3));
			$this->assertMatchesRegularExpression('/^SINCE "1[456]-Sep-2026"$/', $criteria[3]);

		}//end testTheCursorSelectsTheSearchCriteria()

		/**
		 * A failed search yields no messages and the connection is still closed.
		 *
		 * @return void
		 */
		public function testAFailedSearchYieldsNothingAndCloses(): void {
			$GLOBALS['__integriq_imap_stub']['open'] = 'conn';
			$GLOBALS['__integriq_imap_stub']['search'] = false;

			$messages = $this->transport()->fetch(['host' => 'imap.example.org', 'username' => 'u'], null);

			$this->assertSame([], $messages);
			$this->assertSame([['close', 'conn']], $this->calls('close'));

		}//end testAFailedSearchYieldsNothingAndCloses()

		/**
		 * Each found message is fetched, joined header-and-body and parsed; a
		 * message with neither header nor body is skipped.
		 *
		 * @return void
		 */
		public function testFoundMessagesAreFetchedAndParsed(): void {
			$GLOBALS['__integriq_imap_stub']['open'] = 'conn';
			$GLOBALS['__integriq_imap_stub']['search'] = [3, 7, 9];
			$GLOBALS['__integriq_imap_stub']['headers'] = [
				3 => "From: burger@example.org\r\nTo: zaken@gemeente.example\r\nSubject: Vraag ZAAK-2026-0042\r\nMessage-ID: <a@example.org>\r\n",
				9 => "From: ander@example.org\r\nSubject: Tweede\r\nMessage-ID: <b@example.org>\r\n",
			];
			$GLOBALS['__integriq_imap_stub']['bodies'] = [
				3 => "Goedemiddag\r\n",
				9 => "Nog een\r\n",
			];

			$messages = $this->transport()->fetch(['host' => 'imap.example.org', 'username' => 'u'], null);

			$this->assertCount(2, $messages);
			$this->assertSame('burger@example.org', $messages[0]->getFrom());
			$this->assertSame('Vraag ZAAK-2026-0042', $messages[0]->getSubject());
			$this->assertStringContainsString('Goedemiddag', $messages[0]->getBodyText());
			$this->assertSame('Tweede', $messages[1]->getSubject());
			$this->assertSame(
				[['fetchheader', 'conn', 3], ['fetchheader', 'conn', 7], ['fetchheader', 'conn', 9]],
				$this->calls('fetchheader')
			);
			$this->assertSame([['close', 'conn']], $this->calls('close'));

		}//end testFoundMessagesAreFetchedAndParsed()

		/**
		 * An error while reading is wrapped in a transport exception and the
		 * connection is closed anyway.
		 *
		 * @return void
		 */
		public function testAReadErrorIsWrappedAndTheConnectionClosed(): void {
			$GLOBALS['__integriq_imap_stub']['open'] = 'conn';
			$GLOBALS['__integriq_imap_stub']['searchThrows'] = new RuntimeException('server went away');

			try {
				$this->transport()->fetch(['host' => 'imap.example.org', 'username' => 'u'], null);
				$this->fail('Expected a MailboxTransportException.');
			} catch (MailboxTransportException $exception) {
				$this->assertSame('The IMAP mailbox could not be read: server went away', $exception->getMessage());
			}

			$this->assertSame([['close', 'conn']], $this->calls('close'));

		}//end testAReadErrorIsWrappedAndTheConnectionClosed()
	}//end class
}//end namespace
