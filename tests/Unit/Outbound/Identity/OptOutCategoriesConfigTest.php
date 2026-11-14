<?php

/**
 * Unit tests for how OptOutCategories reads the configured protected-category
 * aliases: unset, not JSON, and a mix of accepted and ignored values.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the configured aliases of protected categories.
 *
 * @covers \OCA\Integriq\Outbound\Identity\OptOutCategories
 */
class OptOutCategoriesConfigTest extends TestCase {

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Categories over one configured value.
	 *
	 * @param string $configured The raw `outbound.protected_categories` value.
	 *
	 * @return OptOutCategories The categories.
	 */
	private function categories(string $configured): OptOutCategories {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === OptOutCategories::CONFIG_PROTECTED ? $configured : $default)
		);

		return new OptOutCategories($appConfig, $this->logger);

	}//end categories()

	/**
	 * A value that is not JSON is warned about and only the fixed aliases apply.
	 *
	 * @return void
	 */
	public function testAValueThatIsNotJsonKeepsTheDefaultAliases(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'[OptOutCategories] outbound.protected_categories is not JSON; the fixed floor applies',
			['value' => '{broken']
		);

		$this->assertSame(OptOutCategories::DEFAULT_ALIASES, $this->categories('  {broken ')->aliases());

	}//end testAValueThatIsNotJsonKeepsTheDefaultAliases()

	/**
	 * An empty value is silent and only the fixed aliases apply.
	 *
	 * @return void
	 */
	public function testAnEmptyValueKeepsTheDefaultAliasesSilently(): void {
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(OptOutCategories::DEFAULT_ALIASES, $this->categories('   ')->aliases());

	}//end testAnEmptyValueKeepsTheDefaultAliasesSilently()

	/**
	 * A named alias of a floor category is added; an alias of anything else is ignored with a warning.
	 *
	 * @return void
	 */
	public function testOnlyAliasesOfTheFloorAreAccepted(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			'[OptOutCategories] ignored a value in outbound.protected_categories: only aliases of besluit, statutory, account and security are accepted',
			['value' => 'newsletter', 'key' => 'Nieuwsbrief']
		);

		$aliases = $this->categories('{" Beschikking ":" Besluit ","Nieuwsbrief":"newsletter"}')->aliases();

		$this->assertSame('besluit', $aliases['beschikking']);
		$this->assertArrayNotHasKey('nieuwsbrief', $aliases);
		$this->assertArrayNotHasKey('Nieuwsbrief', $aliases);
		$this->assertTrue($this->categories('{"beschikking":"besluit"}')->isExempt('beschikking'));

	}//end testOnlyAliasesOfTheFloorAreAccepted()

	/**
	 * A listed floor category is accepted silently; a listed unknown one is warned about.
	 *
	 * @return void
	 */
	public function testAListedUnknownCategoryIsWarnedAbout(): void {
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('ignored a value'),
			['value' => 'marketing', 'key' => 1]
		);

		$aliases = $this->categories('["security","marketing"]')->aliases();

		$this->assertSame(OptOutCategories::DEFAULT_ALIASES, $aliases);

	}//end testAListedUnknownCategoryIsWarnedAbout()

}//end class
