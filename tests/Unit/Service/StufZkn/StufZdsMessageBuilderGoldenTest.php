<?php

/**
 * Golden test for the StUF-ZDS case messages.
 *
 * Pins the whole envelope every builder renders, and the refusals, with the
 * referentienummer and the tijdstipBericht masked.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\StufZkn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-case-message-construction
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\StufZkn;

use OCA\Integriq\Exception\StufZknTranslationException;
use OCA\Integriq\Service\StufZkn\StufZdsMessageBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Every built message matches tests/fixtures/stuf-zds/messages.json.
 */
class StufZdsMessageBuilderGoldenTest extends TestCase {

	/**
	 * The golden file.
	 */
	private const GOLDEN = __DIR__ . '/../../../fixtures/stuf-zds/messages.json';

	/**
	 * Mask the referentienummer and the tijdstipBericht.
	 *
	 * @param array $message The built message, or a refusal.
	 *
	 * @return array|string The masked xml, or the refusal.
	 */
	private function mask(array|string $message): array|string {
		if (is_string($message) === true) {
			return $message;
		}

		$xml = str_replace($message['referentienummer'], 'REF', $message['xml']);
		return (string)preg_replace('#(<stuf:tijdstipBericht>)\d{17}(</)#', '$1TIME$2', $xml);
	}//end mask()

	/**
	 * Run a builder; a refusal becomes its message.
	 *
	 * @param callable $build The builder call.
	 *
	 * @return array|string The message or the refusal.
	 */
	private function attempt(callable $build): array|string {
		try {
			return $build();
		} catch (StufZknTranslationException $exception) {
			return 'refused: ' . $exception->getMessage();
		}
	}//end attempt()

	/**
	 * Every message, rendered by the current code.
	 *
	 * @return array<string,string>
	 */
	private function messages(): array {
		$builder = new StufZdsMessageBuilder();
		$full    = $builder->routing(
			[
				'ontvangerOrganisatie' => '1900',
				'configuration' => ['organisatie' => ' 0363 ', 'ontvangerApplicatie' => 'ZSH', 'ontvangerGebruiker' => 'svc'],
			],
			['username' => 'user&co', 'password' => 'p<w>']
		);
		$bare    = $builder->routing([], null);
		$case    = [
			'omschrijving' => 'Kap & snoei <boom>',
			'startdatum' => '20261001',
			'betrokkenen' => [['bsn' => '999993653', 'role' => 'heeftAlsInitiator'], ['bsn' => '111222333'], ['role' => 'heeftAlsBelanghebbende']],
			'documenten' => [['name' => 'a.pdf', 'mime' => 'application/pdf', 'bytes' => 'PDF'], ['bytes' => 'raw']],
		];

		$messages = [
			'creation-full' => fn () => $builder->caseCreation($case, 'Kapvergunning', $full, 'ZAAK-1', ['includeDocuments' => true]),
			'creation-bare' => fn () => $builder->caseCreation(['betrokkenen' => []], 'Melding', $bare),
			'creation-empty-id' => fn () => $builder->caseCreation($case, 'Melding', $bare, '', ['includeDocuments' => false]),
			'creation-no-zaaktype' => fn () => $builder->caseCreation($case, '  ', $full),
			'creation-over-limit' => fn () => $builder->caseCreation($case, 'Melding', $full, null, ['includeDocuments' => true, 'payloadLimitBytes' => 5]),
			'creation-at-limit' => fn () => $builder->caseCreation($case, 'Melding', $bare, null, ['includeDocuments' => true, 'payloadLimitBytes' => 6]),
			'update' => fn () => $builder->caseUpdate('ZAAK-1', ['einddatum' => '20261101', 'omschrijving' => null, 'other' => 'x', 'resultaattoelichting' => 'ok'], $full),
			'update-empty' => fn () => $builder->caseUpdate('ZAAK-1', [], $bare),
			'details' => fn () => $builder->caseDetailsQuery('ZAAK-1', $full, ['omschrijving', 'heeftAlsInitiator']),
			'details-bare' => fn () => $builder->caseDetailsQuery('ZAAK-1', $bare),
			'identification' => fn () => $builder->caseIdentificationRequest($full),
			'free' => fn () => $builder->freeMessage(['name' => 'meldingBericht', 'verplichteVelden' => ['a']], ['a' => '1', 'b' => '<2>'], $full),
			'free-missing' => fn () => $builder->freeMessage(['name' => 'meldingBericht', 'verplichteVelden' => ['a', 'c']], ['a' => '1'], $full),
		];

		return array_map(fn (callable $build) => $this->mask(message: $this->attempt(build: $build)), $messages);
	}//end messages()

	/**
	 * Every message matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryMessageMatchesTheGoldenFile(): void {
		$actual = $this->messages();
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);
	}//end testEveryMessageMatchesTheGoldenFile()
}//end class
