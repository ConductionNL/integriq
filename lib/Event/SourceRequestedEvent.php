<?php

/**
 * Integriq SourceRequested Event.
 *
 * The ADR-041 cross-app command for "give me a Source for this base URL". A
 * sibling app that still holds an outbound call to a plain URL (dossiq's
 * retired webhook steps are the first) cannot hand that URL to a flow step:
 * `openconnector.source-call` names a configured Source and a path relative
 * to it, never a URL. This event is how such an app obtains the Source.
 *
 * FIND OR CREATE, NEVER DUPLICATE. The Source is keyed by a slug derived from
 * the base URL (scheme, host and port), so asking twice for the same base
 * returns the same Source. An existing Source is returned as it is, whatever
 * an administrator has since changed on it, because it is theirs now.
 *
 * WHAT IS CREATED CARRIES NO CREDENTIALS. A created Source is enabled, has the
 * base URL as its location and, when asked, a request timeout. Anything that
 * needs a secret must be configured by an administrator through the credential
 * broker. The consumer MUST guard the dispatch with class_exists() and treat
 * an unhandled event as a refusal, never as a Source.
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
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/source-requested-event/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "find or create the Source for this base URL".
 *
 * Carries provenance (which app, which user, for what purpose) and a
 * synchronous result slot the in-process listener writes: `isHandled()`,
 * `getSourceId()`, `getSourceSlug()`, `wasCreated()`, or `getRefusal()` when
 * the request was refused.
 *
 * @spec openspec/specs/source-requested-event/spec.md
 */
class SourceRequestedEvent extends Event {
	/**
	 * Whether an Integriq listener handled the request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The uuid of the Source found or created.
	 *
	 * @var string|null
	 */
	private ?string $sourceId = null;

	/**
	 * The slug of the Source found or created.
	 *
	 * @var string|null
	 */
	private ?string $sourceSlug = null;

	/**
	 * Whether the Source was created by this request.
	 *
	 * @var bool
	 */
	private bool $created = false;

	/**
	 * Why the request was refused, when it was.
	 *
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string      $sourceApp      The requesting app id (e.g. `dossiq`).
	 * @param string      $baseUrl        The base URL: scheme, host and optional port, no path.
	 * @param string      $purpose        What the Source is for, written into its description.
	 * @param int|null    $timeoutSeconds The request timeout a created Source gets, or null for the default.
	 * @param string|null $userId         The acting Nextcloud user, or null for a system request.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $baseUrl,
		private readonly string $purpose,
		private readonly ?int $timeoutSeconds = null,
		private readonly ?string $userId = null,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The requesting app id.
	 *
	 * @return string The source app id.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The requested base URL.
	 *
	 * @return string The base URL.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function getBaseUrl(): string {
		return $this->baseUrl;
	}//end getBaseUrl()

	/**
	 * What the Source is for.
	 *
	 * @return string The purpose.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function getPurpose(): string {
		return $this->purpose;
	}//end getPurpose()

	/**
	 * The request timeout a created Source gets.
	 *
	 * @return int|null The timeout in seconds, or null for the default.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function getTimeoutSeconds(): ?int {
		return $this->timeoutSeconds;
	}//end getTimeoutSeconds()

	/**
	 * The acting Nextcloud user.
	 *
	 * @return string|null The user id, or null for a system request.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function getUserId(): ?string {
		return $this->userId;
	}//end getUserId()

	/**
	 * Record the Source that answers the request, and mark it handled.
	 *
	 * @param string $sourceId   The Source uuid.
	 * @param string $sourceSlug The Source slug.
	 * @param bool   $created    Whether this request created it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function setSource(string $sourceId, string $sourceSlug, bool $created): void {
		$this->sourceId = $sourceId;
		$this->sourceSlug = $sourceSlug;
		$this->created = $created;
		$this->refusal = null;
		$this->handled = true;
	}//end setSource()

	/**
	 * Record why the request was refused. The event stays unhandled.
	 *
	 * @param string $refusal The reason.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function refuse(string $refusal): void {
		$this->refusal = $refusal;
		$this->handled = false;
	}//end refuse()

	/**
	 * Whether an Integriq listener answered the request with a Source.
	 *
	 * @return bool True when handled.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * The Source uuid, once handled.
	 *
	 * @return string|null The uuid.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function getSourceId(): ?string {
		return $this->sourceId;
	}//end getSourceId()

	/**
	 * The Source slug, once handled.
	 *
	 * @return string|null The slug.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function getSourceSlug(): ?string {
		return $this->sourceSlug;
	}//end getSourceSlug()

	/**
	 * Whether this request created the Source.
	 *
	 * @return bool True when created, false when an existing one was found.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function wasCreated(): bool {
		return $this->created;
	}//end wasCreated()

	/**
	 * Why the request was refused.
	 *
	 * @return string|null The reason, or null when it was not refused.
	 *
	 * @spec openspec/specs/source-requested-event/spec.md
	 */
	public function getRefusal(): ?string {
		return $this->refusal;
	}//end getRefusal()
}//end class
