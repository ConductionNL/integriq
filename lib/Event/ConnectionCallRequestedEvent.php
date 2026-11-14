<?php

/**
 * Integriq ConnectionCallRequested Event.
 *
 * The ADR-041 cross-app command for calling an outside connection an app
 * declared in its `lib/Settings/connections.json`: the app names the
 * connection and the request, integriq calls the source an administrator
 * linked to it, with that source's credential, and writes the answer back.
 * The calling app never sees the credential. A consumer MUST guard the
 * dispatch with class_exists() and treat an unhandled event as a refused call.
 *
 * @category Event
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "call my declared connection with this request".
 *
 * The result slot carries either the answer (`status`, `body`, `sourceSlug`)
 * or a structured refusal (`code`, `reason`), never both. A handled event is
 * never left empty.
 *
 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
 */
class ConnectionCallRequestedEvent extends Event {

	use CommandAnswer;

	/**
	 * Constructor.
	 *
	 * @param string                $sourceApp     The calling app id, for example `humaniq`.
	 * @param string                $connectionKey One of the app's declared connection keys.
	 * @param string                $method        `GET` or `POST`.
	 * @param string                $path          The path below the linked source's location.
	 * @param array<string,mixed>   $query         Query parameters.
	 * @param array<mixed>|string|null $body       The request body for a POST.
	 * @param string                $correlationId Caller-generated id, for the caller's own bookkeeping.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $connectionKey,
		private readonly string $method = 'GET',
		private readonly string $path = '',
		private readonly array $query = [],
		private readonly array|string|null $body = null,
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The calling app id.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The declared connection key.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getConnectionKey(): string {
		return $this->connectionKey;
	}//end getConnectionKey()

	/**
	 * The HTTP method.
	 *
	 * @return string The method as given.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getMethod(): string {
		return $this->method;
	}//end getMethod()

	/**
	 * The path below the source's location.
	 *
	 * @return string The path.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getPath(): string {
		return $this->path;
	}//end getPath()

	/**
	 * The query parameters.
	 *
	 * @return array<string,mixed> The query.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getQuery(): array {
		return $this->query;
	}//end getQuery()

	/**
	 * The request body.
	 *
	 * @return array<mixed>|string|null The body.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getBody(): array|string|null {
		return $this->body;
	}//end getBody()

	/**
	 * The caller's correlation id.
	 *
	 * @return string The correlation id.
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Record the answer and mark the request handled.
	 *
	 * @param int    $status     The HTTP status the source answered.
	 * @param mixed  $body       The decoded JSON body, or the raw text.
	 * @param string $sourceSlug The slug of the source that was called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-route-distance-and-rdw-lookup/specs/connection-calls/spec.md#requirement-an-app-calls-a-connection-it-declared-through-a-typed-command-req-cc-001
	 */
	public function setResult(int $status, mixed $body, string $sourceSlug): void {
		$this->answer(result: ['status' => $status, 'body' => $body, 'sourceSlug' => $sourceSlug]);
	}//end setResult()
}//end class
