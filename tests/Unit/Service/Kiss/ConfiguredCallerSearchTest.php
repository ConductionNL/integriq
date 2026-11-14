<?php

/**
 * Unit tests for ConfiguredCallerSearch.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/kcc-cti-adapter/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\ConfiguredCallerSearch;
use OCA\Integriq\Service\Kiss\KlantinteractiesClient;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Callers are looked up in the klantinteracties source the CTI source names, and nowhere else.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */
class ConfiguredCallerSearchTest extends TestCase {

	/**
	 * The named KISS source's configuration.
	 *
	 * @var array
	 */
	private array $kiss = [
		'provider' => 'rest',
		'baseUrl' => 'https://kiss.example.nl/api/v1',
		'authentication' => ['encryptedToken' => 'ciphertext-blob'],
	];

	/**
	 * @var OrObjectService&MockObject
	 */
	private $objects;

	/**
	 * @var KlantinteractiesClient&MockObject
	 */
	private $client;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(OrObjectService::class);
		$this->client  = $this->createMock(KlantinteractiesClient::class);

	}//end setUp()

	/**
	 * The search under test.
	 *
	 * @return ConfiguredCallerSearch The search.
	 */
	private function search(): ConfiguredCallerSearch {
		return new ConfiguredCallerSearch($this->objects, $this->client);

	}//end search()

	/**
	 * The object service answers this source for the id `kiss-1`.
	 *
	 * @param array $source The source object.
	 *
	 * @return void
	 */
	private function sourceIs(array $source): void {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($source);
		$this->objects->expects($this->once())->method('find')->with(
			'kiss-1',
			'integriq',
			'source',
			false,
			false
		)->willReturn($entity);

	}//end sourceIs()

	/**
	 * The named REST source is searched with its own configuration, read without RBAC or tenancy.
	 *
	 * @return void
	 */
	public function testTheNamedSourceIsSearched(): void {
		$this->sourceIs(['type' => 'kiss', 'isEnabled' => true, 'configuration' => $this->kiss]);
		$party = ['uuid' => 'p-1'];
		$this->client->expects($this->once())->method('findPartiesByPhoneNumber')
			->with($this->kiss, '+31612345678')->willReturn([$party]);

		$found = $this->search()->findPartiesByPhoneNumber(
			sourceConfiguration: ['callerLookupSource' => ' kiss-1 ', 'sharedSecret' => 'x'],
			e164: '+31612345678'
		);

		$this->assertSame([$party], $found);

	}//end testTheNamedSourceIsSearched()

	/**
	 * Case references come from the same named source.
	 *
	 * @return void
	 */
	public function testCasesComeFromTheNamedSource(): void {
		$this->sourceIs(['type' => 'kiss', 'configuration' => $this->kiss]);
		$this->client->expects($this->once())->method('findOpenCasesForParty')
			->with($this->kiss, ['uuid' => 'p-1'])->willReturn(['case-1']);

		$this->assertSame(
			['case-1'],
			$this->search()->findOpenCasesForParty(sourceConfiguration: ['callerLookupSource' => 'kiss-1'], party: ['uuid' => 'p-1'])
		);

	}//end testCasesComeFromTheNamedSource()

	/**
	 * A CTI source naming no directory looks nobody up, and reads no source.
	 *
	 * @return void
	 */
	public function testNoNamedSourceSearchesNothing(): void {
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('findAll');
		$this->client->expects($this->never())->method($this->anything());

		$this->assertSame([], $this->search()->findPartiesByPhoneNumber(sourceConfiguration: [], e164: '+31612345678'));
		$this->assertSame([], $this->search()->findOpenCasesForParty(sourceConfiguration: ['callerLookupSource' => ''], party: ['uuid' => 'p']));

	}//end testNoNamedSourceSearchesNothing()

	/**
	 * Each source the directory cannot be: not KISS, disabled, the log sandbox, no configuration.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function unusableSources(): array {
		$rest = ['provider' => 'rest', 'baseUrl' => 'https://kiss.example.nl'];

		return [
			'another source type' => [['type' => 'cti', 'configuration' => $rest]],
			'disabled'            => [['type' => 'kiss', 'isEnabled' => false, 'configuration' => $rest]],
			'log sandbox'         => [['type' => 'kiss', 'configuration' => ['provider' => 'log']]],
			'no provider'         => [['type' => 'kiss', 'configuration' => ['baseUrl' => 'https://kiss.example.nl']]],
			'no configuration'    => [['type' => 'kiss', 'configuration' => 'rest']],
		];

	}//end unusableSources()

	/**
	 * A named source that is not an enabled REST KISS source searches nothing.
	 *
	 * @param array $source The source object.
	 *
	 * @dataProvider unusableSources
	 *
	 * @return void
	 */
	public function testAnUnusableSourceSearchesNothing(array $source): void {
		$this->sourceIs($source);
		$this->client->expects($this->never())->method($this->anything());

		$this->assertSame(
			[],
			$this->search()->findPartiesByPhoneNumber(sourceConfiguration: ['callerLookupSource' => 'kiss-1'], e164: '+31612345678')
		);

	}//end testAnUnusableSourceSearchesNothing()

	/**
	 * A source that cannot be read, or is gone, searches nothing.
	 *
	 * @return void
	 */
	public function testAMissingSourceSearchesNothing(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('not found')),
			null
		);
		$this->client->expects($this->never())->method($this->anything());

		$search = $this->search();
		$this->assertSame([], $search->findPartiesByPhoneNumber(sourceConfiguration: ['callerLookupSource' => 'gone'], e164: '+31612345678'));
		$this->assertSame([], $search->findOpenCasesForParty(sourceConfiguration: ['callerLookupSource' => 'gone'], party: ['uuid' => 'p']));

	}//end testAMissingSourceSearchesNothing()

}//end class
