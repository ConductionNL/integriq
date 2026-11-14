<?php

/**
 * Integriq — the feed declaration validator agrees with feeds.schema.json.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Feed
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-declared-data-feeds/specs/declared-data-feeds/spec.md#requirement-an-app-declares-its-data-feeds-in-a-file-req-ddf-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Feed;

use OCA\Integriq\Service\Feed\FeedDeclarationValidator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each fixture is run through the validator and through the published JSON Schema.
 */
#[CoversClass(FeedDeclarationValidator::class)]
class FeedDeclarationValidatorTest extends TestCase {

	/**
	 * A well-formed feed.
	 *
	 * @return array<string, mixed> The feed.
	 */
	private static function feed(): array {
		return [
			'key' => 'ledger-lines',
			'title' => 'Ledger lines',
			'register' => 'shillinq',
			'schema' => 'ledger-line',
			'fields' => ['id', 'amount'],
			'filter' => ['status' => 'posted'],
			'scope' => ['parameter' => 'administrationId', 'field' => 'administrationId'],
			'pageSize' => 1000,
		];
	}//end feed()

	/**
	 * The validator's problems for a whole declaration.
	 *
	 * @param mixed $data The declaration.
	 *
	 * @return array<int, string> The problems.
	 */
	private static function problems(mixed $data): array {
		$validator = new FeedDeclarationValidator();
		$errors = $validator->fileErrors(data: $data);
		if ($errors !== []) {
			return $errors;
		}

		foreach ($data['feeds'] as $index => $feed) {
			$errors = array_merge($errors, $validator->feedErrors(feed: $feed, index: $index));
		}

		return $errors;
	}//end problems()

	/**
	 * Whether the published JSON Schema accepts a declaration.
	 *
	 * @param mixed $data The declaration.
	 *
	 * @return boolean True when valid.
	 */
	private static function schemaAccepts(mixed $data): bool {
		$schema = (string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/feeds.schema.json');

		return (new Validator())->validate(json_decode((string)json_encode($data)), $schema)->isValid();
	}//end schemaAccepts()

	/**
	 * Declarations with one change each, and the problem the validator names.
	 *
	 * @return array<string, array{mixed, string|null}>
	 */
	public static function declarations(): array {
		$feed = self::feed();
		$with = static fn (array $change): array => ['feeds' => [array_merge($feed, $change)]];
		$without = static function (string $key) use ($feed): array {
			unset($feed[$key]);
			return ['feeds' => [$feed]];
		};

		return [
			'well formed' => [['app' => 'shillinq', 'feeds' => [$feed]], null],
			'no feeds yet' => [['feeds' => []], null],
			'the fixture' => [json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/feeds/shillinq/lib/Settings/feeds.json'), true), null],
			'not an object' => [['a', 'b'], 'the file is not a JSON object'],
			'feeds missing' => [['app' => 'shillinq'], '"feeds" is not a list'],
			'unknown top key' => [['feeds' => [], 'datasets' => []], 'unknown key "datasets"'],
			'app not a string' => [['app' => 7, 'feeds' => []], '"app" is not a string'],
			'missing scope' => [$without('scope'), 'feeds[0] misses "scope"'],
			'missing pageSize' => [$without('pageSize'), 'feeds[0] misses "pageSize"'],
			'unknown feed key' => [$with(['orderBy' => 'id']), 'feeds[0] has an unknown key "orderBy"'],
			'key with capitals' => [$with(['key' => 'Ledger']), 'feeds[0].key must be lower-case words joined by hyphens'],
			'empty title' => [$with(['title' => ' ']), 'feeds[0].title must be a non-empty string'],
			'no fields' => [$with(['fields' => []]), 'feeds[0].fields must be a non-empty list'],
			'a field with a dot' => [$with(['fields' => ['a.b']]), 'feeds[0].fields holds "a.b", which is not a field name'],
			'a field twice' => [$with(['fields' => ['id', 'id']]), 'feeds[0].fields lists a field twice'],
			'a filter list' => [$with(['filter' => ['posted']]), 'feeds[0].filter must be an object'],
			'a filter object value' => [$with(['filter' => ['status' => ['x']]]), 'feeds[0].filter.status must be a string, number or boolean'],
			'scope without field' => [$with(['scope' => ['parameter' => 'administrationId']]), 'feeds[0].scope.field must be a field name'],
			'scope extra key' => [$with(['scope' => ['parameter' => 'a', 'field' => 'a', 'required' => true]]), 'feeds[0].scope has an unknown key "required"'],
			'page size zero' => [$with(['pageSize' => 0]), 'feeds[0].pageSize must be a whole number from 1 to 5000'],
			'page size too big' => [$with(['pageSize' => 5001]), 'feeds[0].pageSize must be a whole number from 1 to 5000'],
			'page size text' => [$with(['pageSize' => '100']), 'feeds[0].pageSize must be a whole number from 1 to 5000'],
		];
	}//end declarations()

	/**
	 * The validator names the problem, and the JSON Schema agrees on whether there is one.
	 *
	 * @param mixed       $data    The declaration.
	 * @param string|null $problem The problem named, null when well formed.
	 *
	 * @return void
	 */
	#[DataProvider('declarations')]
	public function testTheValidatorAndTheSchemaAgree(mixed $data, ?string $problem): void {
		$problems = self::problems(data: $data);

		if ($problem === null) {
			$this->assertSame([], $problems);
			$this->assertTrue(self::schemaAccepts(data: $data));
			return;
		}

		$this->assertContains($problem, $problems);
		$this->assertFalse(self::schemaAccepts(data: $data));
	}//end testTheValidatorAndTheSchemaAgree()

	/**
	 * A feed that is not an object is refused at its position.
	 *
	 * @return void
	 */
	public function testAFeedThatIsNotAnObject(): void {
		$this->assertSame(['feeds[2] is not an object'], (new FeedDeclarationValidator())->feedErrors(feed: 'ledger', index: 2));
	}//end testAFeedThatIsNotAnObject()
}//end class
