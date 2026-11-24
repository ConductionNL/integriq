<?php

/**
 * FormSubmissionAdapterContractTest
 *
 * Runs the intake channel contract test case on a built-in adapter, so the case itself is known to pass on conforming code.
 *
 * @category  Tests
 * @package   OCA\Integriq\Tests\Unit\Contract
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-24-platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-a-developer-builds-an-extension-from-a-guide-a-skeleton-and-contract-tests-req-cxk-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Contract;

use OCA\Integriq\Intake\Adapter\FormSubmissionAdapter;
use OCA\Integriq\Intake\Adapter\IntakeFileNaming;
use OCA\Integriq\Intake\Adapter\IntakePayloadReader;
use OCA\Integriq\Intake\ChannelCapabilities;
use OCA\Integriq\Intake\InboundMessage;
use OCA\Integriq\Intake\IntakeChannelAdapterInterface;
use OCA\Integriq\Intake\ReplyResult;
use OCA\Integriq\Tests\Contract\IntakeChannelContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The form submission adapter keeps the intake channel contract.
 */
#[CoversClass(FormSubmissionAdapter::class)]
#[UsesClass(ChannelCapabilities::class)]
#[UsesClass(InboundMessage::class)]
#[UsesClass(ReplyResult::class)]
#[UsesClass(IntakePayloadReader::class)]
#[UsesClass(IntakeFileNaming::class)]
final class FormSubmissionAdapterContractTest extends IntakeChannelContractTestCase {

	/**
	 * The adapter under test.
	 *
	 * @return IntakeChannelAdapterInterface
	 */
	protected function createAdapter(): IntakeChannelAdapterInterface {
		return new FormSubmissionAdapter();
	}//end createAdapter()

	/**
	 * A submission the form channel delivers.
	 *
	 * @return array<string,mixed>
	 */
	protected function validPayload(): array {
		return [
			'formId' => 'melding-openbare-ruimte',
			'submissionId' => 'sub-42',
			'submitter' => ['name' => 'Fenna de Jong', 'email' => 'fenna@example.nl'],
			'summary' => 'Losliggende stoeptegel',
			'data' => ['straat' => 'Dorpsstraat'],
		];
	}//end validPayload()
}//end class
