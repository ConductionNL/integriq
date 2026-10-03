<?php

/**
 * The dso_verzoek schema declares every attachment field the intake and the job write.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/dso-attachments-on-the-request/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Settings;

use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Validates request payloads carrying `attachments` against the real
 * `dso_verzoek` schema of the merged integriq register.
 *
 * OpenRegister keeps only what a schema declares, so a field the job writes
 * but the schema lacks is silently lost on save. These tests read the
 * register the way InitializeRegister imports it.
 *
 * @spec openspec/changes/dso-attachments-on-the-request/specs/dso-omgevingsloket/spec.md#requirement-bijlagen-download-and-storage-req-dso-005
 */
class DsoVerzoekAttachmentsSchemaTest extends TestCase {

	/**
	 * A request in each attachment state the code writes.
	 *
	 * @return array<string, mixed> The request payload.
	 */
	private function requestWithEveryAttachmentState(): array {
		return [
			'verzoekId' => 'dso-1',
			'status' => 'mapped',
			'attachments' => [
				['name' => 'a.pdf', 'url' => 'https://dso.test/a', 'status' => 'pending', 'attempts' => 0],
				['name' => 'b.pdf', 'url' => 'https://dso.test/b', 'status' => 'stored', 'attempts' => 1, 'fileId' => 42],
				['name' => 'c.dwg', 'url' => 'https://dso.test/c', 'status' => 'failed', 'attempts' => 3, 'error' => 'HTTP 503'],
				['name' => 'd.pdf', 'url' => 'https://dso.test/d', 'status' => 'too-large', 'attempts' => 1, 'error' => 'too large'],
			],
		];

	}//end requestWithEveryAttachmentState()

	/**
	 * Every field of every attachment entry is declared, so a save keeps it,
	 * and a request carrying every attachment state is accepted.
	 *
	 * @return void
	 */
	public function testSchemaDeclaresEveryAttachmentField(): void {
		$schema = RegisterSchemaValidator::descriptor()['components']['schemas']['dso_verzoek'];
		$items = ($schema['properties']['attachments']['items']['properties'] ?? []);

		foreach (['name', 'url', 'status', 'fileId', 'attempts', 'error'] as $field) {
			$this->assertArrayHasKey($field, $items, 'dso_verzoek.attachments[].' . $field . ' is not declared.');
		}

		$this->assertSame(
			['pending', 'stored', 'failed', 'too-large'],
			$schema['properties']['attachments']['items']['properties']['status']['enum']
		);

		$this->assertSame(
			[],
			RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $this->requestWithEveryAttachmentState())
		);

	}//end testSchemaDeclaresEveryAttachmentField()

	/**
	 * An undeclared attachment field or an unknown status is refused, so the
	 * schema cannot drift from what the code writes without a red test.
	 *
	 * @return void
	 */
	public function testUndeclaredFieldOrUnknownStatusIsRefused(): void {
		$extraField = $this->requestWithEveryAttachmentState();
		$extraField['attachments'][0]['localPath'] = '/DSO-verzoeken/x';
		$this->assertNotSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $extraField));

		$unknownStatus = $this->requestWithEveryAttachmentState();
		$unknownStatus['attachments'][0]['status'] = 'downloaded';
		$this->assertNotSame([], RegisterSchemaValidator::errors(schemaSlug: 'dso_verzoek', object: $unknownStatus));

	}//end testUndeclaredFieldOrUnknownStatusIsRefused()
}//end class
