<?php

/**
 * Unit tests for ObservabilityBindings and ObservabilityRegistrar.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability;

use OCA\Integriq\Observability\IntegriqMetricsProvider;
use OCA\Integriq\Observability\ObservabilityBindings;
use OCA\Integriq\Observability\ObservabilityRegistrar;
use OCA\Integriq\Observability\Otel\OtelExportBreaker;
use OCA\Integriq\Observability\Otel\OtelExportBreakerInterface;
use OCA\Integriq\Observability\Otel\OtelSettings;
use OCA\Integriq\Observability\Otel\OtelSettingsInterface;
use OCA\Integriq\Observability\Otel\OtlpTraceExporter;
use OCA\Integriq\Observability\Otel\SpanMapper;
use OCA\Integriq\Observability\Otel\SpanMapperInterface;
use OCA\Integriq\Observability\Otel\TraceExporterInterface;
use OCA\OpenRegister\AppHost\IMetricsProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * The observability registrar binds every interface to its one implementation
 * and the metrics provider under the engine's per-app alias.
 *
 * @covers \OCA\Integriq\Observability\ObservabilityBindings
 * @covers \OCA\Integriq\Observability\ObservabilityRegistrar
 */
class ObservabilityBindingsTest extends TestCase {

	/**
	 * The aliases, in registration order.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private const EXPECTED = [
		[SpanMapperInterface::class, SpanMapper::class],
		[OtelSettingsInterface::class, OtelSettings::class],
		[OtelExportBreakerInterface::class, OtelExportBreaker::class],
		[TraceExporterInterface::class, OtlpTraceExporter::class],
		[IMetricsProvider::class . '::integriq', IntegriqMetricsProvider::class],
	];

	/**
	 * The bindings register exactly these aliases, in order, and nothing else.
	 *
	 * @return void
	 */
	public function testTheBindingsRegisterEveryAlias(): void {
		$context = $this->recordingContext($aliases);

		(new ObservabilityBindings())->register(context: $context);

		$this->assertSame(self::EXPECTED, $aliases);
	}//end testTheBindingsRegisterEveryAlias()

	/**
	 * The registrar delegates to the bindings, so it registers the same set.
	 *
	 * @return void
	 */
	public function testTheRegistrarRegistersTheSameAliases(): void {
		$context = $this->recordingContext($aliases);

		(new ObservabilityRegistrar())->register(context: $context);

		$this->assertSame(self::EXPECTED, $aliases);
	}//end testTheRegistrarRegistersTheSameAliases()

	/**
	 * Every alias target is a class that implements its interface.
	 *
	 * @return void
	 */
	public function testEveryTargetImplementsItsInterface(): void {
		foreach (array_slice(self::EXPECTED, 0, 4) as [$interface, $class]) {
			$this->assertTrue(is_subclass_of($class, $interface), $class . ' implements ' . $interface);
		}
	}//end testEveryTargetImplementsItsInterface()

	/**
	 * A registration context that records the aliases and refuses anything else.
	 *
	 * @param array|null $aliases Receives the recorded aliases.
	 *
	 * @return IRegistrationContext
	 */
	private function recordingContext(?array &$aliases): IRegistrationContext {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->exactly(5))->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);
		$context->expects($this->never())->method('registerService');

		return $context;
	}//end recordingContext()
}//end class
