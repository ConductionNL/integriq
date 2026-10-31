<?php

/**
 * Integriq ConnectionCertificateReader: where a source's client certificate is
 * kept and when it expires, for the connection monitor.
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
 * @spec openspec/specs/connection-run-monitoring/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use OCA\Integriq\Service\Mtls\MtlsConfigResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A keepiq reference is asked of keepiq; inline material is read directly; certificate material is never returned.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md
 */
class ConnectionCertificateReader {

	/**
	 * keepiq's in-process expiry request (keepiq connection-certificate-expiry-for-integriq).
	 */
	public const KEEPIQ_EXPIRY_EVENT = 'OCA\\Keepiq\\Event\\CredentialExpiryRequestedEvent';

	/**
	 * Days before expiry a certificate is shown as expiring soon.
	 */
	private const WARN_DAYS = 60;

	/**
	 * Constructor.
	 *
	 * @param MtlsConfigResolver $mtls       Reads the expiry of inline certificate material.
	 * @param IEventDispatcher   $dispatcher Asks keepiq for a referenced certificate's expiry.
	 * @param IL10N              $l10n       Translates the labels.
	 * @param LoggerInterface    $logger     Logger.
	 */
	public function __construct(
		private readonly MtlsConfigResolver $mtls,
		private readonly IEventDispatcher $dispatcher,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The source's client certificate: where it is kept and when it expires.
	 *
	 * @param array<string, mixed> $data The source object.
	 * @param DateTimeImmutable    $now  Now.
	 *
	 * @return array<string, mixed> The certificate block; `configured: false` without one.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-when-its-certificate-expires-req-crun-007
	 */
	public function certificate(array $data, DateTimeImmutable $now): array {
		$configuration = ($data['configuration'] ?? []);
		if (is_array($configuration) === false) {
			$configuration = [];
		}

		$authentication = ($configuration['authentication'] ?? []);
		if (is_array($authentication) === false) {
			$authentication = [];
		}

		$reference = $this->certificateReference(authentication: $authentication);
		if ($reference !== null) {
			$organisation = ($data['organisation'] ?? '');
			if (is_string($organisation) === false) {
				$organisation = '';
			}

			$answer = $this->askKeepiq(credentialId: $reference, organisation: $organisation);
			if ($answer === null) {
				return $this->certificateBlock(
					keptIn: 'keepiq',
					notAfter: null,
					renewUrl: null,
					now: $now,
					unknownReason: $this->l10n->t('Expiry unknown: keepiq did not answer.')
				);
			}

			return $this->certificateBlock(keptIn: 'keepiq', notAfter: $answer['notAfter'], renewUrl: $answer['renewUrl'], now: $now);
		}

		$notAfter = $this->mtls->certificateNotAfter(authConfig: $authentication);
		if ($notAfter === null) {
			$notAfter = $this->pemNotAfter(pem: ($configuration['cert'] ?? null));
		}

		if ($notAfter === null) {
			return ['configured' => false, 'none' => true];
		}

		return $this->certificateBlock(
			keptIn: 'source',
			notAfter: (new DateTimeImmutable())->setTimestamp($notAfter)->format('c'),
			renewUrl: null,
			now: $now
		);
	}//end certificate()

	/**
	 * The keepiq credential id of the source's mTLS certificate, when it is a reference.
	 *
	 * @param array<string, mixed> $authentication The source's authentication block.
	 *
	 * @return string|null The credential id.
	 */
	private function certificateReference(array $authentication): ?string {
		$mtls = ($authentication['mtls'] ?? null);
		if (is_array($mtls) === false || is_array(($mtls['certificate'] ?? null)) === false) {
			return null;
		}

		$credentialId = (($mtls['certificate']['credentialRef'] ?? [])['credentialId'] ?? null);
		if (is_string($credentialId) === false || $credentialId === '') {
			return null;
		}

		return $credentialId;
	}//end certificateReference()

	/**
	 * Ask keepiq, in process, when a referenced certificate expires.
	 *
	 * @param string $credentialId The keepiq credential id.
	 * @param string $organisation The organisation the source acts for, empty when it names none.
	 *
	 * @return array{notAfter: string, renewUrl: string|null}|null The answer, or null when keepiq gave none.
	 */
	private function askKeepiq(string $credentialId, string $organisation): ?array {
		$class = self::KEEPIQ_EXPIRY_EVENT;
		if (class_exists($class) === false) {
			return null;
		}

		try {
			$event = new $class($credentialId, $organisation);
			if ($event instanceof Event === false
				|| method_exists($event, 'getNotAfter') === false
				|| method_exists($event, 'getRenewUrl') === false
			) {
				return null;
			}

			$this->dispatcher->dispatchTyped($event);
			$notAfter = $event->getNotAfter();
			$renewUrl = $event->getRenewUrl();
		} catch (Throwable $e) {
			$this->logger->warning('[ConnectionMonitorService] keepiq could not answer a certificate expiry: ' . $e->getMessage());
			return null;
		}

		if (is_string($notAfter) === false || $notAfter === '') {
			return null;
		}

		if (is_string($renewUrl) === false) {
			$renewUrl = null;
		}

		return ['notAfter' => $notAfter, 'renewUrl' => $renewUrl];
	}//end askKeepiq()

	/**
	 * The expiry of a plain PEM certificate kept on the source.
	 *
	 * @param mixed $pem The PEM text.
	 *
	 * @return integer|null The notAfter as unix time.
	 */
	private function pemNotAfter(mixed $pem): ?int {
		if (is_string($pem) === false || str_contains($pem, 'BEGIN CERTIFICATE') === false) {
			return null;
		}

		$parsed = openssl_x509_parse($pem);
		if ($parsed === false || is_int(($parsed['validTo_time_t'] ?? null)) === false) {
			return null;
		}

		return $parsed['validTo_time_t'];
	}//end pemNotAfter()

	/**
	 * Shape the certificate block the page shows.
	 *
	 * @param string            $keptIn        `keepiq` or `source`.
	 * @param string|null       $notAfter      The expiry, ISO 8601.
	 * @param string|null       $renewUrl      Where to renew it.
	 * @param DateTimeImmutable $now           Now.
	 * @param string|null       $unknownReason Why the expiry is unknown.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function certificateBlock(
		string $keptIn,
		?string $notAfter,
		?string $renewUrl,
		DateTimeImmutable $now,
		?string $unknownReason=null,
	): array {
		$block = [
			'configured' => true,
			'keptIn' => $keptIn,
			'expiresAt' => $notAfter,
			'daysLeft' => null,
			'status' => 'unknown',
			'statusLabel' => $this->l10n->t('Expiry unknown'),
			'notice' => $unknownReason,
			'renewUrl' => $renewUrl,
		];
		if ($notAfter === null) {
			return $block;
		}

		try {
			$expires = new DateTimeImmutable($notAfter);
		} catch (\Exception) {
			// An unparseable expiry from keepiq is an unknown expiry, not a failed report.
			$block['expiresAt'] = null;
			return $block;
		}

		$days = (int)floor(($expires->getTimestamp() - $now->getTimestamp()) / 86400);
		$block['daysLeft'] = max(0, $days);

		if ($days < 0) {
			$block['status'] = 'expired';
			$block['statusLabel'] = $this->l10n->t('Expired');
			$block['notice'] = $this->l10n->t('The certificate has expired, so calls to this connection fail. Replace it now.');
			return $block;
		}

		if ($days <= self::WARN_DAYS) {
			$block['status'] = 'expiring';
			$block['statusLabel'] = $this->l10n->t('Expires soon');
			$block['notice'] = $this->l10n->t(
				'Replace the certificate before %s, or calls to this connection stop working.',
				[(string)$this->l10n->l('date', $expires, ['width' => 'long'])]
			);
			return $block;
		}

		$block['status'] = 'valid';
		$block['statusLabel'] = $this->l10n->t('Valid');
		return $block;
	}//end certificateBlock()
}//end class
