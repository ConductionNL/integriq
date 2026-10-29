<?php

/**
 * Characterization tests for MappingService::handleCast.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Adbar\Dot;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationContractService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Twig\Loader\ArrayLoader;

/**
 * Pins the observable result of every cast that handleCast supports.
 */
class MappingServiceCastTest extends TestCase {

	/**
	 * @var MappingService
	 */
	private MappingService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = new MappingService(
			new ArrayLoader([]),
			$this->createMock(CallService::class),
			$this->createMock(FileService::class),
			$this->createMock(ObjectService::class),
			$this->createMock(OrObjectService::class),
			$this->createMock(SynchronizationContractService::class),
		);
	}//end setUp()

	/**
	 * Runs handleCast on a single key and returns the resulting array.
	 *
	 * @param array  $data The data to cast in.
	 * @param string $key  The key to cast.
	 * @param string $cast The cast to apply.
	 *
	 * @return array The data after the cast.
	 */
	private function cast(array $data, string $key, string $cast): array {
		$dot = new Dot(items: $data);
		$method = new ReflectionMethod(objectOrMethod: MappingService::class, method: 'handleCast');
		$method->invoke($this->service, $dot, $key, $cast);

		return $dot->all();
	}//end cast()

	/**
	 * Cases that replace the value at the key with a new value.
	 *
	 * @return array<string, array{mixed, string, mixed}>
	 */
	public static function valueCastProvider(): array {
		return [
			'string from int'           => [12, 'string', '12'],
			'bool from 1'               => ['1', 'bool', true],
			'boolean from yes'          => ['YES', 'boolean', true],
			'bool from true'            => ['True', 'bool', true],
			'bool from other'           => ['no', 'bool', false],
			'nullable bool keeps null'  => [null, '?bool', null],
			'nullable boolean from 1'   => ['1', '?boolean', true],
			'nullable bool from other'  => ['nope', '?bool', false],
			'int from string'           => ['42abc', 'int', 42],
			'integer from float'        => [3.9, 'integer', 3],
			'float from string'         => ['1.5', 'float', 1.5],
			'array from scalar'         => ['a', 'array', ['a']],
			'date passes format'        => ['Y', 'date', date('Y')],
			'url encodes'               => ['a b&c', 'url', 'a+b%26c'],
			'urlDecode decodes'         => ['a+b%26c', 'urlDecode', 'a b&c'],
			'rawurl encodes'            => ['a b', 'rawurl', 'a%20b'],
			'rawurlDecode decodes'      => ['a%20b', 'rawurlDecode', 'a b'],
			'html encodes'              => ['<b>', 'html', '&lt;b&gt;'],
			'htmlDecode decodes'        => ['&lt;b&gt;', 'htmlDecode', '<b>'],
			'base64 encodes'            => ['abc', 'base64', 'YWJj'],
			'base64Decode decodes'      => ['YWJj', 'base64Decode', 'abc'],
			'json encodes'              => [['a' => 1], 'json', '{"a":1}'],
			'jsonToArray decodes'       => ['{&quot;a&quot;:1}', 'jsonToArray', ['a' => 1]],
			'jsonToArray keeps array'   => [['a' => 1], 'jsonToArray', ['a' => 1]],
			'nullStringToNull nulls'    => ['null', 'nullStringToNull', null],
			'nullStringToNull keeps'    => ['x', 'nullStringToNull', 'x'],
			'coordinates split'         => ['1 2', 'coordinateStringToArray', ['1', '2']],
			'moneyStringToInt'          => ['1.234,56', 'moneyStringToInt', 123456],
			'intToMoneyString'          => [123456, 'intToMoneyString', '1.234,56'],
			'setNullIfValue match'      => ['x', 'setNullIfValue==x', null],
			'setNullIfValue no match'   => ['y', 'setNullIfValue==x', 'y'],
			'setNullIfValue empty'      => ['', 'setNullIfValue==', null],
			'setNullIfValue null array' => [['a' => null, 'b' => ['c' => null]], 'setNullIfValue==', null],
			'setNullIfValue bare'       => ['', 'setNullIfValue', ''],
			'unknown cast keeps value'  => ['x', 'doesNotExist', 'x'],
			'keyCantBeValue differs'    => ['other', 'keyCantBeValue', 'other'],
			'unsetIfValue no match'     => ['y', 'unsetIfValue==x', 'y'],
		];
	}//end valueCastProvider()

	/**
	 * Test that a value cast writes the expected value back to the key.
	 *
	 * @param mixed  $input    The value before the cast.
	 * @param string $cast     The cast to apply.
	 * @param mixed  $expected The value after the cast.
	 *
	 * @return void
	 */
	#[DataProvider('valueCastProvider')]
	public function testValueCast(mixed $input, string $cast, mixed $expected): void {
		$result = $this->cast(data: ['field' => $input], key: 'field', cast: $cast);

		$this->assertArrayHasKey(key: 'field', array: $result);
		$this->assertSame(expected: $expected, actual: $result['field']);
	}//end testValueCast()

	/**
	 * Cases that remove the key from the data.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public static function deletingCastProvider(): array {
		return [
			'unsetIfValue match'       => ['x', 'unsetIfValue==x'],
			'unsetIfValue empty'       => ['', 'unsetIfValue=='],
			'unsetIfValue null'        => [null, 'unsetIfValue=='],
			'unsetIfValue null array'  => [['a' => null], 'unsetIfValue=='],
			'keyCantBeValue same'      => ['field', 'keyCantBeValue'],
		];
	}//end deletingCastProvider()

	/**
	 * Test that a deleting cast removes the key.
	 *
	 * @param mixed  $input The value before the cast.
	 * @param string $cast  The cast to apply.
	 *
	 * @return void
	 */
	#[DataProvider('deletingCastProvider')]
	public function testDeletingCast(mixed $input, string $cast): void {
		$result = $this->cast(data: ['field' => $input, 'other' => 1], key: 'field', cast: $cast);

		$this->assertSame(expected: ['other' => 1], actual: $result);
	}//end testDeletingCast()

	/**
	 * Test that countValue counts the referenced countable field.
	 *
	 * @return void
	 */
	public function testCountValueCountsReferencedField(): void {
		$result = $this->cast(data: ['field' => 'x', 'list' => [1, 2, 3]], key: 'field', cast: 'countValue:list');

		$this->assertSame(expected: 3, actual: $result['field']);
	}//end testCountValueCountsReferencedField()

	/**
	 * Test that countValue keeps the value when the referenced field is missing or not countable.
	 *
	 * @return void
	 */
	public function testCountValueKeepsValueWhenReferenceUnusable(): void {
		$missing = $this->cast(data: ['field' => 'x'], key: 'field', cast: 'countValue:list');
		$notCountable = $this->cast(data: ['field' => 'x', 'list' => 'abc'], key: 'field', cast: 'countValue:list');
		$noPath = $this->cast(data: ['field' => 'x'], key: 'field', cast: 'countValue:');

		$this->assertSame(expected: 'x', actual: $missing['field']);
		$this->assertSame(expected: 'x', actual: $notCountable['field']);
		$this->assertSame(expected: 'x', actual: $noPath['field']);
	}//end testCountValueKeepsValueWhenReferenceUnusable()

	/**
	 * Test that a cast on a missing key does not create it.
	 *
	 * @return void
	 */
	public function testCastOnMissingKeyDoesNotCreateIt(): void {
		$result = $this->cast(data: ['other' => 1], key: 'field', cast: 'string');

		$this->assertSame(expected: ['other' => 1], actual: $result);
	}//end testCastOnMissingKeyDoesNotCreateIt()

	/**
	 * Test that utf8 transliterates to ASCII.
	 *
	 * @return void
	 */
	public function testUtf8Transliterates(): void {
		$result = $this->cast(data: ['field' => 'abc'], key: 'field', cast: 'utf8');

		$this->assertSame(expected: 'abc', actual: $result['field']);
	}//end testUtf8Transliterates()
}//end class
