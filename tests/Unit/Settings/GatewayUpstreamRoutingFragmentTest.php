<?php

/**
 * Contract test for the gateway-upstream-routing register fragment.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-upstream-routing/specs/endpoint-runtime/spec.md#requirement-the-call-log-records-which-target-served-a-call-req-uprt-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Repair\InitializeRegister;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Merged the way InitializeRegister merges it, the fragment adds the routing
 * fields to the endpoint and the call log without touching what was there,
 * and the per-target count groups on a declared call-log property.
 */
class GatewayUpstreamRoutingFragmentTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Decode a JSON file under the repository root.
	 *
	 * @param string $relative The path from the root.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $relative): array {
		return json_decode((string)file_get_contents(self::root() . $relative), true, flags: JSON_THROW_ON_ERROR);
	}//end json()

	/**
	 * The schemas with every fragment merged in.
	 *
	 * @return array<string, mixed>
	 */
	private static function mergedSchemas(): array {
		$descriptor = self::json('/lib/Settings/integriq_register.json');
		$merge = new ReflectionMethod(InitializeRegister::class, 'deepMergeConfig');
		$fragments = glob(self::root() . '/lib/Settings/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragmentPath) {
			$fragment = json_decode((string)file_get_contents($fragmentPath), true);
			if (is_array($fragment) === true) {
				$descriptor = $merge->invoke(null, $descriptor, $fragment);
			}
		}

		return $descriptor['components']['schemas'];
	}//end mergedSchemas()

	/**
	 * The endpoint gains targets and sticky; its single targetId stays.
	 *
	 * @return void
	 */
	public function testTheEndpointGainsTargetsAndKeepsItsTargetId(): void {
		$endpoint = self::mergedSchemas()['endpoint']['properties'];

		$this->assertArrayHasKey('targetId', $endpoint);
		$this->assertSame('array', $endpoint['targets']['type']);
		$this->assertSame(['source'], $endpoint['targets']['items']['required']);
		$this->assertSame(['source', 'weight', 'when', 'group'], array_keys($endpoint['targets']['items']['properties']));
		$this->assertSame(1, $endpoint['targets']['items']['properties']['weight']['default']);
		$this->assertSame('boolean', $endpoint['sticky']['type']);
		$this->assertFalse($endpoint['sticky']['default']);
	}//end testTheEndpointGainsTargetsAndKeepsItsTargetId()

	/**
	 * The call log gains routedTarget and routedReason and counts calls per routed target.
	 *
	 * @return void
	 */
	public function testTheCallLogRecordsTheTargetAndCountsPerTarget(): void {
		$callLog = self::mergedSchemas()['call_log'];

		$this->assertArrayHasKey('statusCode', $callLog['properties']);
		$this->assertSame('string', $callLog['properties']['routedTarget']['type']);
		$this->assertSame('string', $callLog['properties']['routedReason']['type']);
		$this->assertSame(['admin'], $callLog['authorization']['read'], 'the call-log lockdown fragment still applies');

		$count = $callLog['x-openregister-aggregations']['callsPerTarget'];
		$this->assertSame('count', $count['metric']);
		foreach ($count['groupBy'] as $field) {
			$this->assertArrayHasKey($field, $callLog['properties']);
		}
	}//end testTheCallLogRecordsTheTargetAndCountsPerTarget()
}//end class
