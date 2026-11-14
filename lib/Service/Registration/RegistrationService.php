<?php

/**
 * Integriq RegistrationService.
 *
 * Registers a form submission another app hands over at a registration target.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Registration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Registration;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Integriq\Service\MappingServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The command's answer for one hand-over (design D2, D3 and D5).
 *
 * In order: the target must exist and be enabled, the app must be one of its
 * allowedApps, and the hand-over must have the shape of design D3. A record
 * already done answers its stored result without an outside call. Otherwise
 * the hand-over is mapped, a record is written as started, and the target's
 * leg registers it from the steps an earlier hand-over left. Every failure is
 * an answer with a code and a retryable flag; nothing is thrown to the caller.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
class RegistrationService {

	/**
	 * The apps a target allows when it names none.
	 *
	 * @var array<int,string>
	 */
	public const DEFAULT_ALLOWED_APPS = ['portaliq'];

	/**
	 * The legs by target kind.
	 *
	 * @var array<string,RegistrationLeg>
	 */
	private array $legs = [];

	/**
	 * Constructor.
	 *
	 * @param RegistrationStore         $store      Targets and records.
	 * @param MappingServiceInterface   $mappings   Maps the hand-over to the outside shape.
	 * @param JsonRegistrationLeg       $json       The json leg.
	 * @param ObjectsApiRegistrationLeg $objectsApi The objects-api leg.
	 * @param LoggerInterface           $logger     Records refusals by target and reference, never the answers.
	 * @param StufZdsRegistrationLeg|null $stufZds  The stuf-zds leg.
	 * @param ZgwRegistrationLeg|null     $zgw      The zgw leg.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly RegistrationStore $store,
		private readonly MappingServiceInterface $mappings,
		JsonRegistrationLeg $json,
		ObjectsApiRegistrationLeg $objectsApi,
		private readonly LoggerInterface $logger,
		?StufZdsRegistrationLeg $stufZds = null,
		?ZgwRegistrationLeg $zgw = null,
	) {
		foreach (array_filter([$json, $objectsApi, $stufZds, $zgw]) as $leg) {
			$this->legs[$leg->kind()] = $leg;
		}
	}//end __construct()

	/**
	 * Register one submission and answer the result or the refusal.
	 *
	 * @param string              $app      The calling app.
	 * @param string              $target   The target slug.
	 * @param array<string,mixed> $handover The hand-over of design D3.
	 *
	 * @return array<string,mixed> {ok: true, externalReference, externalUrl} or {ok: false, code, retryable, message, status}.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function register(string $app, string $target, array $handover): array {
		try {
			return $this->registerOrRefuse(app: $app, slug: $target, handover: $handover);
		} catch (RegistrationRefusal $refusal) {
			$this->logger->warning(
				'Integriq: a submission was not registered',
				[
					'app' => $app,
					'target' => $target,
					'reference' => ($handover['reference'] ?? null),
					'code' => $refusal->getReason(),
					'retryable' => $refusal->isRetryable(),
				]
			);
			return $refusal->toAnswer();
		} catch (Throwable $e) {
			$this->logger->error(
				'Integriq: registering a submission failed',
				['app' => $app, 'target' => $target, 'reference' => ($handover['reference'] ?? null), 'exception' => $e]
			);
			$refusal = new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: true,
				message: 'The registration could not be completed: ' . $e->getMessage()
			);
			return $refusal->toAnswer();
		}//end try
	}//end register()

	/**
	 * The registration, or a refusal thrown.
	 *
	 * @param string              $app      The calling app.
	 * @param string              $slug     The target slug.
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return array<string,mixed> The answer of a registration that is done.
	 *
	 * @throws RegistrationRefusal When the submission is not registered.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-a-submission-is-registered-once-req-sreg-003
	 */
	private function registerOrRefuse(string $app, string $slug, array $handover): array {
		$target = $this->usableTarget(app: $app, slug: $slug);
		self::checkHandover(handover: $handover);

		$reference = (string)$handover['reference'];
		$record    = $this->store->findRecord(target: $slug, app: $app, reference: $reference);
		if ($record !== null && ($record['data']['state'] ?? null) === 'done') {
			return self::answer(
				externalReference: (string)($record['data']['externalReference'] ?? ''),
				externalUrl: (string)($record['data']['externalUrl'] ?? '')
			);
		}

		$leg = ($this->legs[(string)($target['kind'] ?? '')] ?? null);
		if ($leg === null) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::SOURCE_UNAVAILABLE,
				retryable: false,
				message: 'Registering at a target of kind ' . (string)($target['kind'] ?? '') . ' is not available yet.'
			);
		}

		$mapped = $this->mapped(target: $target, handover: $handover);
		$data   = [
			'target' => $slug,
			'app' => $app,
			'submissionReference' => $reference,
			'state' => 'started',
			'steps' => (array)($record['data']['steps'] ?? []),
			'startedAt' => (string)($record['data']['startedAt'] ?? self::now()),
		];
		$uuid   = $this->store->saveRecord(data: $data, uuid: ($record['uuid'] ?? null));

		try {
			$result = $leg->register(target: $target, mapped: $mapped, handover: $handover, steps: $data['steps']);
		} catch (RegistrationRefusal $refusal) {
			$this->store->saveRecord(
				data: ['state' => 'failed', 'lastError' => $refusal->toAnswer(), 'finishedAt' => self::now()] + $data,
				uuid: $uuid
			);
			throw $refusal;
		}

		$this->store->saveRecord(
			data: [
				'state' => 'done',
				'steps' => $result['steps'],
				'externalReference' => $result['externalReference'],
				'externalUrl' => $result['externalUrl'],
				'finishedAt' => self::now(),
			] + $data,
			uuid: $uuid
		);

		return self::answer(externalReference: $result['externalReference'], externalUrl: $result['externalUrl']);
	}//end registerOrRefuse()

	/**
	 * The target, when it exists, is enabled and allows the app.
	 *
	 * @param string $app  The calling app.
	 * @param string $slug The target slug.
	 *
	 * @return array<string,mixed> The target.
	 *
	 * @throws RegistrationRefusal unknown-target or not-allowed.
	 */
	private function usableTarget(string $app, string $slug): array {
		$target = null;
		if ($slug !== '') {
			$target = $this->store->findTarget(slug: $slug);
		}

		if ($target === null || ($target['enabled'] ?? true) === false) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::UNKNOWN_TARGET,
				retryable: false,
				message: 'There is no enabled registration target ' . $slug . '.'
			);
		}

		if (in_array($app, self::allowedApps(target: $target), true) === false) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::NOT_ALLOWED,
				retryable: false,
				message: 'App ' . $app . ' may not use registration target ' . $slug . '.'
			);
		}

		return $target;
	}//end usableTarget()

	/**
	 * The apps a target allows.
	 *
	 * @param array<string,mixed> $target The target.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public static function allowedApps(array $target): array {
		$apps = ($target['allowedApps'] ?? null);
		if (is_array($apps) === false || $apps === []) {
			return self::DEFAULT_ALLOWED_APPS;
		}

		return array_values(array_filter($apps, 'is_string'));
	}//end allowedApps()

	/**
	 * Refuse a hand-over without the shape of design D3.
	 *
	 * Files travel as Nextcloud file ids; bytes are never accepted.
	 *
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return void
	 *
	 * @throws RegistrationRefusal invalid-handover.
	 */
	private static function checkHandover(array $handover): void {
		$problem = self::handoverProblem(handover: $handover);
		if ($problem !== null) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::INVALID_HANDOVER,
				retryable: false,
				message: 'The hand-over is refused: ' . $problem . '.'
			);
		}
	}//end checkHandover()

	/**
	 * What is wrong with a hand-over, or null when nothing is.
	 *
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return string|null
	 */
	private static function handoverProblem(array $handover): ?string {
		if (is_string($handover['reference'] ?? null) === false || trim($handover['reference']) === '') {
			return 'it has no submission reference';
		}

		if (is_array($handover['answers'] ?? null) === false) {
			return 'it has no answers';
		}

		if (isset($handover['pdf']) === true && self::isFile(file: $handover['pdf']) === false) {
			return 'its PDF is not a file id';
		}

		$attachments = ($handover['attachments'] ?? []);
		if (is_array($attachments) === false || in_array(false, array_map([self::class, 'isFile'], $attachments), true) === true) {
			return 'an attachment is not a file id';
		}

		return null;
	}//end handoverProblem()

	/**
	 * Whether a value is a file reference: an array with a positive integer fileId.
	 *
	 * @param mixed $file The value.
	 *
	 * @return bool
	 */
	private static function isFile(mixed $file): bool {
		return is_array($file) === true && is_int($file['fileId'] ?? null) === true && $file['fileId'] > 0;
	}//end isFile()

	/**
	 * The hand-over in the outside shape, through the target's mapping.
	 *
	 * @param array<string,mixed> $target   The target.
	 * @param array<string,mixed> $handover The hand-over.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws RegistrationRefusal mapping-failed.
	 */
	private function mapped(array $target, array $handover): array {
		$mapping = (string)($target['mapping'] ?? '');
		if ($mapping === '') {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::MAPPING_FAILED,
				retryable: false,
				message: 'The registration target names no mapping.'
			);
		}

		try {
			return $this->mappings->executeMapping(mapping: $mapping, input: $handover);
		} catch (Throwable $e) {
			throw new RegistrationRefusal(
				reason: RegistrationRefusal::MAPPING_FAILED,
				retryable: false,
				message: 'The mapping failed: ' . $e->getMessage()
			);
		}
	}//end mapped()

	/**
	 * A successful answer.
	 *
	 * @param string $externalReference The outside reference.
	 * @param string $externalUrl       The outside url.
	 *
	 * @return array{ok:true,externalReference:string,externalUrl:string}
	 */
	private static function answer(string $externalReference, string $externalUrl): array {
		return ['ok' => true, 'externalReference' => $externalReference, 'externalUrl' => $externalUrl];
	}//end answer()

	/**
	 * Now, as an ISO 8601 date-time.
	 *
	 * @return string
	 */
	private static function now(): string {
		return (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
