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
		$expiry = $this->findExpiry(data: $data);
		if ($expiry === null) {
			return ['configured' => false, 'none' => true];
		}

		return $this->certificateBlock(expiry: $expiry, now: $now);
	}//end certificate()

	/**
	 * Find the source's certificate expiry: from keepiq for a reference, else on the source.
	 *
	 * @param array<string, mixed> $data The source object.
	 *
	 * @return CertificateExpiry|null The expiry, or null when the source has no certificate.
	 */
	private function findExpiry(array $data): ?CertificateExpiry {
		$configuration = $this->arrayOrEmpty(value: ($data['configuration'] ?? []));
		$authentication = $this->arrayOrEmpty(value: ($configuration['authentication'] ?? []));

		$reference = $this->certificateReference(authentication: $authentication);
		if ($reference !== null) {
			return $this->keepiqExpiry(credentialId: $reference, data: $data);
		}

		return $this->sourceExpiry(configuration: $configuration, authentication: $authentication);
	}//end findExpiry()

	/**
	 * A value when it is an array, else an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed> The array.
	 */
	private function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOrEmpty()

	/**
	 * The expiry keepiq answers for a referenced certificate, or an unknown one when it does not.
	 *
	 * @param string               $credentialId The keepiq credential id.
	 * @param array<string, mixed> $data         The source object.
	 *
	 * @return CertificateExpiry The expiry.
	 */
	private function keepiqExpiry(string $credentialId, array $data): CertificateExpiry {
		$organisation = ($data['organisation'] ?? '');
		if (is_string($organisation) === false) {
			$organisation = '';
		}

		$answer = $this->askKeepiq(credentialId: $credentialId, organisation: $organisation);
		if ($answer === null) {
			$expiry = new CertificateExpiry(keptIn: 'keepiq', notAfter: null);
			$expiry->unknownReason = $this->l10n->t('Expiry unknown: keepiq did not answer.');
			return $expiry;
		}

		$expiry = new CertificateExpiry(keptIn: 'keepiq', notAfter: $answer['notAfter']);
		$expiry->renewUrl = $answer['renewUrl'];
		return $expiry;
	}//end keepiqExpiry()

	/**
	 * The expiry of the certificate kept on the source: the mTLS material, else a plain PEM.
	 *
	 * @param array<string, mixed> $configuration  The source configuration.
	 * @param array<string, mixed> $authentication The source's authentication block.
	 *
	 * @return CertificateExpiry|null The expiry, or null when the source keeps no certificate.
	 */
	private function sourceExpiry(array $configuration, array $authentication): ?CertificateExpiry {
		$notAfter = $this->mtls->certificateNotAfter(authConfig: $authentication);
		if ($notAfter === null) {
			$notAfter = $this->pemNotAfter(pem: ($configuration['cert'] ?? null));
		}

		if ($notAfter === null) {
			return null;
		}

		return new CertificateExpiry(keptIn: 'source', notAfter: (new DateTimeImmutable())->setTimestamp($notAfter)->format('c'));
	}//end sourceExpiry()

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
			if ($this->asksExpiry(event: $event) === false) {
				return null;
			}

			$this->dispatcher->dispatchTyped($event);
			$notAfter = $event->getNotAfter();
			$renewUrl = $event->getRenewUrl();
		} catch (Throwable $e) {
			$this->logger->warning('[ConnectionMonitorService] keepiq could not answer a certificate expiry: ' . $e->getMessage());
			return null;
		}

		return $this->keepiqAnswer(notAfter: $notAfter, renewUrl: $renewUrl);
	}//end askKeepiq()

	/**
	 * Whether an object is keepiq's expiry event, able to carry an answer.
	 *
	 * @param object $event The constructed event.
	 *
	 * @return boolean True when it is an event with an expiry and a renew url.
	 */
	private function asksExpiry(object $event): bool {
		return $event instanceof Event
			&& method_exists($event, 'getNotAfter') === true
			&& method_exists($event, 'getRenewUrl') === true;
	}//end asksExpiry()

	/**
	 * Shape keepiq's answer: an expiry is required, a renew url only when it is text.
	 *
	 * @param mixed $notAfter The expiry keepiq gave.
	 * @param mixed $renewUrl The renew url keepiq gave.
	 *
	 * @return array{notAfter: string, renewUrl: string|null}|null The answer, or null when keepiq gave none.
	 */
	private function keepiqAnswer(mixed $notAfter, mixed $renewUrl): ?array {
		if (is_string($notAfter) === false || $notAfter === '') {
			return null;
		}

		if (is_string($renewUrl) === false) {
			$renewUrl = null;
		}

		return ['notAfter' => $notAfter, 'renewUrl' => $renewUrl];
	}//end keepiqAnswer()

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
	 * @param CertificateExpiry $expiry Where the certificate is kept and when it expires.
	 * @param DateTimeImmutable $now    Now.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function certificateBlock(CertificateExpiry $expiry, DateTimeImmutable $now): array {
		$block = [
			'configured' => true,
			'keptIn' => $expiry->keptIn,
			'expiresAt' => $expiry->notAfter,
			'daysLeft' => null,
			'status' => 'unknown',
			'statusLabel' => $this->l10n->t('Expiry unknown'),
			'notice' => $expiry->unknownReason,
			'renewUrl' => $expiry->renewUrl,
		];
		if ($expiry->notAfter === null) {
			return $block;
		}

		$expires = $this->parseExpiry(notAfter: $expiry->notAfter);
		if ($expires === null) {
			// An unparseable expiry from keepiq is an unknown expiry, not a failed report.
			$block['expiresAt'] = null;
			return $block;
		}

		$days = (int)floor(($expires->getTimestamp() - $now->getTimestamp()) / 86400);
		$block['daysLeft'] = max(0, $days);

		return array_merge($block, $this->expiryStatus(days: $days, expires: $expires));
	}//end certificateBlock()

	/**
	 * Parse an ISO 8601 expiry.
	 *
	 * @param string $notAfter The expiry.
	 *
	 * @return DateTimeImmutable|null The expiry, or null when it does not parse.
	 */
	private function parseExpiry(string $notAfter): ?DateTimeImmutable {
		try {
			return new DateTimeImmutable($notAfter);
		} catch (\Exception) {
			return null;
		}
	}//end parseExpiry()

	/**
	 * The status, label and (when there is one to give) notice for a known expiry.
	 *
	 * @param integer           $days    Whole days until the expiry; negative once expired.
	 * @param DateTimeImmutable $expires The expiry.
	 *
	 * @return array<string, mixed> The fields of the block that the expiry decides.
	 */
	private function expiryStatus(int $days, DateTimeImmutable $expires): array {
		if ($days < 0) {
			return [
				'status' => 'expired',
				'statusLabel' => $this->l10n->t('Expired'),
				'notice' => $this->l10n->t('The certificate has expired, so calls to this connection fail. Replace it now.'),
			];
		}

		if ($days <= self::WARN_DAYS) {
			return [
				'status' => 'expiring',
				'statusLabel' => $this->l10n->t('Expires soon'),
				'notice' => $this->l10n->t(
					'Replace the certificate before %s, or calls to this connection stop working.',
					[(string)$this->l10n->l('date', $expires, ['width' => 'long'])]
				),
			];
		}

		return [
			'status' => 'valid',
			'statusLabel' => $this->l10n->t('Valid'),
		];
	}//end expiryStatus()
}//end class
