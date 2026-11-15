<?php

/**
 * Integriq JsonRegistrationLeg.
 *
 * Posts a mapped submission to a plain JSON endpoint.
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

use OCA\Integriq\Service\CaseSystem\CallServiceCaseSystemTransport;
use OCA\Integriq\Service\CaseSystem\CaseSystemRefusal;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;

/**
 * The json leg (design D4): one POST of the mapped body to the target's path.
 *
 * When the target carries a JSON Schema the body is checked first, and a body
 * that does not match is refused as mapping-failed without a request.
 *
 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
 */
class JsonRegistrationLeg implements RegistrationLeg {

	/**
	 * The step name of the POST.
	 *
	 * @var string
	 */
	private const STEP = 'posted';

	/**
	 * Constructor.
	 *
	 * @param CallServiceCaseSystemTransport $transport Sends on the target's endpoint source.
	 * @param JsonSchemaChecker              $checker   Checks the body against the target's schema.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function __construct(
		private readonly CallServiceCaseSystemTransport $transport,
		private readonly JsonSchemaChecker $checker,
	) {
	}//end __construct()

	/**
	 * The kind.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function kind(): string {
		return 'json';
	}//end kind()

	/**
	 * Post the mapped body once.
	 *
	 * @param array<string,mixed>            $target   The registration target.
	 * @param array<string,mixed>            $mapped   The mapped body.
	 * @param array<string,mixed>            $handover The hand-over.
	 * @param array<int,array<string,mixed>> $steps    Steps already done.
	 *
	 * @return array{externalReference:string,externalUrl:string,steps:array<int,array<string,mixed>>}
	 *
	 * @throws RegistrationRefusal When the body does not match, the source is missing or the endpoint refuses.
	 *
	 * @spec openspec/changes/form-submission-registration-targets/specs/submission-registration/spec.md#requirement-an-app-registers-a-submission-through-one-typed-command-req-sreg-002
	 */
	public function register(array $target, array $mapped, array $handover, array $steps): array {
		$done = self::earlierPost(steps: $steps);
		if ($done !== null) {
			return ['externalReference' => (string)($done['reference'] ?? ''), 'externalUrl' => (string)($done['url'] ?? ''), 'steps' => $steps];
		}

		$settings = (array)($target['json'] ?? []);
		$this->checkSchema(schema: ($settings['schema'] ?? null), mapped: $mapped);
		$answer = $this->post(source: (string)($target['sources']['endpoint'] ?? ''), path: (string)($settings['path'] ?? ''), mapped: $mapped);

		$data      = self::dataOf(answer: $answer);
		$reference = (string)($handover['reference'] ?? '');
		if (is_scalar($data['id'] ?? null) === true && (string)$data['id'] !== '') {
			$reference = (string)$data['id'];
		}

		$url = '';
		if (is_string($data['url'] ?? null) === true) {
			$url = $data['url'];
		}

		$steps[] = ['step' => self::STEP, 'reference' => $reference, 'url' => $url];

		return ['externalReference' => $reference, 'externalUrl' => $url, 'steps' => $steps];
	}//end register()

	/**
	 * The POST an earlier hand-over already made, if any.
	 *
	 * @param array<int,array<string,mixed>> $steps Steps already done.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function earlierPost(array $steps): ?array {
		foreach ($steps as $step) {
			if (($step['step'] ?? null) === self::STEP) {
				return $step;
			}
		}

		return null;
	}//end earlierPost()

	/**
	 * Refuse a mapped body that does not match the target's JSON Schema.
	 *
	 * @param mixed               $schema The schema, or nothing.
	 * @param array<string,mixed> $mapped The mapped body.
	 *
	 * @return void
	 *
	 * @throws RegistrationRefusal mapping-failed.
	 */
	private function checkSchema(mixed $schema, array $mapped): void {
		if ($schema === null || $schema === [] || $schema === '') {
			return;
		}

		$outcome = $this->checker->check(schema: $schema, payload: $mapped);
		if ($outcome->isValid() === true) {
			return;
		}

		$errors = array_map(
			static fn (array $error): string => $error['path'] . ' ' . $error['message'],
			$outcome->firstErrors(3)
		);
		throw new RegistrationRefusal(
			reason: RegistrationRefusal::MAPPING_FAILED,
			retryable: false,
			message: 'The mapped submission does not match the target schema: ' . implode('; ', $errors)
		);
	}//end checkSchema()

	/**
	 * POST the body and refuse anything but a 2xx.
	 *
	 * @param string              $source The endpoint source uuid.
	 * @param string              $path   The path on it.
	 * @param array<string,mixed> $mapped The body.
	 *
	 * @return array{status:int,data:mixed,raw:string}
	 *
	 * @throws RegistrationRefusal When the source is missing or the endpoint refuses.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) RegistrationRefusal::fromStatus is the named constructor for an outside answer.
	 */
	private function post(string $source, string $path, array $mapped): array {
		if ($source === '') {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: false, message: 'The target names no endpoint source.');
		}

		try {
			$answer = $this->transport->send(sourceId: $source, method: 'POST', address: $path, options: ['json' => $mapped]);
		} catch (CaseSystemRefusal $e) {
			throw new RegistrationRefusal(reason: RegistrationRefusal::SOURCE_UNAVAILABLE, retryable: false, message: $e->getMessage());
		}

		if ($answer['status'] < 200 || $answer['status'] >= 300) {
			throw RegistrationRefusal::fromStatus(status: $answer['status'], detail: substr($answer['raw'], 0, 500));
		}

		return $answer;
	}//end post()

	/**
	 * The decoded answer as an array, empty when it is not one.
	 *
	 * @param array{status:int,data:mixed,raw:string} $answer The answer.
	 *
	 * @return array<string,mixed>
	 */
	private static function dataOf(array $answer): array {
		if (is_array($answer['data']) === true) {
			return $answer['data'];
		}

		return [];
	}//end dataOf()
}//end class
