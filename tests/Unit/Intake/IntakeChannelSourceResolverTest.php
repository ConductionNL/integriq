<?php

/**
 * Unit tests for finding the source an intake channel is configured on.
 *
 * A channel's endpoint, credentials and settings live on an OpenRegister
 * `source` object of type `intake-channel`. These tests pin which enabled
 * source a channel resolves to, that rows of the wrong shape are passed over
 * rather than matched, and that "no source" is null, never an empty
 * configuration a caller would send with.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use OCA\Integriq\Intake\IntakeChannelSourceResolver;
use OCA\Integriq\Intake\IntakeRoutingService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Resolving a channel id to its enabled intake-channel source.
 *
 * @covers \OCA\Integriq\Intake\IntakeChannelSourceResolver
 */
class IntakeChannelSourceResolverTest extends TestCase {

	/**
	 * The resolver asks for enabled intake-channel sources in the intake register.
	 *
	 * @return void
	 */
	public function testTheLookupAsksForEnabledIntakeChannelSources(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->once())
			->method('findAll')
			->with([
				'filters' => [
					'register' => IntakeRoutingService::REGISTER,
					'schema' => 'source',
					'type' => 'intake-channel',
					'isEnabled' => true,
				],
			])
			->willReturn(['results' => []]);

		$this->assertNull((new IntakeChannelSourceResolver($objectService))->sourceFor('whatsapp'));
	}//end testTheLookupAsksForEnabledIntakeChannelSources()

	/**
	 * The source whose configuration names the channel is returned; others and odd rows are passed over.
	 *
	 * @return void
	 */
	public function testTheSourceNamingTheChannelIsReturned(): void {
		$other = ObjectServiceMockBuilder::objectEntity($this, ['configuration' => ['channelId' => 'sms']], 'src-sms');
		$noConfig = ObjectServiceMockBuilder::objectEntity($this, ['configuration' => 'not-an-array'], 'src-odd');
		$match = ObjectServiceMockBuilder::objectEntity($this, ['configuration' => ['channelId' => 'whatsapp', 'endpoint' => 'https://gw']], 'src-wa');
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturn(['results' => [['channelId' => 'whatsapp'], $other, $noConfig, $match]]);

		$source = (new IntakeChannelSourceResolver($objectService))->sourceFor('whatsapp');

		$this->assertSame($match, $source);
	}//end testTheSourceNamingTheChannelIsReturned()

	/**
	 * A bare list (no `results` envelope) is read the same way.
	 *
	 * @return void
	 */
	public function testABareListAnswerIsReadTheSameWay(): void {
		$match = ObjectServiceMockBuilder::objectEntity($this, ['configuration' => ['channelId' => 'form']], 'src-form');
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturn([$match]);

		$this->assertSame($match, (new IntakeChannelSourceResolver($objectService))->sourceFor('form'));
	}//end testABareListAnswerIsReadTheSameWay()

	/**
	 * A `results` value that is not a list resolves nothing.
	 *
	 * @return void
	 */
	public function testANonListResultsValueResolvesNothing(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturn(['results' => 'unavailable']);

		$this->assertNull((new IntakeChannelSourceResolver($objectService))->sourceFor('form'));
	}//end testANonListResultsValueResolvesNothing()

	/**
	 * The configuration of the matching source is returned as stored.
	 *
	 * @return void
	 */
	public function testTheConfigurationOfTheMatchingSourceIsReturned(): void {
		$configuration = ['channelId' => 'whatsapp', 'endpoint' => 'https://gw', 'mode' => 'live'];
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturn([
			'results' => [ObjectServiceMockBuilder::objectEntity($this, ['configuration' => $configuration], 'src-wa')],
		]);

		$this->assertSame($configuration, (new IntakeChannelSourceResolver($objectService))->configurationFor('whatsapp'));
	}//end testTheConfigurationOfTheMatchingSourceIsReturned()

	/**
	 * No matching source means no configuration (null, not an empty array).
	 *
	 * @return void
	 */
	public function testNoMatchingSourceMeansNoConfiguration(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('findAll')->willReturn([
			'results' => [ObjectServiceMockBuilder::objectEntity($this, ['configuration' => ['channelId' => 'sms']], 'src-sms')],
		]);

		$this->assertNull((new IntakeChannelSourceResolver($objectService))->configurationFor('whatsapp'));
	}//end testNoMatchingSourceMeansNoConfiguration()
}//end class
