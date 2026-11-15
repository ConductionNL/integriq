<?php

/**
 * Unit tests for ProblemResponse.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Http
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-every-gateway-error-is-a-problem-document-req-rcpe-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Http;

use OCA\Integriq\Http\ProblemResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Problem documents: members, content type, invalid-params and the old members.
 */
#[CoversClass(ProblemResponse::class)]
final class ProblemResponseTest extends TestCase {

	/**
	 * The headers set on a response, without the server defaults getHeaders() merges in.
	 *
	 * @param ProblemResponse $response The response.
	 *
	 * @return array<string,mixed>
	 */
	private function headersOf(ProblemResponse $response): array {
		return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
	}//end headersOf()

	/**
	 * A validation failure lists each field and is served as application/problem+json.
	 *
	 * @return void
	 */
	public function testValidationFailureListsEachField(): void {
		$response = new ProblemResponse(
			slug: 'validation-failed',
			status: 400,
			detail: 'Two fields are not valid.',
			instance: 'req-1',
			invalidParams: [['name' => 'bsn', 'reason' => 'must be 9 digits'], ['name' => 'name', 'reason' => 'is required']]
		);

		$this->assertSame('application/problem+json', $this->headersOf($response)['Content-Type']);
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(
			[
				'type' => 'https://integriq.nl/problems/validation-failed',
				'title' => 'The request is not valid',
				'status' => 400,
				'detail' => 'Two fields are not valid.',
				'instance' => 'req-1',
				'invalid-params' => [['name' => 'bsn', 'reason' => 'must be 9 digits'], ['name' => 'name', 'reason' => 'is required']],
			],
			$response->getData()
		);
	}//end testValidationFailureListsEachField()

	/**
	 * A failed authentication in the old shape keeps `error` and `details` and gains the problem members.
	 *
	 * @return void
	 */
	public function testLegacyAuthenticationFailureKeepsTheOldMembers(): void {
		$response = ProblemResponse::fromLegacy(
			data: ['error' => 'The token could not be validated', 'details' => ['reason' => 'expired']],
			status: 401,
			instance: 'req-2',
			headers: ['WWW-Authenticate' => 'Bearer']
		);

		$data = $response->getData();
		$this->assertSame('https://integriq.nl/problems/authentication-failed', $data['type']);
		$this->assertSame('The caller could not be authenticated', $data['title']);
		$this->assertSame(401, $data['status']);
		$this->assertSame('The token could not be validated', $data['detail']);
		$this->assertSame('req-2', $data['instance']);
		$this->assertSame('The token could not be validated', $data['error']);
		$this->assertSame(['reason' => 'expired'], $data['details']);
		$this->assertSame('Bearer', $this->headersOf($response)['WWW-Authenticate']);
		$this->assertSame('application/problem+json', $this->headersOf($response)['Content-Type']);
	}//end testLegacyAuthenticationFailureKeepsTheOldMembers()

	/**
	 * Old members never overwrite the problem members, and old invalid fields become invalid-params.
	 *
	 * @return void
	 */
	public function testLegacyMembersDoNotOverrideProblemMembers(): void {
		$response = ProblemResponse::fromLegacy(
			data: [
				'type' => 'Validation failed',
				'status' => 'bad',
				'message' => 'Validation failed',
				'invalidParams' => [['property' => 'email', 'code' => 'required', 'reason' => 'The required property is missing']],
			],
			status: 400
		);

		$data = $response->getData();
		$this->assertSame('https://integriq.nl/problems/validation-failed', $data['type']);
		$this->assertSame(400, $data['status']);
		$this->assertSame('Validation failed', $data['detail']);
		$this->assertSame([['name' => 'email', 'reason' => 'The required property is missing']], $data['invalid-params']);
		$this->assertArrayNotHasKey('instance', $data);
	}//end testLegacyMembersDoNotOverrideProblemMembers()

	/**
	 * Each status maps to its problem type; an unknown slug falls back to the generic type.
	 *
	 * @return void
	 */
	public function testStatusesMapToProblemTypes(): void {
		$this->assertSame('rate-limited', ProblemResponse::slugForStatus(status: 429));
		$this->assertSame('forbidden', ProblemResponse::slugForStatus(status: 403));
		$this->assertSame('not-found', ProblemResponse::slugForStatus(status: 404));
		$this->assertSame('upstream-unavailable', ProblemResponse::slugForStatus(status: 502));
		$this->assertSame('upstream-unavailable', ProblemResponse::slugForStatus(status: 500));
		$this->assertSame('gateway-error', ProblemResponse::slugForStatus(status: 409));

		$response = new ProblemResponse(slug: 'no-such-type', status: 409, detail: 'Conflict');
		$this->assertSame('https://integriq.nl/problems/gateway-error', $response->getData()['type']);
		$this->assertSame('The gateway could not handle the request', $response->getData()['title']);
	}//end testStatusesMapToProblemTypes()
}//end class
