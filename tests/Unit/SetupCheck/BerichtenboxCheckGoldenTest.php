<?php

/**
 * Characterisation test for BerichtenboxCheck::run.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\SetupCheck
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\SetupCheck;

use OCA\Integriq\Service\DigitalPost\BerichtenboxHealthInterface;
use OCA\Integriq\SetupCheck\BerichtenboxCheck;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Golden record of the Berichtenbox setup check.
 */
class BerichtenboxCheckGoldenTest extends TestCase {

	/**
	 * The check's verdict for some findings.
	 *
	 * @param array<string, mixed>|null $findings The findings; null makes the health check unavailable.
	 *
	 * @return array{severity: string, description: string|null}
	 */
	private function verdict(?array $findings): array {
		$health = $this->createMock(BerichtenboxHealthInterface::class);
		$health->method('findings')->willReturn((array)$findings);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($findings, $health): object {
				if ($findings === null) {
					throw new RuntimeException('OpenRegister missing');
				}

				return $health;
			}
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $singular : $plural))
		);

		$check = (new \ReflectionClass(BerichtenboxCheck::class))->newInstanceArgs(self::arguments(container: $container, l10n: $l10n));
		$result = $check->run();

		return ['severity' => $result->getSeverity(), 'description' => $result->getDescription()];
	}//end verdict()

	/**
	 * The constructor arguments, matched by type.
	 *
	 * @param ContainerInterface $container The container.
	 * @param IL10N              $l10n      The translator.
	 *
	 * @return array<int, object>
	 */
	private static function arguments(ContainerInterface $container, IL10N $l10n): array {
		$arguments = [];
		foreach ((new \ReflectionClass(BerichtenboxCheck::class))->getConstructor()->getParameters() as $parameter) {
			$arguments[] = ((string)$parameter->getType() === IL10N::class ? $l10n : $container);
		}

		return $arguments;
	}//end arguments()

	/**
	 * The recorded verdicts.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$record = [
			'unavailable' => $this->verdict(null),
			'no sources' => $this->verdict(['sources' => 0, 'waiting' => 5, 'certificates' => [['slug' => 'x', 'problem' => 'missing', 'validTo' => '']]]),
			'all well' => $this->verdict(['sources' => 2, 'waiting' => 0, 'certificates' => []]),
			'one waiting' => $this->verdict(['sources' => 1, 'waiting' => 1, 'certificates' => []]),
			'many waiting and every certificate problem' => $this->verdict([
				'sources' => 3,
				'waiting' => 4,
				'certificates' => [
					['slug' => 'bb-1', 'problem' => 'missing', 'validTo' => ''],
					['slug' => 'bb-2', 'problem' => 'expires-soon', 'validTo' => '2026-12-01'],
					['slug' => 'bb-3', 'problem' => 'certificate-expired', 'validTo' => ''],
				],
			]),
			'certificate only' => $this->verdict(['sources' => 1, 'waiting' => 0, 'certificates' => [['slug' => 'bb-2', 'problem' => 'expires-soon', 'validTo' => '2026-12-01']]]),
		];

		$path = __DIR__ . '/../../fixtures/setup-check/berichtenbox-check.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
