<?php

/**
 * Golden test pinning the four DUO/education retour acknowledgement translators.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/rod-adapter/spec.md#requirement-req-003-duo-acknowledgement-and-signaalcode-translation-to-a-typed-event
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Stuf;

use OCA\Integriq\Exception\OsoTranslationException;
use OCA\Integriq\Exception\RodTranslationException;
use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Exception\VerzuimloketTranslationException;
use OCA\Integriq\Service\Oso\OsoAcknowledgementTranslator;
use OCA\Integriq\Service\Rod\RodAcknowledgementTranslator;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVAcknowledgementTranslator;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketAcknowledgementTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every outcome of the four retour acknowledgement translators: the status update,
 * and the exception class and exact message of each refusal.
 */
class RetourAcknowledgementGoldenTest extends TestCase {

	/**
	 * Each translator with the exception class it raises.
	 *
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function translatorProvider(): array {
		return [
			'oso' => [OsoAcknowledgementTranslator::class, OsoTranslationException::class],
			'rod' => [RodAcknowledgementTranslator::class, RodTranslationException::class],
			'uwlr-eduv' => [UwlrEduVAcknowledgementTranslator::class, UwlrEduVTranslationException::class],
			'verzuimloket' => [VerzuimloketAcknowledgementTranslator::class, VerzuimloketTranslationException::class],
		];

	}//end translatorProvider()

	/**
	 * An accepted retour (signaalcode 0) with an omschrijving.
	 *
	 * @param string $translatorClass The translator under test.
	 *
	 * @return void
	 */
	#[DataProvider('translatorProvider')]
	public function testAcceptedRetour(string $translatorClass): void {
		$xml = '<retour><stuurgegevens><kenmerk> k-1 </kenmerk><signaalcode> 0 </signaalcode></stuurgegevens>'
			. '<body><omschrijving> Verwerkt </omschrijving></body></retour>';

		$this->assertSame(
			['kenmerk' => 'k-1', 'signaalcode' => '0', 'signaalOmschrijving' => 'Verwerkt', 'accepted' => true],
			(new $translatorClass())->translate($xml)
		);

	}//end testAcceptedRetour()

	/**
	 * A rejected retour without a body: no omschrijving, accepted false.
	 *
	 * @param string $translatorClass The translator under test.
	 *
	 * @return void
	 */
	#[DataProvider('translatorProvider')]
	public function testRejectedRetourWithoutBody(string $translatorClass): void {
		$xml = '<retour><stuurgegevens><kenmerk>k-2</kenmerk><signaalcode>7</signaalcode></stuurgegevens></retour>';

		$this->assertSame(
			['kenmerk' => 'k-2', 'signaalcode' => '7', 'signaalOmschrijving' => null, 'accepted' => false],
			(new $translatorClass())->translate($xml)
		);

	}//end testRejectedRetourWithoutBody()

	/**
	 * A retour with a blank omschrijving and no signaalcode.
	 *
	 * @param string $translatorClass The translator under test.
	 *
	 * @return void
	 */
	#[DataProvider('translatorProvider')]
	public function testBlankOmschrijvingAndMissingSignaalcode(string $translatorClass): void {
		$xml = '<retour><stuurgegevens><kenmerk>k-3</kenmerk></stuurgegevens><body><omschrijving>  </omschrijving></body></retour>';

		$this->assertSame(
			['kenmerk' => 'k-3', 'signaalcode' => '', 'signaalOmschrijving' => null, 'accepted' => false],
			(new $translatorClass())->translate($xml)
		);

	}//end testBlankOmschrijvingAndMissingSignaalcode()

	/**
	 * The refusals, each with its exact message.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refusalProvider(): array {
		return [
			'empty' => ["  \n", 'Retour envelope is empty.'],
			'malformed' => ['<retour><stuurgegevens>', 'Retour envelope is not well-formed XML.'],
			'no kenmerk' => [
				'<retour><stuurgegevens><kenmerk> </kenmerk><signaalcode>0</signaalcode></stuurgegevens></retour>',
				'Retour envelope is missing stuurgegevens.kenmerk — refusing to resolve an unrelated message.',
			],
		];

	}//end refusalProvider()

	/**
	 * Every translator refuses every bad envelope with its own exception class and the same message.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		foreach (self::translatorProvider() as $name => [$translatorClass, $exceptionClass]) {
			foreach (self::refusalProvider() as $case => [$xml, $message]) {
				try {
					(new $translatorClass())->translate($xml);
					$this->fail($name . ' accepted the ' . $case . ' envelope.');
				} catch (\Exception $exception) {
					$this->assertSame($exceptionClass, get_class($exception), $name . ' ' . $case);
					$this->assertSame($message, $exception->getMessage(), $name . ' ' . $case);
					$this->assertNull($exception->getPrevious(), $name . ' ' . $case);
				}
			}
		}

	}//end testRefusals()
}//end class
