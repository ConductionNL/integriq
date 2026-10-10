<?php

/**
 * Integriq BodyCapturePolicy.
 *
 * Decides whether an outgoing call's request and response bodies are stored.
 * They are stored only while an administrator has an investigation window
 * open on the call's source, or when the calling code asked for it with
 * `logBody`. Everything else about the call (target, status, timing, size,
 * redacted headers, trace id) is always kept. Woo row 13.23, decision D5.
 *
 * The policy fails closed: no window, an unreadable window, a window in the
 * past, or a clock or settings read that throws all mean no body is stored.
 *
 * @category Outbound
 * @package  OCA\Integriq\Outbound\Call
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
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound\Call;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\Integriq\Service\RetentionDefaults;
use OCP\IAppConfig;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Stores call bodies only inside an investigation window.
 *
 * @spec openspec/specs/outbound-call-log/spec.md#requirement-every-outbound-call-is-a-record-with-its-request-and-its-response-req-ocd-001
 */
class BodyCapturePolicy {

	/**
	 * App setting: the longest window an administrator may open, in hours.
	 *
	 * @var string
	 */
	public const MAX_HOURS_KEY = 'call_log_body_capture_max_hours';

	/**
	 * App setting: how long captured bodies stay after the window ends, in days.
	 *
	 * @var string
	 */
	public const RETENTION_DAYS_KEY = 'call_log_body_retention_days';

	/**
	 * Default longest window, in hours.
	 *
	 * @var int
	 */
	public const DEFAULT_MAX_HOURS = 72;

	/**
	 * Default body retention after the window, in days.
	 *
	 * @var int
	 */
	public const DEFAULT_RETENTION_DAYS = 7;

	/**
	 * The keys of a Guzzle request config that carry a body.
	 *
	 * @var array<int,string>
	 */
	public const REQUEST_BODY_KEYS = ['body', 'json', 'form_params', 'multipart'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the window maximum and the body retention.
	 * @param ClockInterface|null $clock The clock; the system clock when absent.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ?ClockInterface $clock = null,
	) {

	}//end __construct()

	/**
	 * The current time.
	 *
	 * @return DateTimeImmutable Now.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	public function now(): DateTimeImmutable {
		if ($this->clock !== null) {
			return $this->clock->now();
		}

		return new DateTimeImmutable();

	}//end now()

	/**
	 * The longest window an administrator may open, in hours.
	 *
	 * @return int The maximum, at least 1.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	public function maxHours(): int {
		$hours = $this->appConfig->getValueInt('integriq', self::MAX_HOURS_KEY, self::DEFAULT_MAX_HOURS);
		if ($hours < 1) {
			return self::DEFAULT_MAX_HOURS;
		}

		return $hours;

	}//end maxHours()

	/**
	 * How long captured bodies stay after the window ends, in days.
	 *
	 * @return int The retention, at least 0.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-captured-bodies-age-out-and-the-record-stays-req-ocd-009
	 */
	public function retentionDays(): int {
		return max(0, $this->appConfig->getValueInt('integriq', self::RETENTION_DAYS_KEY, self::DEFAULT_RETENTION_DAYS));

	}//end retentionDays()

	/**
	 * The end of the source's investigation window, when it has a readable one.
	 *
	 * @param array<string,mixed> $sourceData The source.
	 *
	 * @return DateTimeImmutable|null The end, or null without a readable window.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	public function windowEnd(array $sourceData): ?DateTimeImmutable {
		$until = ($sourceData['bodyCaptureUntil'] ?? null);
		if (is_string($until) === false || trim($until) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($until);
		} catch (Throwable) {
			return null;
		}

	}//end windowEnd()

	/**
	 * Whether the source has an open investigation window at the given moment.
	 *
	 * @param array<string,mixed> $sourceData The source.
	 * @param DateTimeInterface $now The moment of the call.
	 *
	 * @return bool True only for a readable window that ends after now.
	 *
	 * @spec openspec/specs/outbound-call-log/spec.md#requirement-an-administrator-opens-a-bounded-investigation-window-per-source-req-ocd-008
	 */
	public function isOpen(array $sourceData, DateTimeInterface $now): bool {
		$end = $this->windowEnd(sourceData: $sourceData);
		if ($end === null) {
			return false;
		}

		return $end > $now;

	}//end isOpen()

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
	public function strip(array $request, array $response): array {
		foreach (self::REQUEST_BODY_KEYS as $key) {
			unset($request[$key]);
		}

		unset($response['body']);

		return ['request' => $request, 'response' => $response];

	}//end strip()

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
	public function apply(array $record, array $sourceData, bool $logBody, ?DateTimeInterface $errorExpires): array {
		$request = $this->asArray(value: ($record['request'] ?? []));
		$response = $this->asArray(value: ($record['response'] ?? []));
		$statusCode = (int)($record['statusCode'] ?? 0);

		$decision = $this->decide(sourceData: $sourceData, logBody: $logBody);

		$record['bodyCaptured'] = $decision['capture'];
		if ($decision['capture'] === false) {
			$stripped = $this->strip(request: $request, response: $response);
			$record['request'] = $stripped['request'];
			$record['response'] = $stripped['response'];
		}

		$bodyExpiresAt = $decision['expiresAt'];
		if ($this->isFailure(statusCode: $statusCode) === true) {
			$record['replayRequest'] = $request;
			if ($bodyExpiresAt === null) {
				$bodyExpiresAt = $this->replayExpiry(errorExpires: $errorExpires);
			}
		}

		if ($bodyExpiresAt !== null) {
			$record['bodyExpiresAt'] = $bodyExpiresAt;
		}

		return $record;

	}//end apply()

	/**
	 * Whether bodies are captured, and until when.
	 *
	 * @param array<string,mixed> $sourceData The source.
	 * @param bool $logBody Whether the calling code asked for the bodies.
	 *
	 * @return array{capture:bool,expiresAt:string|null} The decision.
	 */
	private function decide(array $sourceData, bool $logBody): array {
		if ($logBody === true) {
			return ['capture' => true, 'expiresAt' => null];
		}

		try {
			$now = $this->now();
			if ($this->isOpen(sourceData: $sourceData, now: $now) === false) {
				return ['capture' => false, 'expiresAt' => null];
			}

			$end = $this->windowEnd(sourceData: $sourceData);
			$expires = $end->add(new DateInterval('P' . $this->retentionDays() . 'D'));

			return ['capture' => true, 'expiresAt' => $expires->format('c')];
		} catch (Throwable) {
			// A clock or settings read that fails stores no body.
			return ['capture' => false, 'expiresAt' => null];
		}

	}//end decide()

	/**
	 * When a failed call's replay request ages out outside a window.
	 *
	 * @param DateTimeInterface|null $errorExpires The record's error expiry, null for never.
	 *
	 * @return string The moment, as ISO 8601.
	 */
	private function replayExpiry(?DateTimeInterface $errorExpires): string {
		if ($errorExpires !== null) {
			return $errorExpires->format('c');
		}

		// A record kept for ever still lets its replay request go after the
		// instance's default error retention.
		$seconds = intdiv(RetentionDefaults::CALL_LOG, 1000);

		return $this->now()->add(new DateInterval('PT' . $seconds . 'S'))->format('c');

	}//end replayExpiry()

	/**
	 * Whether a status code is a failed call.
	 *
	 * @param int $statusCode The status code; 0 when nothing answered.
	 *
	 * @return bool True for no answer or a 4xx or 5xx.
	 */
	private function isFailure(int $statusCode): bool {
		return ($statusCode < 100 || $statusCode >= 400);

	}//end isFailure()

	/**
	 * Coerce a request or response bag to an array.
	 *
	 * @param mixed $value The bag.
	 *
	 * @return array<string,mixed> The array.
	 */
	private function asArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];

	}//end asArray()

}//end class
