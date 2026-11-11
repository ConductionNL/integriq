<?php

/**
 * Integriq — how BagObjectReader asks the WFS for one pand or verblijfsobject and reads its answer.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\PropertySource\Provider
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
namespace OCA\Integriq\Tests\Unit\PropertySource\Provider;

use OCA\Integriq\Adapters\Pdok\PdokGeocodingClientMock;
use OCA\Integriq\Adapters\Pdok\PdokWfsClient;
use OCA\Integriq\PropertySource\Provider\BagObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The WFS question and every reading of its answer, with a scripted WFS client.
 *
 * @spec openspec/specs/registry-field-source/spec.md#requirement-a-bag-object-is-read-by-its-identificatie-and-an-unanswered-read-is-not-a-missing-object
 */
class BagObjectReaderFeatureTest extends TestCase {

	private const ID = '0363100012167579';

	/**
	 * A reader whose WFS answers the given collection, or throws it, and records what it was asked.
	 *
	 * @param array<string,mixed>|\Throwable $answer  The collection, or what to throw.
	 * @param string                         $flavour The WFS flavour.
	 * @param array<int,mixed>               $asked   Receives the calls, in order.
	 *
	 * @return BagObjectReader
	 */
	private function reader(array|\Throwable $answer, string $flavour, array &$asked): BagObjectReader {
		$wfs = new class($answer, $flavour, $asked) extends PdokWfsClient {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed>|\Throwable $answer  The answer.
			 * @param string                         $kind    The flavour.
			 * @param array<int,mixed>               $asked   The calls.
			 */
			public function __construct(private array|\Throwable $answer, private string $kind, private array &$asked) {
			}

			/** @inheritDoc */
			public function getCapabilities(string $dataset): string {
				return '';
			}

			/** @inheritDoc */
			public function describeFeatureType(string $dataset, string $featureType): string {
				return '';
			}

			/** @inheritDoc */
			public function getFeature(string $dataset, string $featureType, ?array $bbox = null, int $count = 10, string $srsName = 'EPSG:28992', array $filterFields = []): array {
				$this->asked[] = ['getFeature', $dataset, $featureType, $bbox, $count, $srsName, $filterFields];
				if ($this->answer instanceof \Throwable) {
					throw $this->answer;
				}

				return $this->answer;
			}

			/** @inheritDoc */
			public function flavour(): string {
				$this->asked[] = ['flavour'];
				return $this->kind;
			}
		};

		return new BagObjectReader($wfs, new PdokGeocodingClientMock(), new NullLogger());
	}//end reader()

	/**
	 * The flavour is read first, then one getFeature for at most two features filtered on the identificatie.
	 *
	 * @return void
	 */
	public function testTheWfsIsAskedForAtMostTwoFeaturesByIdentificatie(): void {
		$asked = [];
		$this->reader(['features' => []], 'http', $asked)->read('pand', self::ID);

		$this->assertSame([['flavour'], ['getFeature', 'bag', 'bag:pand', null, 2, 'EPSG:28992', ['identificatie' => self::ID]]], $asked);
	}//end testTheWfsIsAskedForAtMostTwoFeaturesByIdentificatie()

	/**
	 * Every reading of the answer, with the simulated flag carried from the flavour.
	 *
	 * @return array<string, array{0: array<string,mixed>|\Throwable, 1: string, 2: array<string,mixed>}>
	 */
	public static function answers(): array {
		$match = ['identificatie' => self::ID, 'status' => 'Pand in gebruik'];
		$found = ['available' => true, 'reason' => '', 'found' => true];
		return [
			'thrown' => [new RuntimeException('down'), 'mock', ['available' => false, 'reason' => BagObjectReader::REASON_UNREACHABLE, 'found' => false, 'simulated' => true, 'object' => null]],
			'unanswered' => [['unanswered' => true, 'features' => [['properties' => $match]]], 'http', ['available' => false, 'reason' => BagObjectReader::REASON_UNREACHABLE, 'found' => false, 'simulated' => false, 'object' => null]],
			'no features key' => [['type' => 'FeatureCollection'], 'http', ['available' => false, 'reason' => BagObjectReader::REASON_UNREACHABLE, 'found' => false, 'simulated' => false, 'object' => null]],
			'features not a list' => [['features' => 'none'], 'http', ['available' => false, 'reason' => BagObjectReader::REASON_UNREACHABLE, 'found' => false, 'simulated' => false, 'object' => null]],
			'empty' => [['features' => []], 'mock', ['available' => true, 'reason' => '', 'found' => false, 'simulated' => true, 'object' => null]],
			'other object only' => [['features' => [['properties' => ['identificatie' => '0363100012167578']], 'junk']], 'http', ['available' => false, 'reason' => BagObjectReader::REASON_UNCLEAR, 'found' => false, 'simulated' => false, 'object' => null]],
			'match after junk' => [['unanswered' => false, 'features' => ['junk', ['id' => 'x'], ['properties' => $match]]], 'http', $found + ['simulated' => false, 'object' => ['type' => 'pand', 'identificatie' => self::ID, 'attributes' => $match]]],
		];
	}//end answers()

	/**
	 * The answer for each WFS reply.
	 *
	 * @param array<string,mixed>|\Throwable $answer   The WFS reply.
	 * @param string                         $flavour  The WFS flavour.
	 * @param array<string,mixed>            $expected The reader's answer, without its type.
	 *
	 * @return void
	 *
	 * @dataProvider answers
	 */
	public function testEachReplyIsReadTheSameWay(array|\Throwable $answer, string $flavour, array $expected): void {
		$asked = [];
		$result = $this->reader($answer, $flavour, $asked)->read('pand', self::ID);
		unset($result['type']);
		ksort($result);
		ksort($expected);

		$this->assertSame($expected, $result);
	}//end testEachReplyIsReadTheSameWay()
}//end class
