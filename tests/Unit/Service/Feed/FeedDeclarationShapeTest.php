<?php

/**
 * Integriq — a declaration written as datasets is read as feeds.
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

use OCA\Integriq\Service\Feed\FeedDeclarationShape;
use OCA\Integriq\Service\Feed\FeedDeclarationValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The dataset shape shillinq writes becomes the feed shape integriq publishes.
 */
#[CoversClass(FeedDeclarationShape::class)]
#[UsesClass(FeedDeclarationValidator::class)]
class FeedDeclarationShapeTest extends TestCase {

	/**
	 * One dataset as shillinq writes it.
	 *
	 * @return array<string, mixed> The dataset.
	 */
	private static function dataset(): array {
		return [
			'key' => 'accounts',
			'title' => 'Chart of accounts',
			'register' => 'shillinq',
			'schema' => 'Account',
			'filter' => [],
			'binding' => ['administrationId' => 'credential.administrationId'],
			'fields' => ['accountNumber', 'name'],
			'pageSize' => 1000,
		];
	}//end dataset()

	/**
	 * Datasets become feeds, a one-field binding becomes the scope, and the file notes go.
	 *
	 * @return void
	 */
	public function testDatasetsBecomeFeedsScopedByTheirBinding(): void {
		$normalized = (new FeedDeclarationShape())->normalize(
			data: ['_meta' => ['change' => 'reporting-data-delivery'], 'app' => 'shillinq', 'version' => '1.0.0', 'datasets' => [self::dataset()]]
		);

		$expected = self::dataset();
		unset($expected['binding']);
		$expected['scope'] = ['parameter' => 'administrationId', 'field' => 'administrationId'];
		$this->assertSame(['app' => 'shillinq', 'feeds' => [$expected]], $normalized);
	}//end testDatasetsBecomeFeedsScopedByTheirBinding()

	/**
	 * A file already in the feed shape comes back unchanged, datasets and all.
	 *
	 * @return void
	 */
	public function testAFileInTheFeedShapeIsUntouched(): void {
		$file = ['app' => 'shillinq', 'feeds' => [['key' => 'a']], 'datasets' => [self::dataset()], 'version' => '1'];

		$this->assertSame($file, (new FeedDeclarationShape())->normalize(data: $file));
	}//end testAFileInTheFeedShapeIsUntouched()

	/**
	 * Something that is not a declaration comes back as it came, for the validator to refuse.
	 *
	 * @return void
	 */
	public function testAnythingElseIsReturnedAsItCame(): void {
		$this->assertNull((new FeedDeclarationShape())->normalize(data: null));
		$this->assertSame('text', (new FeedDeclarationShape())->normalize(data: 'text'));
		$this->assertSame(['app' => 'shillinq'], (new FeedDeclarationShape())->normalize(data: ['app' => 'shillinq']));
	}//end testAnythingElseIsReturnedAsItCame()

	/**
	 * A dataset with its own scope, or bound by more than one field, keeps what it has.
	 *
	 * @return void
	 */
	public function testOnlyAOneFieldBindingWithoutAScopeIsRead(): void {
		$scoped = self::dataset();
		$scoped['scope'] = ['parameter' => 'admin', 'field' => 'administrationId'];
		$twice = self::dataset();
		$twice['binding']['accountType'] = 'credential.accountType';

		$normalized = (new FeedDeclarationShape())->normalize(data: ['app' => 'shillinq', 'datasets' => [$scoped, $twice, 'not a dataset']]);

		$this->assertSame([$scoped, $twice, 'not a dataset'], $normalized['feeds']);
	}//end testOnlyAOneFieldBindingWithoutAScopeIsRead()

	/**
	 * shillinq's own file, read this way, passes the validator without one error.
	 *
	 * @return void
	 */
	public function testShillinqsOwnFilePassesTheValidator(): void {
		$file = json_decode(
			(string)file_get_contents(dirname(__DIR__, 3) . '/fixtures/feeds/shillinq-datasets/lib/Settings/feeds.json'),
			true
		);
		$validator = new FeedDeclarationValidator();

		$normalized = (new FeedDeclarationShape())->normalize(data: $file);

		$this->assertSame([], $validator->fileErrors(data: $normalized));
		$this->assertCount(4, $normalized['feeds']);
		foreach ($normalized['feeds'] as $index => $feed) {
			$this->assertSame([], $validator->feedErrors(feed: $feed, index: $index), $feed['key']);
		}

		$this->assertNotSame([], $validator->fileErrors(data: $file), 'the raw file is refused, which is what this class exists for');
	}//end testShillinqsOwnFilePassesTheValidator()
}//end class
