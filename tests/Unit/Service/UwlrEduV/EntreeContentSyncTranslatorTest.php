<?php

/**
 * Unit tests for EntreeContentSyncTranslator.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\UwlrEduV
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\UwlrEduV;

use OCA\Integriq\Exception\UwlrEduVTranslationException;
use OCA\Integriq\Service\UwlrEduV\EntreeContentSyncTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Entree content sync translator.
 *
 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#requirement-req-005-entree-content-sso-hand-off-translation
 */
class EntreeContentSyncTranslatorTest extends TestCase {

	/**
	 * @var EntreeContentSyncTranslator
	 */
	private EntreeContentSyncTranslator $translator;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->translator = new EntreeContentSyncTranslator();

	}//end setUp()

	/**
	 * A complete payload carries the SSO audience.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-complete-entree-content-payload-carries-the-sso-audience
	 */
	public function testCompletePayloadCarriesSsoAudience(): void {
		$xml = $this->translator->translate(
			'k1',
			['eckId' => 'eck-001', 'schoolBrin' => '12AB', 'ssoAudience' => 'publisher-y']
		);

		$this->assertStringContainsString('<ssoAudience>publisher-y</ssoAudience>', $xml);
		$this->assertStringContainsString('<eckId>eck-001</eckId>', $xml);
		$this->assertStringContainsString('<schoolBrin>12AB</schoolBrin>', $xml);

	}//end testCompletePayloadCarriesSsoAudience()

	/**
	 * A missing schoolBrin never reaches the envelope.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/integriq-adapter-uwlr-eduv/specs/uwlr-eduv-adapter/spec.md#scenario-a-missing-schoolbrin-never-reaches-the-envelope
	 */
	public function testMissingSchoolBrinNeverReachesTheEnvelope(): void {
		$this->expectException(UwlrEduVTranslationException::class);
		$this->expectExceptionMessage('Required field "schoolBrin" is missing or empty');

		$this->translator->translate('k1', ['eckId' => 'eck-001', 'ssoAudience' => 'publisher-y']);

	}//end testMissingSchoolBrinNeverReachesTheEnvelope()
}//end class
