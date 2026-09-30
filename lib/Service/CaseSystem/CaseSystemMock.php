<?php

/**
 * The case-system operations answered from fixtures (mock mode).
 *
 * @category Service
 * @package  OCA\Integriq\Service\CaseSystem
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\CaseSystem;

use DateTime;

/**
 * Mock mode (design D4): two cases with documents from
 * lib/Settings/case-system-mock.json. What add-document and create-case
 * write is kept by this instance only, so a caller can read back what it
 * wrote within one run and nothing outlives it.
 *
 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
 */
class CaseSystemMock {

	/**
	 * The fixture file.
	 */
	private const FIXTURE = __DIR__ . '/../../Settings/case-system-mock.json';

	/**
	 * The kinds decidiq sends.
	 */
	public const KINDS = ['agenda', 'item-document', 'decision', 'decision-list', 'minutes', 'proof-package'];

	/**
	 * The cases, loaded on first use.
	 *
	 * @var list<array{url:string,identification:string,title:string,documents:list<array{url:string,name:string,content:string}>}>|null
	 */
	private ?array $cases = null;

	/**
	 * Answer one operation from the fixtures.
	 *
	 * @param string              $operation One of CaseSystemOperations::OPERATIONS.
	 * @param array<string,mixed> $body      The caller's body.
	 *
	 * @return array<string,mixed> The answer.
	 *
	 * @throws CaseSystemRefusal When the operation is refused.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	public function run(string $operation, array $body): array {
		return match ($operation) {
			'read-case' => $this->readCase(reference: (string)($body['reference'] ?? '')),
			'list-documents' => $this->listDocuments(case: (string)($body['case'] ?? '')),
			'read-document' => $this->readDocument(url: (string)($body['document'] ?? '')),
			'add-document' => $this->addDocument(body: $body),
			'create-case' => $this->createCase(body: $body),
			default => throw new CaseSystemRefusal(status: 404, message: CaseSystemOperations::unknownOperationMessage(operation: $operation)),
		};
	}//end run()

	/**
	 * A case by number or address; an empty url when unknown.
	 *
	 * @param string $reference The case number or address.
	 *
	 * @return array{url:string,identification:string,title:string}
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	private function readCase(string $reference): array {
		$index = $this->caseIndex(reference: $reference);
		if ($index === null) {
			return ['url' => '', 'identification' => '', 'title' => ''];
		}

		$case = $this->cases()[$index];

		return ['url' => $case['url'], 'identification' => $case['identification'], 'title' => $case['title']];
	}//end readCase()

	/**
	 * The documents of a case.
	 *
	 * @param string $case The case address.
	 *
	 * @return array{documents:list<array{url:string,name:string}>}
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	private function listDocuments(string $case): array {
		$index = $this->caseIndex(reference: $case);
		if ($index === null) {
			return ['documents' => []];
		}

		$documents = [];
		foreach ($this->cases()[$index]['documents'] as $document) {
			$documents[] = ['url' => $document['url'], 'name' => $document['name']];
		}

		return ['documents' => $documents];
	}//end listDocuments()

	/**
	 * One document with its content.
	 *
	 * @param string $url The document address.
	 *
	 * @return array{name:string,content:string}
	 *
	 * @throws CaseSystemRefusal When no fixture document has that address.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	private function readDocument(string $url): array {
		foreach ($this->cases() as $case) {
			foreach ($case['documents'] as $document) {
				if ($document['url'] === $url) {
					return ['name' => $document['name'], 'content' => $document['content']];
				}
			}
		}

		throw new CaseSystemRefusal(status: 404, message: 'The case system holds no document at ' . $url . '.');
	}//end readDocument()

	/**
	 * Add a document to a fixture case.
	 *
	 * @param array<string,mixed> $body The body.
	 *
	 * @return array{url:string}
	 *
	 * @throws CaseSystemRefusal When the kind or the case is unknown.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	private function addDocument(array $body): array {
		$kind = (string)($body['kind'] ?? '');
		if (in_array($kind, self::KINDS, true) === false) {
			throw new CaseSystemRefusal(status: 422, message: 'The kind "' . $kind . '" has no document type in this case system\'s kinds table.');
		}

		$index = $this->caseIndex(reference: (string)($body['case'] ?? ''));
		if ($index === null) {
			throw new CaseSystemRefusal(status: 404, message: 'The case system holds no case at ' . (string)($body['case'] ?? '') . '.');
		}

		$url = 'https://documenten.mock.invalid/api/v1/enkelvoudiginformatieobjecten/' . self::uuid();
		$this->cases[$index]['documents'][] = [
			'url' => $url,
			'name' => (string)($body['name'] ?? ''),
			'content' => (string)($body['content'] ?? ''),
		];

		return ['url' => $url];
	}//end addDocument()

	/**
	 * Create a meeting case.
	 *
	 * @param array<string,mixed> $body The body.
	 *
	 * @return array{url:string,identification:string}
	 *
	 * @throws CaseSystemRefusal When the kind is not meeting or the date is not a date.
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	private function createCase(array $body): array {
		$date = (string)($body['date'] ?? '');
		$parsed = DateTime::createFromFormat('!Y-m-d', $date);
		if (($body['kind'] ?? '') !== 'meeting' || $parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new CaseSystemRefusal(status: 422, message: 'create-case needs kind "meeting" and a date as year-month-day.');
		}

		$cases = $this->cases();
		$case = [
			'url' => 'https://zaken.mock.invalid/api/v1/zaken/' . self::uuid(),
			'identification' => sprintf('ZAAK-MOCK-%04d', count($cases) + 1),
			'title' => (string)($body['title'] ?? ''),
			'documents' => [],
		];
		$this->cases[] = $case;

		return ['url' => $case['url'], 'identification' => $case['identification']];
	}//end createCase()

	/**
	 * The index of the case with this number or address.
	 *
	 * @param string $reference The number or address.
	 *
	 * @return integer|null
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	private function caseIndex(string $reference): ?int {
		foreach ($this->cases() as $index => $case) {
			if ($reference !== '' && ($case['url'] === $reference || $case['identification'] === $reference)) {
				return $index;
			}
		}

		return null;
	}//end caseIndex()

	/**
	 * The fixture cases.
	 *
	 * @return list<array{url:string,identification:string,title:string,documents:list<array{url:string,name:string,content:string}>}>
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	private function cases(): array {
		if ($this->cases === null) {
			$decoded = json_decode((string)file_get_contents(self::FIXTURE), true);
			$this->cases = array_values((array)($decoded['cases'] ?? []));
		}

		return $this->cases;
	}//end cases()

	/**
	 * A random version-4 uuid.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/case-system-operations-for-decidiq/specs/case-system-operations/spec.md#requirement-a-seeded-zgw-zaken-template-links-a-connection-at-once-req-cso-003
	 */
	private static function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end uuid()
}//end class
