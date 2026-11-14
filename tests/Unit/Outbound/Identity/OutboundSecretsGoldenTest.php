<?php

/**
 * The recipient-key and unsubscribe secrets are read, minted and kept the same way.
 *
 * Pins, for both RecipientKey and UnsubscribeTokenService, that a stored
 * secret is used as is, that a blank or whitespace one is minted once (64
 * alphanumeric characters) and stored as a sensitive lazy value under the
 * class's own key, and that the secret is read only once per instance, so the
 * shared secret loader can move without changing any of it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Db\UnsubscribeShortLinkMapperInterface;
use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCA\Integriq\Outbound\Identity\RecipientKey;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCA\Integriq\Service\Sms\PhoneNumberNormaliserInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for the outbound identity secrets.
 */
final class OutboundSecretsGoldenTest extends TestCase {

	/**
	 * Both owners with their config key.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function owners(): array {
		return [
			'recipient key' => ['recipient', 'outbound.recipient_key_secret'],
			'unsubscribe' => ['unsubscribe', 'outbound.unsubscribe_secret'],
		];

	}//end owners()

	/**
	 * Build one owner over the given config and random source.
	 *
	 * @param string        $owner     `recipient` or `unsubscribe`.
	 * @param IAppConfig    $appConfig The app config.
	 * @param ISecureRandom $random    The random source.
	 *
	 * @return object
	 */
	private function owner(string $owner, IAppConfig $appConfig, ISecureRandom $random): object {
		if ($owner === 'recipient') {
			return new RecipientKey(appConfig: $appConfig, random: $random, phoneNumbers: $this->createMock(PhoneNumberNormaliserInterface::class));
		}

		return new UnsubscribeTokenService(
			appConfig: $appConfig,
			random: $random,
			categories: $this->createMock(OptOutCategories::class),
			time: $this->createMock(ITimeFactory::class),
			shortLinks: $this->createMock(UnsubscribeShortLinkMapperInterface::class),
			urls: $this->createMock(IURLGenerator::class),
			logger: $this->createMock(LoggerInterface::class)
		);

	}//end owner()

	/**
	 * Read the owner's secret twice.
	 *
	 * @param object $owner The owner.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function readTwice(object $owner): array {
		return (fn (): array => [$this->secret(), $this->secret()])->call($owner);

	}//end readTwice()

	/**
	 * A stored secret is used as is, read once, and never minted.
	 *
	 * @param string $owner The owner.
	 * @param string $key   Its config key.
	 *
	 * @return void
	 */
	#[DataProvider('owners')]
	public function testAStoredSecretIsUsed(string $owner, string $key): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('getValueString')->with('integriq', $key, '')->willReturn(' kept ');
		$appConfig->expects($this->never())->method('setValueString');
		$random = $this->createMock(ISecureRandom::class);
		$random->expects($this->never())->method('generate');

		$this->assertSame([' kept ', ' kept '], $this->readTwice(owner: $this->owner($owner, $appConfig, $random)));

	}//end testAStoredSecretIsUsed()

	/**
	 * A blank or whitespace secret is minted once and stored sensitive and lazy.
	 *
	 * @param string $owner The owner.
	 * @param string $key   Its config key.
	 *
	 * @return void
	 */
	#[DataProvider('owners')]
	public function testABlankSecretIsMintedAndStored(string $owner, string $key): void {
		foreach (['', "  \t"] as $stored) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->expects($this->once())->method('getValueString')->with('integriq', $key, '')->willReturn($stored);
			$appConfig->expects($this->once())->method('setValueString')->with('integriq', $key, 'minted', false, true);
			$random = $this->createMock(ISecureRandom::class);
			$random->expects($this->once())->method('generate')->with(64, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('minted');

			$this->assertSame(['minted', 'minted'], $this->readTwice(owner: $this->owner($owner, $appConfig, $random)));
		}

	}//end testABlankSecretIsMintedAndStored()
}//end class
