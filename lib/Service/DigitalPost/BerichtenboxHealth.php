<?php

/**
 * Integriq — what an administrator must know about the Berichtenbox sources.
 *
 * @category Service
 * @package  OCA\Integriq\Service\DigitalPost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\DigitalPost;

use DateTimeImmutable;
use OCA\Integriq\Exception\MtlsConfigurationException;
use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Throwable;

/**
 * Two things go wrong slowly, so they are reported, not acted on.
 *
 * A letter that waits more than 24 hours for a Logius result is named, and its
 * status is left as it is: integriq never guesses that a letter was lost (D7,
 * open question Q5). A certificate that expires within 30 days is named before
 * it stops every send.
 *
 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
 */
class BerichtenboxHealth {
	public const RESULT_WAIT_HOURS = 24;

	public const CERTIFICATE_WARN_DAYS = 30;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService Lists sources and letters.
	 * @param ConnectionStore $connectionStore Reads a source raw.
	 * @param MtlsConfigResolver $mtlsConfigResolver Opens the stored certificate.
	 * @param DigitalPostAccount $account The account letters are read as.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly ConnectionStore $connectionStore,
		private readonly MtlsConfigResolver $mtlsConfigResolver,
		private readonly DigitalPostAccount $account,
	) {
	}//end __construct()

	/**
	 * The findings.
	 *
	 * @param DateTimeImmutable $now The time to measure from.
	 *
	 * @return array{sources:int,waiting:int,certificates:array<int,array{slug:string,problem:string,validTo:string}>}
	 *
	 * @spec openspec/changes/berichtenbox-client/specs/digital-post-adapter/spec.md#requirement-logius-results-decide-the-status-and-a-berichtenbox-letter-is-never-read-req-dpa-011
	 */
	public function findings(DateTimeImmutable $now): array {
		$sources = $this->sources();
		$certificates = [];
		foreach ($sources as $slug => $config) {
			$problem = $this->certificateProblem(config: $config, now: $now);
			if ($problem !== null) {
				$certificates[] = ['slug' => $slug] + $problem;
			}
		}

		$waiting = 0;
		if ($sources !== [] && $this->account->describe()['state'] === 'ok') {
			$this->account->runOrRefuse(
				what: 'Berichtenbox result check',
				operation: function () use ($now, &$waiting): void {
					$waiting = $this->lettersWaiting(now: $now);
				},
				refuse: static function (string $reason): void {
					unset($reason);
				}
			);
		}

		return ['sources' => count($sources), 'waiting' => $waiting, 'certificates' => $certificates];
	}//end findings()

	/**
	 * Every Berichtenbox source, raw, by slug. A configuration read, so RBAC is off for it.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function sources(): array {
		try {
			$result = $this->objectService->findAll(
				config: ['filters' => ['register' => ConnectionStore::REGISTER, 'schema' => 'source']],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable) {
			return [];
		}

		$sources = [];
		foreach (($result['results'] ?? $result) as $source) {
			if ($source instanceof ObjectEntity === false) {
				continue;
			}

			$data = $source->getObject();
			$config = ($data['configuration'] ?? []);
			if (is_array($config) === false || (string)($config['providerId'] ?? '') !== BerichtenboxProvider::ID) {
				continue;
			}

			$raw = $this->connectionStore->readSourceRaw(source: $source)->getObject();
			$sources[(string)($data['slug'] ?? $source->getUuid())] = (array)($raw['configuration'] ?? $config);
		}

		return $sources;
	}//end sources()

	/**
	 * What is wrong with a source's certificate, or null.
	 *
	 * @param array<string,mixed> $config The raw configuration.
	 * @param DateTimeImmutable $now The time.
	 *
	 * @return array{problem:string,validTo:string}|null
	 */
	private function certificateProblem(array $config, DateTimeImmutable $now): ?array {
		$authentication = (array)($config['authentication'] ?? []);
		if (isset($authentication['mtls']) === false) {
			return ['problem' => 'missing', 'validTo' => ''];
		}

		try {
			$bundle = $this->mtlsConfigResolver->resolve(authConfig: $authentication);
		} catch (MtlsConfigurationException $e) {
			return ['problem' => $e->getErrorCode(), 'validTo' => ''];
		}

		$validTo = (int)((array)openssl_x509_parse($bundle->certificatePem))['validTo_time_t'];
		if ($validTo - $now->getTimestamp() < self::CERTIFICATE_WARN_DAYS * 86400) {
			return ['problem' => 'expires-soon', 'validTo' => gmdate('Y-m-d', $validTo)];
		}

		return null;
	}//end certificateProblem()

	/**
	 * Live Berichtenbox letters still `sent` after the wait.
	 *
	 * @param DateTimeImmutable $now The time.
	 *
	 * @return int How many.
	 */
	private function lettersWaiting(DateTimeImmutable $now): int {
		try {
			$result = $this->objectService->findAll(
				config: ['filters' => ['register' => DigitalPostService::REGISTER, 'schema' => DigitalPostService::SCHEMA], 'limit' => 1000]
			);
		} catch (Throwable) {
			return 0;
		}

		$limit = $now->getTimestamp() - (self::RESULT_WAIT_HOURS * 3600);
		$waiting = 0;
		foreach (($result['results'] ?? $result) as $entity) {
			$letter = ($entity instanceof ObjectEntity ? $entity->getObject() : (array)$entity);
			if ((string)($letter['providerId'] ?? '') !== BerichtenboxProvider::ID
				|| (string)($letter['status'] ?? '') !== DigitalPostResult::STATUS_SENT
				|| ($letter['simulated'] ?? false) === true
			) {
				continue;
			}

			$created = strtotime((string)($letter['created'] ?? ''));
			if ($created !== false && $created < $limit) {
				$waiting++;
			}
		}

		return $waiting;
	}//end lettersWaiting()
}//end class
