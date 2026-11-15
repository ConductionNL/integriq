<?php

/**
 * Characterisation test for MessageComposer::compose.
 *
 * Pins the composed body, the quoting level, the unsubscribe link and the
 * headers for every quoting level, signature, history shape, undecided and
 * decided send, and channel.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Outbound\Identity\MessageComposer;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use PHPUnit\Framework\TestCase;

/**
 * The composed messages, recorded before compose() was split into steps.
 */
class MessageComposerGoldenTest extends TestCase {

	/**
	 * The record.
	 *
	 * @var string
	 */
	private const GOLDEN = '{"composed":{"default level, no signature":{"body":"Hallo\\n\\nOp 2026-01-02 schreef Piet:\\n> Tweede","quotingLevel":"last-message","unsubscribeLink":null,"headers":[]},"none":{"body":"Hallo\\n\\n-- \\nTeam","quotingLevel":"none","unsubscribeLink":null,"headers":[]},"full history":{"body":"Hallo\\n\\nOp 2026-01-01 schreef Jan:\\n> Eerste\\n> regel twee\\n\\nOp 2026-01-02 schreef Piet:\\n> Tweede","quotingLevel":"full-history","unsubscribeLink":null,"headers":[]},"all is not a level":{"body":"Hallo\\n\\nOp 2026-01-02 schreef Piet:\\n> Tweede","quotingLevel":"last-message","unsubscribeLink":null,"headers":[]},"unknown level":{"body":"Hallo\\n\\nOp 2026-01-02 schreef Piet:\\n> Tweede","quotingLevel":"last-message","unsubscribeLink":null,"headers":[]},"empty history":{"body":"Hallo","quotingLevel":"full-history","unsubscribeLink":null,"headers":[]},"case link":{"body":"Hallo\\n\\nGeen updates meer over deze zaak ontvangen: https:\\/\\/x.test\\/u\\/Z-1","quotingLevel":"last-message","unsubscribeLink":"https:\\/\\/x.test\\/u\\/Z-1","headers":[]},"no case link":{"body":"Hallo","quotingLevel":"last-message","unsubscribeLink":null,"headers":[]},"no caseRef":{"body":"Hallo","quotingLevel":"last-message","unsubscribeLink":null,"headers":[]},"no recipient":{"body":"Hallo","quotingLevel":"last-message","unsubscribeLink":null,"headers":[]},"decided without decision array":{"body":"Hallo","quotingLevel":"last-message","unsubscribeLink":null,"headers":[]},"decided without material":{"body":"Hallo","quotingLevel":"last-message","unsubscribeLink":null,"headers":[]},"decided email":{"body":"Hallo\\n\\nGeen berichten meer ontvangen: https:\\/\\/x.test\\/s\\/abc","quotingLevel":"last-message","unsubscribeLink":"https:\\/\\/x.test\\/s\\/abc","headers":{"List-Unsubscribe":"<https:\\/\\/x.test\\/s\\/abc>"}},"decided email in a case":{"body":"Hallo\\n\\nGeen updates meer over deze zaak ontvangen: https:\\/\\/x.test\\/s\\/abc","quotingLevel":"last-message","unsubscribeLink":"https:\\/\\/x.test\\/s\\/abc","headers":{"List-Unsubscribe":"<https:\\/\\/x.test\\/s\\/abc>"}},"decided email without headers":{"body":"Hallo\\n\\nGeen berichten meer ontvangen: u","quotingLevel":"last-message","unsubscribeLink":"u","headers":[]},"decided sms":{"body":"Hallo\\nSTOP: x.test\\/s\\/abc","quotingLevel":"last-message","unsubscribeLink":"https:\\/\\/x.test\\/s\\/abc","headers":[]},"decided sms without text":{"body":"Hallo","quotingLevel":"last-message","unsubscribeLink":"u","headers":[]},"decided whatsapp":{"body":"Hallo\\nSTOP: x.test\\/s\\/abc","quotingLevel":"last-message","unsubscribeLink":"https:\\/\\/x.test\\/s\\/abc","headers":[]},"decided other channel":{"body":"Hallo\\n\\nGeen berichten meer ontvangen: https:\\/\\/x.test\\/s\\/abc","quotingLevel":"last-message","unsubscribeLink":"https:\\/\\/x.test\\/s\\/abc","headers":[]},"decided no channel":{"body":"Hallo\\n\\nGeen berichten meer ontvangen: ","quotingLevel":"last-message","unsubscribeLink":"","headers":[]}},"links":[["jan@example.nl","Z-1","case-update","https:\\/\\/b.test"],["nolink@example.nl","Z-2","",""]]}';

	/**
	 * GOLDEN: every composition case.
	 *
	 * @return void
	 */
	public function testGoldenCompositions(): void {
		$links = [];
		$tokens = $this->createMock(UnsubscribeTokenService::class);
		$tokens->method('linkFor')->willReturnCallback(
			function (string $address, string $caseRef, string $category, string $baseUrl = '') use (&$links): ?string {
				$links[] = [$address, $caseRef, $category, $baseUrl];
				return ($address === 'nolink@example.nl') ? null : 'https://x.test/u/' . $caseRef;
			}
		);
		$composer = new MessageComposer($tokens);

		$history = [
			['at' => '2026-01-01', 'from' => 'Jan', 'text' => "Eerste\nregel twee"],
			'not an entry',
			['at' => '2026-01-02', 'from' => 'Piet', 'text' => 'Tweede'],
		];
		$material = ['url' => 'https://x.test/s/abc', 'smsText' => 'STOP: x.test/s/abc', 'headers' => ['List-Unsubscribe' => '<https://x.test/s/abc>']];
		$cases = [
			'default level, no signature' => [[], "Hallo  \n", $history, []],
			'none' => [['quotingLevel' => 'none', 'signature' => ' Team '], 'Hallo', $history, []],
			'full history' => [['quotingLevel' => 'full-history'], 'Hallo', $history, []],
			'all is not a level' => [['quotingLevel' => 'all'], 'Hallo', $history, []],
			'unknown level' => [['quotingLevel' => 'alles'], 'Hallo', $history, []],
			'empty history' => [['quotingLevel' => 'full-history', 'signature' => ''], 'Hallo', [], []],
			'case link' => [[], 'Hallo', [], ['recipient' => ' jan@example.nl ', 'caseRef' => ' Z-1 ', 'category' => 'case-update', 'baseUrl' => 'https://b.test']],
			'no case link' => [[], 'Hallo', [], ['recipient' => 'nolink@example.nl', 'caseRef' => 'Z-2']],
			'no caseRef' => [[], 'Hallo', [], ['recipient' => 'jan@example.nl']],
			'no recipient' => [[], 'Hallo', [], ['caseRef' => 'Z-3']],
			'decided without decision array' => [[], 'Hallo', [], ['decision' => 'allowed', 'channel' => 'email']],
			'decided without material' => [[], 'Hallo', [], ['decision' => ['send' => true, 'unsubscribe' => null], 'channel' => 'email']],
			'decided email' => [[], 'Hallo', [], ['decision' => ['unsubscribe' => $material], 'channel' => 'email']],
			'decided email in a case' => [[], 'Hallo', [], ['decision' => ['unsubscribe' => $material], 'channel' => 'email', 'caseRef' => 'Z-4']],
			'decided email without headers' => [[], 'Hallo', [], ['decision' => ['unsubscribe' => ['url' => 'u', 'headers' => 'x']], 'channel' => 'email']],
			'decided sms' => [[], 'Hallo', [], ['decision' => ['unsubscribe' => $material], 'channel' => 'sms']],
			'decided sms without text' => [[], 'Hallo', [], ['decision' => ['unsubscribe' => ['url' => 'u']], 'channel' => 'sms']],
			'decided whatsapp' => [[], 'Hallo', [], ['decision' => ['unsubscribe' => $material], 'channel' => 'whatsapp']],
			'decided other channel' => [[], 'Hallo', [], ['decision' => ['unsubscribe' => $material], 'channel' => 'digital-post', 'caseRef' => ' ']],
			'decided no channel' => [[], 'Hallo', [], ['decision' => ['unsubscribe' => []]]],
		];

		$composed = [];
		foreach ($cases as $name => [$identity, $body, $caseHistory, $options]) {
			$composed[$name] = $composer->compose($identity, $body, $caseHistory, $options);
		}

		$this->assertSame(self::GOLDEN, json_encode(['composed' => $composed, 'links' => $links]));
	}//end testGoldenCompositions()
}//end class
