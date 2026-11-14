<?php

/**
 * Unit tests for the log document generation binding: its identity, its
 * templates, and what it answers when asked for a render's status.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service\DocumentGeneration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\DocumentGeneration;

use OCA\Integriq\Service\DocumentGeneration\LogDocumentGenerationProvider;
use OCA\Integriq\Service\DocumentGeneration\RenderOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sandbox binding's identity, templates and status lookups.
 */
#[CoversClass(LogDocumentGenerationProvider::class)]
#[UsesClass(RenderOutcome::class)]
class LogDocumentGenerationProviderStatusTest extends TestCase {

	/**
	 * The binding names itself as the log binding and asks for no configuration.
	 *
	 * @return void
	 */
	public function testIdentifiesAsTheLogBindingWithAnEmptyConfigSchema(): void {
		$provider = new LogDocumentGenerationProvider();

		$this->assertSame('log', $provider->getProviderId());
		$this->assertSame('Sandbox / log (no network call)', $provider->getProviderName());
		$this->assertSame(['type' => 'object', 'properties' => []], $provider->getConfigSchema());
	}//end testIdentifiesAsTheLogBindingWithAnEmptyConfigSchema()

	/**
	 * The binding activates on any source configuration and still renders after.
	 *
	 * @return void
	 */
	public function testActivatesOnAnyConfigurationWithoutThrowing(): void {
		$provider = new LogDocumentGenerationProvider();

		$provider->assertActivatable([]);
		$provider->assertActivatable(['location' => 'https://vendor.example', 'auth' => 'none']);

		$this->assertSame('rendered', $provider->render([], 'mock-brief', [])->status);
	}//end testActivatesOnAnyConfigurationWithoutThrowing()

	/**
	 * The binding offers its three placeholder templates whatever the source says.
	 *
	 * @return void
	 */
	public function testListsTheThreePlaceholderTemplates(): void {
		$provider = new LogDocumentGenerationProvider();

		$this->assertSame(
			[
				['id' => 'mock-beschikking', 'name' => 'Beschikking (placeholder)'],
				['id' => 'mock-brief', 'name' => 'Brief (placeholder)'],
				['id' => 'mock-besluit', 'name' => 'Besluit (placeholder)'],
			],
			$provider->listTemplates(['location' => 'ignored'])
		);
	}//end testListsTheThreePlaceholderTemplates()

	/**
	 * A render it holds reports as rendered, pointing at the same file reference.
	 *
	 * @return void
	 */
	public function testStatusOfAHeldRenderIsRendered(): void {
		$provider = new LogDocumentGenerationProvider();
		$rendered = $provider->render([], 'mock-brief', ['name' => 'A']);

		$status = $provider->status([], $rendered->providerJobId);

		$this->assertSame('rendered', $status->status);
		$this->assertSame($rendered->providerJobId, $status->providerJobId);
		$this->assertSame('log:' . $rendered->providerJobId, $status->fileReference);
		$this->assertSame('Placeholder held in memory for this process', $status->detail);
	}//end testStatusOfAHeldRenderIsRendered()

	/**
	 * An unknown job is a failed answer, not an unreachable vendor.
	 *
	 * @return void
	 */
	public function testStatusOfAnUnknownJobIsFailed(): void {
		$provider = new LogDocumentGenerationProvider();

		$status = $provider->status([], 'MOCK-DOC-NOPE');

		$this->assertSame('failed', $status->status);
		$this->assertSame('MOCK-DOC-NOPE', $status->providerJobId);
		$this->assertSame('The log binding holds no render called MOCK-DOC-NOPE', $status->detail);
	}//end testStatusOfAnUnknownJobIsFailed()

	/**
	 * A render held by one binding instance is unknown to another.
	 *
	 * @return void
	 */
	public function testRendersAreHeldPerInstance(): void {
		$first = new LogDocumentGenerationProvider();
		$rendered = $first->render([], 'mock-besluit', []);

		$status = (new LogDocumentGenerationProvider())->status([], $rendered->providerJobId);

		$this->assertSame('failed', $status->status);
	}//end testRendersAreHeldPerInstance()
}//end class
