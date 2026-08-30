<?php

/**
 * Unit tests for ZgwVersionNegotiationService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/zgw-version-translation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\ZgwUnknownVersionException;
use OCA\Integriq\Exception\ZgwVersionNotImplementedException;
use OCA\Integriq\Service\ZgwVersionNegotiationService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for version resolution, known/implemented assertions, and the
 * `expand` query-hint strip.
 *
 * @spec openspec/changes/zgw-version-translation/specs/zgw-version-translation/spec.md#requirement-version-negotiation-with-passthrough-default-req-002
 */
class ZgwVersionNegotiationServiceTest extends TestCase {

	/**
	 * @var ZgwVersionNegotiationService
	 */
	private ZgwVersionNegotiationService $service;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $request;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new ZgwVersionNegotiationService();
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testNoVersionSignalDefaultsToCanonical(): void {
		$this->request->method('getHeader')->willReturn('');

		$resolved = $this->service->resolveVersion(request: $this->request, explicit: null);

		$this->assertSame(expected: ZgwVersionNegotiationService::VERSION_CANONICAL, actual: $resolved);
	}//end testNoVersionSignalDefaultsToCanonical()

	/**
	 * @return void
	 */
	public function testExplicitValueTakesPrecedenceOverHeaders(): void {
		$this->request->method('getHeader')->willReturn('1.0');

		$resolved = $this->service->resolveVersion(request: $this->request, explicit: '1.6');

		$this->assertSame(expected: '1.6', actual: $resolved);
	}//end testExplicitValueTakesPrecedenceOverHeaders()

	/**
	 * @return void
	 */
	public function testXZgwVersionHeaderIsUsedWhenNoExplicitValue(): void {
		$this->request->method('getHeader')->willReturnMap(
			[
				['X-ZGW-Version', '1.6'],
				['Accept', ''],
			]
		);

		$resolved = $this->service->resolveVersion(request: $this->request, explicit: null);

		$this->assertSame(expected: '1.6', actual: $resolved);
	}//end testXZgwVersionHeaderIsUsedWhenNoExplicitValue()

	/**
	 * @return void
	 */
	public function testAcceptVersionParameterIsUsedAsFallback(): void {
		$this->request->method('getHeader')->willReturnMap(
			[
				['X-ZGW-Version', ''],
				['Accept', 'application/json;version=1.6'],
			]
		);

		$resolved = $this->service->resolveVersion(request: $this->request, explicit: null);

		$this->assertSame(expected: '1.6', actual: $resolved);
	}//end testAcceptVersionParameterIsUsedAsFallback()

	/**
	 * @return void
	 */
	public function testAssertKnownVersionAcceptsImplementedVersions(): void {
		$this->service->assertKnownVersion(version: '1.0');
		$this->service->assertKnownVersion(version: '1.6');
		$this->addToAssertionCount(count: 2);
	}//end testAssertKnownVersionAcceptsImplementedVersions()

	/**
	 * @return void
	 */
	public function testAssertKnownVersionAcceptsNextGenPlaceholder(): void {
		$this->service->assertKnownVersion(version: '2.0');
		$this->addToAssertionCount(count: 1);
	}//end testAssertKnownVersionAcceptsNextGenPlaceholder()

	/**
	 * @return void
	 */
	public function testAssertKnownVersionRejectsUnknownVersion(): void {
		$this->expectException(ZgwUnknownVersionException::class);
		$this->service->assertKnownVersion(version: '0.9');
	}//end testAssertKnownVersionRejectsUnknownVersion()

	/**
	 * @return void
	 */
	public function testAssertImplementedVersionRejectsNextGenPlaceholder(): void {
		$this->expectException(ZgwVersionNotImplementedException::class);
		$this->service->assertImplementedVersion(version: '2.0');
	}//end testAssertImplementedVersionRejectsNextGenPlaceholder()

	/**
	 * @return void
	 */
	public function testAssertImplementedVersionAcceptsCanonicalAndStability(): void {
		$this->service->assertImplementedVersion(version: '1.0');
		$this->service->assertImplementedVersion(version: '1.6');
		$this->addToAssertionCount(count: 2);
	}//end testAssertImplementedVersionAcceptsCanonicalAndStability()

	/**
	 * @return void
	 */
	public function testStripUnsupportedExpandHintRemovesExpandKey(): void {
		$stripped = $this->service->stripUnsupportedExpandHint(
			queryParams: ['expand' => 'hoofdzaak', 'zaaktype' => 'https://host/zt/1']
		);

		$this->assertArrayNotHasKey(key: 'expand', array: $stripped);
		$this->assertArrayHasKey(key: 'zaaktype', array: $stripped);
	}//end testStripUnsupportedExpandHintRemovesExpandKey()

	/**
	 * @return void
	 */
	public function testStripUnsupportedExpandHintIsANoOpWhenAbsent(): void {
		$stripped = $this->service->stripUnsupportedExpandHint(queryParams: ['zaaktype' => 'https://host/zt/1']);

		$this->assertSame(expected: ['zaaktype' => 'https://host/zt/1'], actual: $stripped);
	}//end testStripUnsupportedExpandHintIsANoOpWhenAbsent()
}//end class
