<?php

/**
 * Integriq exchange job request.
 *
 * The values a new exchange job is built from: who owns it, where it goes,
 * what it covers and who asked for it.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

use OCA\Integriq\Event\ExchangeJobRequestedEvent;

/**
 * One request for a new exchange job. Built only by its from* constructors,
 * which set each field once; nothing changes it afterwards.
 *
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
 */
final class ExchangeJobRequest {

	/**
	 * The owning app.
	 *
	 * @var string
	 */
	public string $ownerApp = '';

	/**
	 * The exchange target.
	 *
	 * @var string
	 */
	public string $target = '';

	/**
	 * The direction.
	 *
	 * @var string
	 */
	public string $direction = '';

	/**
	 * The owner's reference.
	 *
	 * @var string
	 */
	public string $ownerRef = '';

	/**
	 * The scope.
	 *
	 * @var array<string,mixed>
	 */
	public array $scope = [];

	/**
	 * The mapping slug the owner named, or null when it named none.
	 *
	 * @var string|null
	 */
	public ?string $mappingSlug = null;

	/**
	 * Who asked for the job.
	 *
	 * @var string
	 */
	public string $requestedBy = '';

	/**
	 * The job's label, empty for the default.
	 *
	 * @var string
	 */
	public string $name = '';

	/**
	 * The request an ExchangeJobRequestedEvent carries.
	 *
	 * @param ExchangeJobRequestedEvent $event The event.
	 *
	 * @return self The request.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-001-an-exchange-job-is-a-tagged-native-job
	 */
	public static function fromEvent(ExchangeJobRequestedEvent $event): self {
		$request = new self();
		$request->ownerApp    = $event->getOwnerApp();
		$request->target      = $event->getTarget();
		$request->direction   = $event->getDirection();
		$request->ownerRef    = $event->getOwnerRef();
		$request->scope       = $event->getScope();
		$request->mappingSlug = $event->getMappingSlug();
		$request->requestedBy = $event->getRequestedBy();
		$request->name        = $event->getName();
		return $request;

	}//end fromEvent()

	/**
	 * The request stored job data describes. A mapping that is not a string
	 * counts as none named.
	 *
	 * @param array<string,mixed> $data The job data.
	 *
	 * @return self The request.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-006-a-rejected-record-is-a-dead-letter-with-a-correction-loop
	 */
	public static function fromJobData(array $data): self {
		$request = new self();
		$request->ownerApp    = (string)($data['ownerApp'] ?? '');
		$request->target      = (string)($data['exchangeTarget'] ?? '');
		$request->direction   = (string)($data['exchangeDirection'] ?? '');
		$request->ownerRef    = (string)($data['ownerRef'] ?? '');
		$request->scope       = (array)($data['exchangeScope'] ?? []);
		$request->mappingSlug = self::slugOrNull(slug: ($data['exchangeMapping'] ?? null));
		$request->requestedBy = (string)($data['requestedBy'] ?? '');
		$request->name        = (string)($data['name'] ?? '');
		return $request;

	}//end fromJobData()

	/**
	 * The slug when it is a string, otherwise null.
	 *
	 * @param mixed $slug The stored slug.
	 *
	 * @return string|null The slug, or null.
	 */
	private static function slugOrNull(mixed $slug): ?string {
		if (is_string($slug) === false) {
			return null;
		}

		return $slug;

	}//end slugOrNull()
}//end class
