<?php

/**
 * Golden tests for the provider registries' lookups.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Helper;

use OCA\Integriq\Service\DigitalPost\DigitalPostProviderInterface;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Oso\OsoProviderInterface;
use OCA\Integriq\Service\Oso\OsoProviderRegistry;
use OCA\Integriq\Service\Rod\RodProviderInterface;
use OCA\Integriq\Service\Rod\RodProviderRegistry;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderInterface;
use OCA\Integriq\Service\UwlrEduV\UwlrEduVProviderRegistry;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderInterface;
use OCA\Integriq\Service\Verzuimloket\VerzuimloketProviderRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins what every provider registry returns and refuses: the binding for an
 * id, the empty id (log for the education registries), and the refusal
 * wording with no, one and several registered ids.
 */
class ProviderRegistriesGoldenTest extends TestCase {

	/**
	 * The registries with their provider interface, label and empty-id behaviour.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
	 */
	public static function registries(): array {
		return [
			'digital-post' => [DigitalPostProviderRegistry::class, DigitalPostProviderInterface::class, 'digital post', false],
			'oso' => [OsoProviderRegistry::class, OsoProviderInterface::class, 'OSO', true],
			'rod' => [RodProviderRegistry::class, RodProviderInterface::class, 'ROD', true],
			'uwlr' => [UwlrEduVProviderRegistry::class, UwlrEduVProviderInterface::class, 'UWLR/Edu-V', true],
			'verzuimloket' => [VerzuimloketProviderRegistry::class, VerzuimloketProviderInterface::class, 'Verzuimloket', true],
		];

	}//end registries()

	/**
	 * A provider stub answering to an id.
	 *
	 * @param string $interface The provider interface.
	 * @param string $id        The provider id.
	 *
	 * @return object The stub.
	 */
	private function provider(string $interface, string $id): object {
		$provider = $this->createMock($interface);
		$provider->method('getProviderId')->willReturn($id);
		return $provider;

	}//end provider()

	/**
	 * The refusal message of a lookup.
	 *
	 * @param object $registry   The registry.
	 * @param string $providerId The id looked up.
	 *
	 * @return string The message.
	 */
	private function refusal(object $registry, string $providerId): string {
		try {
			$registry->get($providerId);
		} catch (RuntimeException $exception) {
			return $exception::class.': '.$exception->getMessage();
		}

		$this->fail('The lookup did not refuse.');

	}//end refusal()

	/**
	 * Lookups resolve the first binding per id, refuse unknown ids with the known ids listed.
	 *
	 * @param string $class     The registry class.
	 * @param string $interface The provider interface.
	 * @param string $label     The label in the refusal.
	 * @param bool   $emptyIsLog Whether an empty id resolves to `log`.
	 *
	 * @return void
	 */
	#[DataProvider('registries')]
	public function testLookupsAndRefusals(string $class, string $interface, string $label, bool $emptyIsLog): void {
		$log    = $this->provider($interface, 'log');
		$first  = $this->provider($interface, 'remote');
		$second = $this->provider($interface, 'remote');

		$registry = new $class([$log, $first, $second]);
		$this->assertSame($first, $registry->get('remote'));
		$this->assertSame($log, $registry->get('log'));
		$this->assertSame(['log', 'remote'], $registry->ids());
		$this->assertTrue($registry->has('remote'));
		$this->assertFalse($registry->has(''));

		$prefix = 'RuntimeException: No '.$label.' provider is registered under ';
		$this->assertSame($prefix.'"typo". Registered providers: log, remote. Nothing was sent.', $this->refusal($registry, 'typo'));

		$empty = new $class([]);
		$this->assertSame($prefix.'"typo". Registered providers: (none). Nothing was sent.', $this->refusal($empty, 'typo'));

		$single = new $class([$first]);
		if ($emptyIsLog === true) {
			$this->assertSame($log, $registry->get(''));
			$this->assertSame($prefix.'"log". Registered providers: remote. Nothing was sent.', $this->refusal($single, ''));
			return;
		}

		$this->assertSame($prefix.'"". Registered providers: remote. Nothing was sent.', $this->refusal($single, ''));

	}//end testLookupsAndRefusals()
}//end class
