<?php

/**
 * Integriq CertificateExpiryAlertService: open a connection alert when a
 * source's client certificate comes within a threshold of its expiry.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-an-expiring-certificate-opens-an-alert-req-crun-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;

/**
 * Certificate expiry alerts, one per source, threshold and certificate.
 *
 * The expiry comes from {@see ConnectionCertificateReader}: keepiq for a
 * referenced certificate, the source itself for inline material. Writing the
 * `connection_alert` is all this does; its `certificate-expiring` created rule
 * notifies the group named for connection alerts. An alert is never opened
 * twice for the same certificate and threshold; a renewed certificate has
 * another expiry date, which clears the old alerts and arms the thresholds again.
 *
 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-an-expiring-certificate-opens-an-alert-req-crun-008
 */
class CertificateExpiryAlertService {

	/**
	 * The alert rule of a certificate expiry.
	 *
	 * @var string
	 */
	public const RULE = 'certificateExpiry';

	/**
	 * Days before expiry an alert opens, when the source names none.
	 *
	 * @var int[]
	 */
	public const DEFAULT_DAYS = [30, 14, 7];

	/**
	 * Records read per call (every read is bounded).
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService             $objectService Reads sources and alerts, writes alerts.
	 * @param ConnectionCertificateReader $certificates  Reads a source's certificate expiry.
	 * @param LoggerInterface             $logger        Logs an alert that could not be written.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly ConnectionCertificateReader $certificates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Open the alerts due now and clear those of a replaced certificate.
	 *
	 * @param DateTimeImmutable $now The moment of the check.
	 *
	 * @return array{opened: int, cleared: int}
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-an-expiring-certificate-opens-an-alert-req-crun-008
	 */
	public function evaluate(DateTimeImmutable $now): array {
		$outcome = ['opened' => 0, 'cleared' => 0];
		foreach ($this->read(schema: 'source', filters: []) as $entity) {
			$source = $entity->getObject();
			if (is_array($source) === false || ($source['isEnabled'] ?? true) === false) {
				continue;
			}

			$result = $this->checkSource(sourceId: (string)$entity->getUuid(), source: $source, now: $now);
			$outcome['opened'] += $result['opened'];
			$outcome['cleared'] += $result['cleared'];
		}

		return $outcome;
	}//end evaluate()

	/**
	 * The thresholds of a source, tightest last, or the defaults.
	 *
	 * @param array<string,mixed> $source The source's data.
	 *
	 * @return int[]
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-an-expiring-certificate-opens-an-alert-req-crun-008
	 */
	public static function thresholdsOf(array $source): array {
		$configured = (($source['alertThresholds'] ?? [])['certificateExpiryDays'] ?? null);
		$days = [];
		if (is_array($configured) === true) {
			foreach ($configured as $value) {
				if (is_int($value) === true && $value > 0) {
					$days[] = $value;
				}
			}
		}

		if ($days === []) {
			$days = self::DEFAULT_DAYS;
		}

		$days = array_values(array_unique($days));
		rsort($days);

		return $days;
	}//end thresholdsOf()

	/**
	 * Check one source.
	 *
	 * @param string              $sourceId The source uuid.
	 * @param array<string,mixed> $source   The source's data.
	 * @param DateTimeImmutable   $now      The moment of the check.
	 *
	 * @return array{opened: int, cleared: int}
	 */
	private function checkSource(string $sourceId, array $source, DateTimeImmutable $now): array {
		$outcome = ['opened' => 0, 'cleared' => 0];
		$certificate = $this->certificates->certificate(data: $source, now: $now);
		$expiresAt = ($certificate['expiresAt'] ?? null);
		$daysLeft = ($certificate['daysLeft'] ?? null);
		if (($certificate['configured'] ?? false) !== true || is_string($expiresAt) === false || is_int($daysLeft) === false) {
			return $outcome;
		}

		$alerts = $this->read(schema: ConnectionAlertService::SCHEMA, filters: ['subject' => $sourceId, 'rule' => self::RULE]);
		$warned = [];
		foreach ($alerts as $alert) {
			$data = $alert->getObject();
			if (($data['expiresAt'] ?? null) === $expiresAt) {
				$warned[] = (int)($data['threshold'] ?? 0);
				continue;
			}

			if (($data['state'] ?? null) === 'open') {
				$this->clear(alert: $alert, now: $now);
				$outcome['cleared']++;
			}
		}

		$threshold = $this->dueThreshold(thresholds: self::thresholdsOf(source: $source), daysLeft: $daysLeft, warned: $warned);
		if ($threshold === null) {
			return $outcome;
		}

		$this->save(
			alert: [
				'subjectType' => 'source',
				'subject' => $sourceId,
				'subjectName' => (string)($source['name'] ?? ''),
				'rule' => self::RULE,
				'count' => $daysLeft,
				'threshold' => $threshold,
				'windowMinutes' => ($threshold * 1440),
				'expiresAt' => $expiresAt,
				'state' => 'open',
				'openedAt' => $now->format('c'),
			],
			uuid: null
		);
		$outcome['opened']++;

		return $outcome;
	}//end checkSource()

	/**
	 * The tightest threshold passed that has not warned yet for this certificate.
	 *
	 * A threshold at or beyond one that already warned stays quiet, so a
	 * certificate first seen 5 days before expiry warns once, for 7 days.
	 *
	 * @param int[] $thresholds The thresholds, widest first.
	 * @param int   $daysLeft   Days left before expiry.
	 * @param int[] $warned     Thresholds already warned for this certificate.
	 *
	 * @return int|null
	 */
	private function dueThreshold(array $thresholds, int $daysLeft, array $warned): ?int {
		$passed = array_values(array_filter($thresholds, static fn (int $days): bool => $daysLeft <= $days));
		if ($passed === []) {
			return null;
		}

		$tightest = min($passed);
		foreach ($warned as $days) {
			if ($days > 0 && $days <= $tightest) {
				return null;
			}
		}

		return $tightest;
	}//end dueThreshold()

	/**
	 * Clear the alert of a certificate that was replaced.
	 *
	 * @param object            $alert The open alert.
	 * @param DateTimeImmutable $now   The moment of the check.
	 *
	 * @return void
	 */
	private function clear(object $alert, DateTimeImmutable $now): void {
		$data = $alert->getObject();
		$data['state'] = 'cleared';
		$data['clearedAt'] = $now->format('c');
		$this->save(alert: $data, uuid: (string)$alert->getUuid());
	}//end clear()

	/**
	 * Read one bounded page of a schema.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Extra filters.
	 *
	 * @return array<int,object>
	 */
	private function read(string $schema, array $filters): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => ConnectionAlertService::REGISTER, 'schema' => $schema], $filters),
				'limit' => self::PAGE_SIZE,
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(($result['results'] ?? $result));
	}//end read()

	/**
	 * Write an alert; a failure is logged and does not stop the other sources.
	 *
	 * @param array<string,mixed> $alert The alert.
	 * @param string|null         $uuid  The alert to update, or null for a new one.
	 *
	 * @return void
	 */
	private function save(array $alert, ?string $uuid): void {
		try {
			$this->objectService->saveObject(
				object: $alert,
				register: ConnectionAlertService::REGISTER,
				schema: ConnectionAlertService::SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
			);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'[CertificateExpiryAlertService] could not write a certificate expiry alert: ' . $exception->getMessage(),
				['subject' => ($alert['subject'] ?? null), 'threshold' => ($alert['threshold'] ?? null)]
			);
		}
	}//end save()
}//end class
