<?php

/**
 * Contract of the BodyCapturePolicy.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound\Call
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Public operations of {@see BodyCapturePolicy} that other components call.
 */
interface BodyCapturePolicyInterface {
	/**
	 * The current time.
	 *
	 * @return DateTimeImmutable Now.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	public function now(): DateTimeImmutable;

	/**
	 * The longest window an administrator may open, in hours.
	 *
	 * @return int The maximum, at least 1.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	public function maxHours(): int;

	/**
	 * How long captured bodies stay after the window ends, in days.
	 *
	 * @return int The retention, at least 0.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	public function retentionDays(): int;

	/**
	 * The end of the source's investigation window, when it has a readable one.
	 *
	 * @param array<string,mixed> $sourceData The source.
	 *
	 * @return DateTimeImmutable|null The end, or null without a readable window.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	public function windowEnd(array $sourceData): ?DateTimeImmutable;

	/**
	 * Whether the source has an open investigation window at the given moment.
	 *
	 * @param array<string,mixed> $sourceData The source.
	 * @param DateTimeInterface $now The moment of the call.
	 *
	 * A window that ends further away than the instance maximum counts as
	 * closed. BodyCaptureController::open() refuses such a window, but a
	 * `bodyCaptureUntil` written another way (the generic source API, a
	 * source update, an import) skips that check, and would otherwise open a
	 * window of any length without a reason.
	 *
	 * @return bool True only for a readable window that ends after now and within the maximum.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	public function isOpen(array $sourceData, DateTimeInterface $now): bool;

	/**
	 * Remove the bodies from a stored request and response.
	 *
	 * @param array<string,mixed> $request The request as it would be stored.
	 * @param array<string,mixed> $response The response as it would be stored.
	 *
	 * @return array{request:array<string,mixed>,response:array<string,mixed>} Both, without bodies.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
	 */
	public function strip(array $request, array $response): array;

	/**
	 * The record of a call whose caller withholds its bodies: no body, no
	 * replay request, and the asking user by name. Run it after {@see apply()},
	 * so neither a window nor `logBody` puts a body back.
	 *
	 * @param array<string,mixed> $record The call record.
	 *
	 * @return array<string,mixed> The record to write.
	 *
	 * @spec openspec/changes/call-log-without-bodies/specs/outbound-call-log/spec.md#requirement-a-caller-can-have-a-call-recorded-without-any-body
	 */
	public function withheldRecord(array $record): array;

	/**
	 * Reduce a record to what may be kept of a call whose caller withholds its bodies.
	 *
	 * The request keeps its method and its URL without the query string. The
	 * response keeps its status, timing and size. Everything else goes: both
	 * bodies, the headers, the query, the replay request and the body expiry.
	 * An identifier in the URL path stays, so a caller that withholds bodies
	 * must send its identifiers in the body.
	 *
	 * @param array<string,mixed> $record The call record, or its request and response only.
	 *
	 * @return array<string,mixed> The record without bodies.
	 *
	 * @spec openspec/changes/call-log-without-bodies/specs/outbound-call-log/spec.md#requirement-a-caller-can-have-a-call-recorded-without-any-body
	 */
	public function withhold(array $record): array;

	/**
	 * Apply the policy to a call record before it is written.
	 *
	 * Inside a window both bodies stay, `bodyCaptured` is true and
	 * `bodyExpiresAt` is the window end plus the body retention. With
	 * `logBody` the bodies stay for the life of the record. Otherwise both
	 * bodies go. A failed call always keeps the request it sent as
	 * `replayRequest`, so it can be replayed; outside a window that request
	 * ages out with the error retention.
	 *
	 * @param array<string,mixed> $record The call record, `request` and `response` already redacted.
	 * @param array<string,mixed> $sourceData The source the call went to.
	 * @param bool $logBody Whether the calling code asked for the bodies.
	 * @param DateTimeInterface|null $errorExpires When a failed call's record expires, null for never.
	 *
	 * @return array<string,mixed> The record to write.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	public function apply(array $record, array $sourceData, bool $logBody, ?DateTimeInterface $errorExpires): array;

	/**
	 * Whether a call's bodies may be kept at all: inside the source's open window, or because the caller asked.
	 *
	 * The same decision {@see apply()} makes for the call record, for a
	 * consumer that stores the request and response elsewhere, such as the
	 * execution trace step of the call.
	 *
	 * @param array<string,mixed> $sourceData The source the call went to.
	 * @param bool $logBody Whether the calling code asked for the bodies.
	 *
	 * @return bool True when the bodies may be stored.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
	 */
	public function captures(array $sourceData, bool $logBody): bool;
}
