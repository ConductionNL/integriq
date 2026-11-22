<?php

/**
 * Unit tests for reading the operation, depth and root fields of a GraphQL document.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Endpoint
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-graphql-and-streaming-protocols/specs/endpoint-runtime/spec.md#requirement-a-graphql-api-is-proxied-with-operation-aware-policies-req-gqlp-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Endpoint;

use OCA\Integriq\Service\Endpoint\GraphqlDocumentReader;
use OCA\Integriq\Service\Endpoint\GraphqlOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Fixture queries with aliases, fragments, comments and strings that a
 * pattern over the text would misread.
 */
#[CoversClass(GraphqlDocumentReader::class)]
#[CoversClass(GraphqlOperation::class)]
class GraphqlDocumentReaderTest extends TestCase {

	/**
	 * A named query with arguments, variables, directives and an alias.
	 *
	 * @return void
	 */
	public function testANamedQueryWithAnAlias(): void {
		$operation = (new GraphqlDocumentReader())->read(
			document: 'query zaakDetails($id: ID!, $with: Boolean = true) @cached(ttl: 30) {
				zaak: zaakById(id: $id, filter: {status: "open", tags: ["a", "b"]}) {
					identificatie
					status @include(if: $with) { omschrijving }
				}
			}'
		);

		$this->assertSame('query', $operation->type);
		$this->assertSame('zaakDetails', $operation->name);
		$this->assertSame(3, $operation->depth);
		$this->assertSame(['zaakById'], $operation->rootFields);
		$this->assertFalse($operation->isIntrospection());
	}

	/**
	 * The shorthand `{ ... }` is an anonymous query.
	 *
	 * @return void
	 */
	public function testTheShorthandIsAnAnonymousQuery(): void {
		$operation = (new GraphqlDocumentReader())->read(document: '{ zaken { identificatie } }');

		$this->assertSame('query', $operation->type);
		$this->assertNull($operation->name);
		$this->assertSame(2, $operation->depth);
	}

	/**
	 * A mutation hidden behind a comment and a string is still read as the mutation it is.
	 *
	 * @return void
	 */
	public function testCommentsAndStringsDoNotHideTheOperation(): void {
		$operation = (new GraphqlDocumentReader())->read(
			document: "# query zaakDetails { zaak }\nmutation verwijderZaak {\n  verwijder(note: \"query zaakDetails { x }\", body: \"\"\"\n  } query { \n  \"\"\") { ok }\n}"
		);

		$this->assertSame('mutation', $operation->type);
		$this->assertSame('verwijderZaak', $operation->name);
		$this->assertSame(['verwijder'], $operation->rootFields);
		$this->assertSame(2, $operation->depth);
	}

	/**
	 * Fragments count towards the depth where they are spread, not where they are defined.
	 *
	 * @return void
	 */
	public function testFragmentsAreExpandedForTheDepth(): void {
		$operation = (new GraphqlDocumentReader())->read(
			document: 'fragment deep on Zaak { a { b { c { d } } } }
			query zakenLijst { zaken { ...deep ... on Zaak { e } } }'
		);

		$this->assertSame('zakenLijst', $operation->name);
		// zaken (1), then a, b, c and d from the fragment.
		$this->assertSame(5, $operation->depth);
	}

	/**
	 * With several operations the request's operationName picks one.
	 *
	 * @return void
	 */
	public function testOperationNamePicksOneOfSeveral(): void {
		$document = 'query zakenLijst { zaken { id } } mutation verwijderZaak { verwijder { ok } }';

		$this->assertSame('mutation', (new GraphqlDocumentReader())->read(document: $document, operationName: 'verwijderZaak')->type);
		$this->assertSame('zakenLijst', (new GraphqlDocumentReader())->read(document: $document, operationName: 'zakenLijst')->name);
	}

	/**
	 * Introspection is seen at the root, also through a fragment.
	 *
	 * @return void
	 */
	public function testIntrospectionIsSeenThroughAFragment(): void {
		$operation = (new GraphqlDocumentReader())->read(
			document: 'query Probe { ...schemaBits } fragment schemaBits on Query { __schema { types { name } } }'
		);

		$this->assertSame(['__schema'], $operation->rootFields);
		$this->assertTrue($operation->isIntrospection());
	}

	/**
	 * Documents the reader refuses, with the reason.
	 *
	 * @return array<string, array{0: string, 1: string|null, 2: string}>
	 */
	public static function refusedDocuments(): array {
		return [
			'several operations, no name' => ['query a { x } query b { y }', null, 'several operations'],
			'unknown operation name' => ['query a { x }', 'b', 'no operation named "b"'],
			'only a fragment' => ['fragment f on Q { x }', null, 'no operation'],
			'unknown fragment' => ['{ ...missing }', null, 'not defined'],
			'cyclic fragment' => ['{ ...a } fragment a on Q { ...b } fragment b on Q { ...a }', null, 'spreads itself'],
			'type definition' => ['type Query { x: Int }', null, 'other than operations'],
			'unclosed selection' => ['{ zaken { id }', null, 'ends inside'],
			'unclosed string' => ['{ zaak(id: "1) { id } }', null, 'does not close'],
			'empty selection' => ['{ }', null, 'cannot be empty'],
			'lone dot' => ['{ a.b }', null, 'lone'],
			'duplicate fragment' => ['{ ...f } fragment f on Q { x } fragment f on Q { y }', null, 'defined twice'],
		];
	}

	/**
	 * A document that is not a readable executable document is refused.
	 *
	 * @param string      $document      The document.
	 * @param string|null $operationName The operation name.
	 * @param string      $reason        Part of the expected message.
	 *
	 * @return void
	 */
	#[DataProvider('refusedDocuments')]
	public function testUnreadableDocumentsAreRefused(string $document, ?string $operationName, string $reason): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage($reason);

		(new GraphqlDocumentReader())->read(document: $document, operationName: $operationName);
	}

	/**
	 * A document past the size limit is refused unread.
	 *
	 * @return void
	 */
	public function testAnOversizedDocumentIsRefused(): void {
		$this->expectExceptionMessage('too large');

		(new GraphqlDocumentReader())->read(document: '{ x }' . str_repeat(' ', GraphqlDocumentReader::MAX_DOCUMENT_BYTES));
	}

	/**
	 * Nesting past what the reader follows is refused rather than recursed.
	 *
	 * @return void
	 */
	public function testRunawayNestingIsRefused(): void {
		$this->expectExceptionMessage('nests too deep');

		(new GraphqlDocumentReader())->read(document: str_repeat('{ a ', 300) . str_repeat('}', 300));
	}
}
