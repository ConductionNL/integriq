<?php

/**
 * Tests for the instance lookups of the fixed webhook profiles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Intake;

use OCA\Integriq\Service\Intake\WebhookProfiles;
use OCA\Integriq\Service\Intake\WebhookProfilesInterface;
use PHPUnit\Framework\TestCase;

/**
 * Injected callers get the same profiles the static lookups give.
 */
class WebhookProfilesTest extends TestCase {

	/**
	 * The profile list matches, key for key.
	 *
	 * @return void
	 */
	public function testProfilesMatchTheStaticList(): void {
		$profiles = new WebhookProfiles();

		$this->assertInstanceOf(WebhookProfilesInterface::class, $profiles);
		$this->assertEquals(expected: WebhookProfiles::all(), actual: $profiles->profiles());
		$this->assertArrayHasKey(WebhookProfilesInterface::PEPPOL, $profiles->profiles());

	}//end testProfilesMatchTheStaticList()

	/**
	 * Lookups by authorization type, intake channel and alert channel match.
	 *
	 * @return void
	 */
	public function testLookupsMatchTheStaticLookups(): void {
		$profiles = new WebhookProfiles();

		$this->assertEquals(
			expected: WebhookProfiles::byAuthorizationType(authorizationType: 'PEPPOL-WEBHOOK'),
			actual: $profiles->forAuthorizationType(authorizationType: 'PEPPOL-WEBHOOK')
		);
		$this->assertEquals(
			expected: WebhookProfiles::byAuthorizationType(authorizationType: WebhookProfiles::INTAKE_CHANNEL_PREFIX . 'teams'),
			actual: $profiles->forAuthorizationType(authorizationType: WebhookProfiles::INTAKE_CHANNEL_PREFIX . 'teams')
		);
		$this->assertNull($profiles->forAuthorizationType(authorizationType: 'unknown'));

		$channel = WebhookProfiles::peppol()->channel;
		$this->assertEquals(expected: WebhookProfiles::byChannel(channel: $channel), actual: $profiles->forChannel(channel: $channel));
		$this->assertNull($profiles->forChannel(channel: 'no-such-channel'));

	}//end testLookupsMatchTheStaticLookups()
}//end class
