<?php

/**
 * OptOutDecisionGoldenTest.
 *
 * Pins every decision OptOutRegistry gives, as the full decision array and
 * the log rows it writes: an unusable address, a reply, an exempt category
 * with and without an opt-out, an opt-out, a missing consent, an allowed send
 * with its unsubscribe material, a probe, and the own-sender paths with the
 * check switched off and with an unreadable list.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
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
 * @spec openspec/changes/opt-out-before-send/specs/outbound-opt-out-authority/spec.md#requirement-sibling-apps-ask-through-a-public-decision-event-req-ooa-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Db\OptOutLogEntry;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Tests\Helpers\OptOutFixture;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The decisions and log rows, recorded before the decision builders were reshaped.
 */
class OptOutDecisionGoldenTest extends TestCase {

	/**
	 * The record.
	 *
	 * @var string
	 */
	private const GOLDEN = '{"batch":{"decisions":{"not an address":{"send":false,"overridden":false,"code":"invalid-address","reason":"This address cannot be used on the \\"email\\" channel.","unsubscribe":null,"address":"","category":"marketing"},"out@x.nl":{"send":false,"overridden":false,"code":"opted-out","reason":"This address opted out (channel).","unsubscribe":null,"address":"out@x.nl","category":"marketing"},"free@x.nl":{"send":false,"overridden":false,"code":"no-consent","reason":"No recorded consent permits this message.","unsubscribe":null,"address":"free@x.nl","category":"marketing"},"in@x.nl":{"send":true,"overridden":false,"code":"allowed","reason":"","unsubscribe":{"url":"https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiaW5AeC5ubCIsInMiOiJjYXNlIiwiY2giOiJlbWFpbCIsInIiOiJaLTkiLCJlIjoxODIxNTM2MDAwLCJwIjoibWFya2V0aW5nIn0.fdc26ab5300b86b53a46602242af145ea793f70166cdaea747dd8b20664024b2","oneClickUrl":"https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiaW5AeC5ubCIsInMiOiJjYXNlIiwiY2giOiJlbWFpbCIsInIiOiJaLTkiLCJlIjoxODIxNTM2MDAwLCJwIjoibWFya2V0aW5nIn0.fdc26ab5300b86b53a46602242af145ea793f70166cdaea747dd8b20664024b2","smsText":null,"headers":{"List-Unsubscribe":"<https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiaW5AeC5ubCIsInMiOiJjYXNlIiwiY2giOiJlbWFpbCIsInIiOiJaLTkiLCJlIjoxODIxNTM2MDAwLCJwIjoibWFya2V0aW5nIn0.fdc26ab5300b86b53a46602242af145ea793f70166cdaea747dd8b20664024b2>","List-Unsubscribe-Post":"List-Unsubscribe=One-Click"}},"address":"in@x.nl","category":"marketing"}},"log":[["suppressed","","marketing","email","dossiq","corr-1","{\\"code\\":\\"invalid-address\\"}",1790000000],["suppressed","out@x.nl","marketing","email","dossiq","corr-1","{\\"code\\":\\"opted-out\\",\\"scope\\":\\"channel\\"}",1790000000],["suppressed","free@x.nl","marketing","email","dossiq","corr-1","{\\"code\\":\\"no-consent\\"}",1790000000],["allowed-count","","marketing","email","dossiq","corr-1","{\\"count\\":2}",1790000000]]},"exempt":{"decisions":{"out@x.nl":{"send":true,"overridden":true,"code":"exempt-override","reason":"Category \\"besluit\\" cannot be stopped by an opt-out.","unsubscribe":null,"address":"out@x.nl","category":"besluit"},"free@x.nl":{"send":true,"overridden":false,"code":"allowed","reason":"","unsubscribe":null,"address":"free@x.nl","category":"besluit"}},"log":[["override","out@x.nl","besluit","email","dossiq","corr-2","{\\"scope\\":\\"channel\\",\\"optOutId\\":1}",1790000000],["allowed-count","","besluit","email","dossiq","corr-2","{\\"count\\":1}",1790000000]]},"reply":{"decisions":{"out@x.nl":{"send":true,"overridden":false,"code":"allowed","reason":"A direct reply is sent whatever the opt-outs say.","unsubscribe":null,"address":"out@x.nl","category":"reply"}},"log":[["allowed-count","","reply","email","dossiq","corr-3","{\\"count\\":1}",1790000000]]},"service":{"decisions":{"free@x.nl":{"send":true,"overridden":false,"code":"allowed","reason":"","unsubscribe":{"url":"https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiZnJlZUB4Lm5sIiwicyI6Imxpc3QiLCJjaCI6ImVtYWlsIiwiciI6Im5ld3MiLCJlIjoxODIxNTM2MDAwLCJwIjoic2VydmljZSJ9.0a60a61dc5dac3b44b08270c2ab13fff34c94ad8aa9ba4a465de8bfea07c6731","oneClickUrl":"https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiZnJlZUB4Lm5sIiwicyI6Imxpc3QiLCJjaCI6ImVtYWlsIiwiciI6Im5ld3MiLCJlIjoxODIxNTM2MDAwLCJwIjoic2VydmljZSJ9.0a60a61dc5dac3b44b08270c2ab13fff34c94ad8aa9ba4a465de8bfea07c6731","smsText":null,"headers":{"List-Unsubscribe":"<https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiZnJlZUB4Lm5sIiwicyI6Imxpc3QiLCJjaCI6ImVtYWlsIiwiciI6Im5ld3MiLCJlIjoxODIxNTM2MDAwLCJwIjoic2VydmljZSJ9.0a60a61dc5dac3b44b08270c2ab13fff34c94ad8aa9ba4a465de8bfea07c6731>","List-Unsubscribe-Post":"List-Unsubscribe=One-Click"}},"address":"free@x.nl","category":"service"}},"log":[["allowed-count","","service","email","pipelinq","corr-4","{\\"count\\":2}",1790000000]]},"probe":{"decisions":{"out@x.nl":{"send":false,"overridden":false,"code":"opted-out","reason":"This address opted out (channel).","unsubscribe":null,"address":"out@x.nl","category":"marketing"},"free@x.nl":{"send":false,"overridden":false,"code":"no-consent","reason":"No recorded consent permits this message.","unsubscribe":null,"address":"free@x.nl","category":"marketing"},"in@x.nl":{"send":true,"overridden":false,"code":"allowed","reason":"","unsubscribe":null,"address":"in@x.nl","category":"marketing"}},"log":[]},"own send":{"decisions":{"out@x.nl":{"send":false,"overridden":false,"code":"opted-out","reason":"This address opted out (channel).","unsubscribe":null,"address":"out@x.nl","category":"case-update"},"free@x.nl":{"send":true,"overridden":false,"code":"allowed","reason":"","unsubscribe":{"url":"https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiZnJlZUB4Lm5sIiwicyI6ImNoYW5uZWwiLCJjaCI6ImVtYWlsIiwiciI6IiIsImUiOjE4MjE1MzYwMDAsInAiOiJzZXJ2aWNlIn0.9adbf19ba44cd2b306934a80b5fadc3641ba3e25c4075ad6bb55f2e8c1bde11e","oneClickUrl":"https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiZnJlZUB4Lm5sIiwicyI6ImNoYW5uZWwiLCJjaCI6ImVtYWlsIiwiciI6IiIsImUiOjE4MjE1MzYwMDAsInAiOiJzZXJ2aWNlIn0.9adbf19ba44cd2b306934a80b5fadc3641ba3e25c4075ad6bb55f2e8c1bde11e","smsText":null,"headers":{"List-Unsubscribe":"<https:\\/\\/gem.nl\\/index.php\\/apps\\/integriq\\/unsubscribe\\/v3.eyJhIjoiZnJlZUB4Lm5sIiwicyI6ImNoYW5uZWwiLCJjaCI6ImVtYWlsIiwiciI6IiIsImUiOjE4MjE1MzYwMDAsInAiOiJzZXJ2aWNlIn0.9adbf19ba44cd2b306934a80b5fadc3641ba3e25c4075ad6bb55f2e8c1bde11e>","List-Unsubscribe-Post":"List-Unsubscribe=One-Click"}},"address":"free@x.nl","category":"case-update"}},"log":[["suppressed","out@x.nl","case-update","email","dossiq","corr-6","{\\"code\\":\\"opted-out\\",\\"scope\\":\\"channel\\"}",1790000000],["allowed-count","","case-update","email","dossiq","corr-6","{\\"count\\":1}",1790000000]]},"own reply":{"decisions":{"out@x.nl":{"send":true,"overridden":false,"code":"allowed","reason":"A direct reply is sent whatever the opt-outs say.","unsubscribe":null,"address":"out@x.nl","category":"reply"}},"log":[["allowed-count","","reply","email","integriq","","{\\"count\\":1}",1790000000]]},"own reply, empty ref":{"decisions":{"out@x.nl":{"send":false,"overridden":false,"code":"opted-out","reason":"This address opted out (channel).","unsubscribe":null,"address":"out@x.nl","category":"service"}},"log":[["suppressed","out@x.nl","service","email","integriq","","{\\"code\\":\\"opted-out\\",\\"scope\\":\\"channel\\"}",1790000000]]},"list unreadable":{"decisions":{"0612345678":{"send":false,"overridden":false,"code":"authority-unavailable","reason":"The opt-out list could not be read, so this message was not sent.","unsubscribe":null,"address":"0612345678","category":"service"},"":{"send":false,"overridden":false,"code":"authority-unavailable","reason":"The opt-out list could not be read, so this message was not sent.","unsubscribe":null,"address":"","category":"service"}},"warnings":["[OptOutRegistry] the opt-out list could not be read; non-exempt sends are refused","[OptOutRegistry] the opt-out list could not be read; non-exempt sends are refused"]},"list unreadable, exempt":{"decisions":{"0612345678":{"send":true,"overridden":false,"code":"allowed","reason":"Category \\"besluit\\" is sent without the opt-out list.","unsubscribe":null,"address":"0612345678","category":"besluit"}}},"switched off":{"decisions":{"out@x.nl":{"send":true,"overridden":false,"code":"allowed","reason":"The opt-out check is turned off on this instance (outbound.optout_authority).","unsubscribe":null,"address":"out@x.nl","category":"marketing"},"":{"send":true,"overridden":false,"code":"allowed","reason":"The opt-out check is turned off on this instance (outbound.optout_authority).","unsubscribe":null,"address":"","category":"marketing"}},"log":[]}}';

	/**
	 * GOLDEN: every decision path, with the log rows each writes.
	 *
	 * @return void
	 */
	public function testGoldenDecisionsAndLogRows(): void {
		$record = [];
		$fx = new OptOutFixture($this, $this->createMock(IDBConnection::class));
		$registry = $fx->registry();
		$registry->record(['address' => 'out@x.nl', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'email', 'sourceApp' => 'pipelinq']);
		$registry->record(['address' => 'in@x.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent', 'sourceApp' => 'pipelinq']);
		$fx->log->rows = [];

		$batches = [
			'batch' => ['email', 'marketing', true, [['address' => 'not an address'], ['address' => 'out@x.nl'], ['address' => 'free@x.nl'], ['address' => 'in@x.nl', 'listRef' => ''], ['address' => 'in@x.nl', 'caseRef' => 'Z-9']], 'dossiq', 'corr-1', 'https://gem.nl/', null, false],
			'exempt' => ['email', 'besluit', false, [['address' => 'out@x.nl'], ['address' => 'free@x.nl']], 'dossiq', 'corr-2', '', null, false],
			'reply' => ['email', 'reply', false, [['address' => 'out@x.nl']], 'dossiq', 'corr-3', '', 'msg-1', false],
			'service' => ['email', 'service', false, [['address' => 'free@x.nl', 'caseRef' => 'Z-1'], ['address' => 'free@x.nl', 'listRef' => 'news']], 'pipelinq', 'corr-4', 'https://gem.nl/', null, false],
			'probe' => ['email', 'marketing', true, [['address' => 'out@x.nl'], ['address' => 'free@x.nl'], ['address' => 'in@x.nl']], 'pipelinq', 'corr-5', 'https://gem.nl/', null, true],
		];
		foreach ($batches as $name => [$channel, $category, $consent, $recipients, $sourceApp, $correlationId, $baseUrl, $inReplyTo, $probe]) {
			$record[$name] = ['decisions' => $registry->decideMany($channel, $category, $consent, $recipients, $sourceApp, $correlationId, $baseUrl, $inReplyTo, $probe)];
			$record[$name]['log'] = $this->logRows(fx: $fx);
		}

		$record['own send'] = ['decisions' => $registry->decideForSend('email', 'case-update', [['address' => 'out@x.nl'], ['address' => 'free@x.nl']], ['sourceApp' => 'dossiq', 'correlationId' => 'corr-6', 'baseUrl' => 'https://gem.nl/', 'inReplyTo' => ' '])];
		$record['own send']['log'] = $this->logRows(fx: $fx);
		$record['own reply'] = ['decisions' => $registry->decideForSend('email', 'reply', [['address' => 'out@x.nl']], ['inReplyTo' => 'msg-2', 'requiresConsent' => 1])];
		$record['own reply']['log'] = $this->logRows(fx: $fx);
		$record['own reply, empty ref'] = ['decisions' => $registry->decideForSend('email', 'reply', [['address' => 'out@x.nl']], ['inReplyTo' => ''])];
		$record['own reply, empty ref']['log'] = $this->logRows(fx: $fx);

		$fx->table->failReads = true;
		$record['list unreadable'] = ['decisions' => $registry->decideForSend('sms', 'service', [['address' => '0612345678'], ['nope' => 1]], ['sourceApp' => 'pipelinq'])];
		$record['list unreadable, exempt'] = ['decisions' => $registry->decideForSend('sms', 'besluit', [['address' => '0612345678']], [])];
		$record['list unreadable']['warnings'] = $fx->warnings;
		$fx->table->failReads = false;

		$fx->config[OptOutRegistry::CONFIG_AUTHORITY] = 'false';
		$record['switched off'] = ['decisions' => $registry->decideForSend('email', 'marketing', [['address' => 'out@x.nl'], ['address' => '']], ['sourceApp' => 'pipelinq'])];
		$record['switched off']['log'] = $this->logRows(fx: $fx);

		$this->assertSame(self::GOLDEN, json_encode($record));

	}//end testGoldenDecisionsAndLogRows()

	/**
	 * The log rows written since the last call, as arrays, and forget them.
	 *
	 * @param OptOutFixture $fx The fixture.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function logRows(OptOutFixture $fx): array {
		$rows = array_map(
			static fn (OptOutLogEntry $entry): array => [
				$entry->getKind(),
				$entry->getAddress(),
				$entry->getCategory(),
				$entry->getChannel(),
				$entry->getSourceApp(),
				$entry->getCorrelationId(),
				$entry->getDetail(),
				$entry->getAt(),
			],
			array_values($fx->log->rows)
		);
		$fx->log->rows = [];

		return $rows;

	}//end logRows()
}//end class
