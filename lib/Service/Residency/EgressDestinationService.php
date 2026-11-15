<?php

/**
 * Integriq EgressDestinationService.
 *
 * Lists every outbound destination integriq sends data to, with where it
 * sits: every enabled source, and every host the call log saw in the last
 * 30 days. A host that matches no source is an undeclared destination with
 * zone unknown. The verdict is "eu-only" only when every destination is in
 * the EU; unknown never counts as EU. It also records an administrator's
 * residency on a source, and switches EU-only mode, which it refuses to
 * switch on while the verdict is not proven.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Residency
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
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Residency;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Integriq\Service\ConnectionRegistryService;
use OCA\Integriq\Service\ConnectionStoreInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;

/**
 * The destinations page, its verdict and EU-only mode.
 *
 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
 */
class EgressDestinationService {

	/**
	 * The register sources and call logs live in.
	 *
	 * @var string
	 */
	private const REGISTER = 'integriq';

	/**
	 * Verdict when every destination is in the EU.
	 *
	 * @var string
	 */
	public const VERDICT_EU_ONLY = 'eu-only';

	/**
	 * Verdict when any destination is not shown to be in the EU.
	 *
	 * @var string
	 */
	public const VERDICT_NOT_PROVEN = 'not-proven';

	/**
	 * How far back the call log is read.
	 *
	 * @var string
	 */
	private const CALL_LOG_WINDOW = '-30 days';

	/**
	 * Records read per page (every read is bounded).
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The most call log pages one listing reads.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 20;

	/**
	 * The most sources one listing reads.
	 *
	 * @var int
	 */
	private const SOURCE_LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService          $objectService Reads sources and call logs.
	 * @param ConnectionStoreInterface $store         Reads a source raw and writes its residency.
	 * @param IAppConfig               $appConfig     Stores EU-only mode.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly ConnectionStoreInterface $store,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every destination with its location, the verdict and the counts per zone.
	 *
	 * @return array{destinations: array<int,array<string,mixed>>, verdict: string, counts: array<string,int>, euOnly: bool}
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-one-page-lists-every-outbound-destination-with-its-location-req-res-002
	 */
	public function list(): array {
		$destinations = $this->sourceDestinations();
		$destinations = $this->addCallLogHosts(destinations: $destinations);
		$destinations = array_values($destinations);

		$counts = array_fill_keys(ResidencyZones::ZONES, 0);
		foreach ($destinations as $destination) {
			$counts[$destination['zone']]++;
		}

		$verdict = self::VERDICT_EU_ONLY;
		if (count($destinations) !== $counts[ResidencyZones::ZONE_EU]) {
			$verdict = self::VERDICT_NOT_PROVEN;
		}

		return [
			'destinations' => $destinations,
			'verdict' => $verdict,
			'counts' => $counts,
			'euOnly' => (new EgressResidencyPolicy(appConfig: $this->appConfig))->isEuOnly(),
		];
	}//end list()

	/**
	 * Switch EU-only mode. Switching it on is refused while any destination is not in the EU.
	 *
	 * @param bool $enabled The wanted state.
	 *
	 * @return array{euOnly: bool, blocking: array<int,array<string,mixed>>} The stored state and,
	 *     when refused, the destinations that block it.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-an-administrator-can-enforce-eu-only-egress-req-res-003
	 */
	public function setEuOnly(bool $enabled): array {
		if ($enabled === true) {
			$blocking = array_values(
				array_filter(
					$this->list()['destinations'],
					static fn (array $destination): bool => $destination['zone'] !== ResidencyZones::ZONE_EU
				)
			);
			if ($blocking !== []) {
				return ['euOnly' => false, 'blocking' => $blocking];
			}
		}

		$this->appConfig->setValueBool('integriq', EgressResidencyPolicy::SETTING, $enabled);

		return ['euOnly' => $enabled, 'blocking' => []];
	}//end setEuOnly()

	/**
	 * Record an administrator's residency on a source.
	 *
	 * @param string $sourceId The source uuid.
	 * @param string $country  The ISO 3166-1 alpha-2 code.
	 * @param string $evidence A URL or a short text.
	 * @param string $userId   The administrator.
	 *
	 * @return array<string,string>|null The stored residency, or null when the source does not exist.
	 *
	 * @throws InvalidArgumentException When the country is not a valid code.
	 *
	 * @spec openspec/changes/connections-declare-data-residency/specs/data-residency/spec.md#requirement-a-source-records-its-residency-with-a-basis-and-evidence-req-res-001
	 */
	public function setSourceResidency(string $sourceId, string $country, string $evidence, string $userId): ?array {
		if (ResidencyZones::isCountry(country: $country) === false) {
			throw new InvalidArgumentException('The country must be an ISO 3166-1 alpha-2 code in upper case.');
		}

		$source = $this->store->findSourceRaw(uuid: $sourceId);
		if ($source === null) {
			return null;
		}

		$residency = [
			'country' => $country,
			'basis' => ConnectionRegistryService::RESIDENCY_ADMIN,
			'evidence' => $evidence,
			'recordedBy' => $userId,
			'recordedAt' => (new DateTimeImmutable())->format('c'),
		];
		$this->store->saveSourceResidency(source: $source, residency: $residency);

		return $residency;
	}//end setSourceResidency()

	/**
	 * One destination per enabled source, keyed by host.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function sourceDestinations(): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => 'source'],
				'limit' => self::SOURCE_LIMIT,
			],
			_rbac: false,
			_multitenancy: false
		);

		$destinations = [];
		foreach (($result['results'] ?? $result) as $entity) {
			$data = $this->enabledSourceData(entity: $entity);
			if ($data === null) {
				continue;
			}

			$key = (string)$entity->getUuid();
			$destinations[$key] = $this->sourceDestination(sourceId: $key, data: $data);
		}

		return $destinations;
	}//end sourceDestinations()

	/**
	 * The data of an enabled source, or null for anything else.
	 *
	 * @param mixed $entity A search result.
	 *
	 * @return array<string,mixed>|null
	 */
	private function enabledSourceData(mixed $entity): ?array {
		if ($entity instanceof ObjectEntity === false) {
			return null;
		}

		$data = $entity->getObject();
		if (is_array($data) === false || ($data['isEnabled'] ?? false) !== true) {
			return null;
		}

		return $data;
	}//end enabledSourceData()

	/**
	 * The destination row of one source.
	 *
	 * @param string              $sourceId The source's uuid.
	 * @param array<string,mixed> $data     The source's data.
	 *
	 * @return array<string,mixed>
	 */
	private function sourceDestination(string $sourceId, array $data): array {
		$residency = $data['residency'] ?? [];
		if (is_array($residency) === false) {
			$residency = [];
		}

		$country = ResidencyZones::countryOf(residency: $residency);

		return [
			'host' => $this->hostOf(url: (string)($data['location'] ?? '')),
			'name' => (string)($data['name'] ?? ''),
			'sourceId' => $sourceId,
			'country' => $country,
			'basis' => (string)($residency['basis'] ?? ''),
			'evidence' => (string)($residency['evidence'] ?? ''),
			'zone' => ResidencyZones::zoneOf(country: $country),
			'lastCallAt' => $this->stringOrNull(value: $data['lastCall'] ?? null),
		];
	}//end sourceDestination()

	/**
	 * Add the call log hosts of the last 30 days that no source covers.
	 *
	 * @param array<string,array<string,mixed>> $destinations The source destinations.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function addCallLogHosts(array $destinations): array {
		$known = [];
		foreach ($destinations as $destination) {
			$known[$destination['host']] = true;
		}

		foreach ($this->recentCallHosts() as $host => $lastCallAt) {
			if (isset($known[$host]) === true) {
				continue;
			}

			$destinations['host:' . $host] = [
				'host' => $host,
				'name' => '',
				'sourceId' => null,
				'country' => null,
				'basis' => '',
				'evidence' => '',
				'zone' => ResidencyZones::ZONE_UNKNOWN,
				'lastCallAt' => $lastCallAt,
			];
		}

		return $destinations;
	}//end addCallLogHosts()

	/**
	 * Every host the outbound call log saw in the window, with its latest call.
	 *
	 * Read newest first in bounded pages, stopping at the first record older
	 * than the window.
	 *
	 * @return array<string,string> Latest call time per host.
	 */
	private function recentCallHosts(): array {
		$since = (new DateTimeImmutable(self::CALL_LOG_WINDOW))->getTimestamp();
		$hosts = [];

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$result = $this->objectService->findAll(
				config: [
					'filters' => ['register' => self::REGISTER, 'schema' => 'call_log', 'direction' => 'outbound'],
					'sort' => ['created' => 'DESC'],
					'limit' => self::PAGE_SIZE,
					'offset' => ($page * self::PAGE_SIZE),
				],
				_rbac: false,
				_multitenancy: false
			);
			$records = array_values(($result['results'] ?? $result));

			if ($this->collectCallHosts(records: $records, since: $since, hosts: $hosts) === false) {
				return $hosts;
			}

			if (count($records) < self::PAGE_SIZE) {
				break;
			}
		}//end for

		return $hosts;
	}//end recentCallHosts()

	/**
	 * Add the hosts of one page of call log records, newest first.
	 *
	 * @param array<int,mixed>     $records The page.
	 * @param int                  $since   The window's start (unix time).
	 * @param array<string,string> $hosts   Latest call time per host, added to.
	 *
	 * @return bool False when a record older than the window was reached.
	 */
	private function collectCallHosts(array $records, int $since, array &$hosts): bool {
		foreach ($records as $entity) {
			if ($entity instanceof ObjectEntity === false || is_array($entity->getObject()) === false) {
				continue;
			}

			$data = $entity->getObject();
			$created = $this->createdWithin(data: $data, since: $since);
			if ($created === null) {
				return false;
			}

			$this->rememberHost(hosts: $hosts, host: $this->hostOf(url: $this->callUrl(data: $data)), created: $created);
		}

		return true;
	}//end collectCallHosts()

	/**
	 * A record's creation time when it falls in the window, else null.
	 *
	 * @param array<string,mixed> $data  The call log record.
	 * @param int                 $since The window's start (unix time).
	 *
	 * @return string|null
	 */
	private function createdWithin(array $data, int $since): ?string {
		$created = (string)($data['created'] ?? '');
		if ($created === '' || strtotime($created) < $since) {
			return null;
		}

		return $created;
	}//end createdWithin()

	/**
	 * Record a host's latest call once; the first (newest) one seen wins.
	 *
	 * @param array<string,string> $hosts   Latest call time per host, added to.
	 * @param string               $host    The host, '' when the URL has none.
	 * @param string               $created The call's creation time.
	 *
	 * @return void
	 */
	private function rememberHost(array &$hosts, string $host, string $created): void {
		if ($host !== '' && isset($hosts[$host]) === false) {
			$hosts[$host] = $created;
		}
	}//end rememberHost()

	/**
	 * The URL a call log record went to.
	 *
	 * @param array<string,mixed> $data The call log record.
	 *
	 * @return string The URL, or '' when the record carries none.
	 */
	private function callUrl(array $data): string {
		$request = ($data['request'] ?? null);
		if (is_array($request) === true && is_string($request['url'] ?? null) === true) {
			return $request['url'];
		}

		return (string)($data['target'] ?? '');
	}//end callUrl()

	/**
	 * The lower-case host of a URL, or the trimmed value when it has none.
	 *
	 * @param string $url The URL or connection string.
	 *
	 * @return string The host.
	 */
	private function hostOf(string $url): string {
		$host = parse_url(trim($url), PHP_URL_HOST);
		if (is_string($host) === true && $host !== '') {
			return strtolower($host);
		}

		return strtolower(trim($url));
	}//end hostOf()

	/**
	 * A string value, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end stringOrNull()
}//end class
