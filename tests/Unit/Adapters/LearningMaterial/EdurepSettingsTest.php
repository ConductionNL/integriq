<?php

/**
 * Unit tests for the Edurep settings stored in the app config.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Adapters\LearningMaterial
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Adapters\LearningMaterial;

use OCA\Integriq\Adapters\LearningMaterial\EdurepSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading defaults, reading stored values and saving only valid keys.
 */
#[CoversClass(EdurepSettings::class)]
class EdurepSettingsTest extends TestCase {
	/**
	 * @var array<string,string>
	 */
	private array $config = [];

	/**
	 * @var list<array{0:string,1:string,2:string}>
	 */
	private array $writes = [];

	/**
	 * @return EdurepSettings
	 */
	private function settings(): EdurepSettings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->writes[] = [$app, $key, $value];
			return true;
		});

		return new EdurepSettings(config: $appConfig);
	}//end settings()

	/**
	 * @return void
	 */
	public function testDefaultsWhenNothingIsStored(): void {
		$this->assertSame(
			[
				'enabled' => true,
				'disabledCollections' => ['KlasCement'],
				'language' => 'nl',
				'termsUrl' => 'https://www.kennisnet.nl/diensten/edurep/',
			],
			$this->settings()->toArray()
		);
	}//end testDefaultsWhenNothingIsStored()

	/**
	 * @return void
	 */
	public function testZeroAndFalseDisableTheSource(): void {
		$this->config['edurep.enabled'] = '0';
		$this->assertFalse($this->settings()->isEnabled());
		$this->config['edurep.enabled'] = 'false';
		$this->assertFalse($this->settings()->isEnabled());
		$this->config['edurep.enabled'] = 'no';
		$this->assertTrue($this->settings()->isEnabled());
	}//end testZeroAndFalseDisableTheSource()

	/**
	 * @return void
	 */
	public function testStoredCollectionsKeepOnlyStrings(): void {
		$this->config['edurep.disabled_collections'] = '{"a":"Schooltv","b":3,"c":"KlasCement"}';
		$this->assertSame(['Schooltv', 'KlasCement'], $this->settings()->disabledCollections());
	}//end testStoredCollectionsKeepOnlyStrings()

	/**
	 * @return void
	 */
	public function testAnEmptyStoredListDisablesNothing(): void {
		$this->config['edurep.disabled_collections'] = '[]';
		$this->assertSame([], $this->settings()->disabledCollections());
	}//end testAnEmptyStoredListDisablesNothing()

	/**
	 * @return void
	 */
	public function testUnreadableStoredCollectionsFallBackToTheDefault(): void {
		$this->config['edurep.disabled_collections'] = 'not json';
		$this->assertSame(['KlasCement'], $this->settings()->disabledCollections());
	}//end testUnreadableStoredCollectionsFallBackToTheDefault()

	/**
	 * @return void
	 */
	public function testSaveWritesEveryValidKey(): void {
		$this->settings()->save(['enabled' => false, 'disabledCollections' => ['Schooltv', 7, 'KlasCement'], 'language' => 'en']);

		$this->assertSame(
			[
				['integriq', 'edurep.enabled', '0'],
				['integriq', 'edurep.disabled_collections', '["Schooltv","KlasCement"]'],
				['integriq', 'edurep.language', 'en'],
			],
			$this->writes
		);
	}//end testSaveWritesEveryValidKey()

	/**
	 * @return void
	 */
	public function testSaveSkipsMissingAndInvalidValues(): void {
		$this->settings()->save(['disabledCollections' => 'Schooltv', 'language' => 'Nederlands']);
		$this->assertSame([], $this->writes);
	}//end testSaveSkipsMissingAndInvalidValues()

	/**
	 * @return void
	 */
	public function testAnEmptyLanguageIsSavedAsNoFilter(): void {
		$this->settings()->save(['enabled' => 1, 'language' => '']);
		$this->assertSame([['integriq', 'edurep.enabled', '1'], ['integriq', 'edurep.language', '']], $this->writes);
	}//end testAnEmptyLanguageIsSavedAsNoFilter()
}//end class
