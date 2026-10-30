<?php

/**
 * Golden characterization of EventService::dispatchMappingAction().
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\FormsFeatureDisabledException;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Forms\FormsAnswerResolver;
use OCA\Integriq\Service\Forms\FormsSyncAdapter;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Every branch of dispatchMappingAction(), recorded against a golden file:
 * the result and every OpenRegister, Forms, answer-resolver, mapping, call
 * and logger call. Timestamps are replaced by a placeholder.
 *
 * Regenerate the golden file on the code it must describe with
 * MAPPING_ACTION_GOLDEN=1.
 */
class EventServiceMappingActionTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/event/dispatch-mapping-action.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * The engine over recording collaborators.
	 *
	 * @return EventService
	 */
	private function service(): EventService {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) {
				$this->log[] = ['or.find', $id, $schema];
				$found = ($this->case['found'][$schema] ?? null);
				if ($found === 'throws') {
					throw new RuntimeException('not found');
				}

				if ($found === null) {
					return null;
				}

				return ObjectServiceMockBuilder::objectEntity($this, $found, $schema . '-' . $id);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null) {
				$this->log[] = ['or.saveObject', $object, $schema, $uuid];
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function ($message, array $context = []) {
				$this->log[] = ['logger.error', $message, array_keys($context)];
			}
		);

		$call = $this->createMock(CallService::class);
		$call->method('call')->willReturnCallback(
			function ($source, $endpoint = '', $method = 'GET', $config = []) {
				$this->log[] = ['call.call', $source->getUuid(), $endpoint, $method, $config];
				if (($this->case['call'] ?? 200) === 'throws') {
					throw new RuntimeException('call failed');
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => ($this->case['call'] ?? 200)], 'log-1');
			}
		);

		$adapter = null;
		$resolver = null;
		$mapping = null;
		if (($this->case['wired'] ?? true) === true) {
			$adapter = $this->createMock(FormsSyncAdapter::class);
			$adapter->method('assertEnabled')->willReturnCallback(
				function () {
					$this->log[] = ['forms.assertEnabled'];
					if (($this->case['formsDisabled'] ?? false) === true) {
						throw new FormsFeatureDisabledException('Forms is off');
					}
				}
			);
			$adapter->method('fetchSubmission')->willReturnCallback(
				function (ObjectEntity $source, int $formId, int $submissionId) {
					$this->log[] = ['forms.fetchSubmission', $source->getUuid(), $formId, $submissionId];
					if (($this->case['fetch'] ?? '') === 'throws') {
						throw new RuntimeException('forms down');
					}

					return ['answers' => [['questionId' => 1, 'text' => 'Jan']]];
				}
			);
			$adapter->method('fetchForm')->willReturnCallback(
				function (ObjectEntity $source, int $formId) {
					$this->log[] = ['forms.fetchForm', $source->getUuid(), $formId];
					return ['questions' => [['id' => 1, 'text' => 'Naam'], ['id' => 0, 'text' => 'Zero'], 'junk', ['id' => 2, 'text' => 'Naam'], ['id' => 3, 'text' => '']]];
				}
			);

			$resolver = $this->createMock(FormsAnswerResolver::class);
			$resolver->method('resolve')->willReturnCallback(
				function (array $questions, array $answers, int|string $questionRef) {
					$this->log[] = ['resolver.resolve', $questionRef];
					return 'answer-' . $questionRef;
				}
			);

			$mapping = $this->createMock(MappingService::class);
			$mapping->method('executeMapping')->willReturnCallback(
				function ($recipe, array $input) {
					$this->log[] = ['mapping.executeMapping', $recipe->getUuid(), $input];
					return ['mapped' => $input];
				}
			);
		}//end if

		return new EventService(
			$objects,
			$this->createMock(IClientService::class),
			$logger,
			$this->createMock(WebhookSignatureService::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(JobService::class),
			$call,
			$this->createMock(FlowRunnerService::class),
			$mapping,
			$resolver,
			$adapter,
		);
	}//end service()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$found = ['mapping' => ['name' => 'm'], 'source' => ['name' => 's'], 'event' => ['data' => ['formId' => 4, 'submission' => ['id' => 8]]]];
		$action = ['kind' => 'mapping', 'mappingId' => 'm-1', 'sourceId' => 's-1', 'endpoint' => '/zaken'];

		return [
			'no-ids' => ['action' => ['kind' => 'mapping'], 'found' => $found],
			'mapping-missing' => ['action' => $action, 'found' => (['mapping' => null] + $found)],
			'source-throws' => ['action' => $action, 'found' => (['source' => 'throws'] + $found)],
			'not-wired' => ['action' => $action, 'found' => $found, 'wired' => false],
			'forms-disabled' => ['action' => $action, 'found' => $found, 'formsDisabled' => true],
			'no-event-id' => ['action' => $action, 'found' => $found, 'message' => ['status' => 'pending']],
			'legacy-event-id' => ['action' => $action, 'found' => $found, 'message' => ['eventId' => 'e-old']],
			'event-missing' => ['action' => $action, 'found' => (['event' => null] + $found)],
			'event-throws' => ['action' => $action, 'found' => (['event' => 'throws'] + $found)],
			'no-form-id' => ['action' => $action, 'found' => (['event' => ['data' => ['submission' => ['id' => 8]]]] + $found)],
			'no-submission-id' => ['action' => $action, 'found' => (['event' => ['data' => ['formId' => '4']]] + $found)],
			'event-without-data' => ['action' => $action, 'found' => (['event' => ['type' => 'x']] + $found)],
			'success' => ['action' => $action, 'found' => $found],
			'success-299-put' => ['action' => ($action + ['method' => 'PUT']), 'found' => $found, 'call' => 299],
			'no-endpoint' => ['action' => ['kind' => 'mapping', 'mappingId' => 'm-1', 'sourceId' => 's-1'], 'found' => $found],
			'status-300' => ['action' => $action, 'found' => $found, 'call' => 300],
			'status-199' => ['action' => $action, 'found' => $found, 'call' => 199],
			'status-zero' => ['action' => $action, 'found' => $found, 'call' => 0],
			'call-throws' => ['action' => $action, 'found' => $found, 'call' => 'throws'],
			'fetch-throws' => ['action' => $action, 'found' => $found, 'fetch' => 'throws'],
			'retry-policy-abandons' => ['action' => $action, 'found' => (['mapping' => null] + $found), 'subscription' => ['retryPolicy' => ['maxRetries' => 1]]],
			'status-500-abandons' => ['action' => $action, 'found' => $found, 'call' => 500, 'subscription' => ['retryPolicy' => ['maxRetries' => 1]]],
			'retry-policy-not-array' => ['action' => $action, 'found' => $found, 'call' => 500, 'subscription' => ['retryPolicy' => 'x'], 'message' => ['event' => 'e-1', 'retryCount' => 2, 'attempts' => [['at' => 'before']]]],
		];
	}//end cases()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$method = new ReflectionMethod(EventService::class, 'dispatchMappingAction');

		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$this->case = $case;
			$service = $this->service();
			$this->log = [];
			$message = ObjectServiceMockBuilder::objectEntity($this, ($case['message'] ?? ['event' => 'e-1', 'status' => 'pending']), 'msg-1');
			try {
				$outcome = ['return' => $method->invoke($service, $message, ($case['subscription'] ?? []), $case['action'])];
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['outcome' => $outcome, 'log' => $this->log];
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		$encoded = (string)preg_replace('/"\d{4}-\d{2}-\d{2}T[^"]*"/', '"DATE"', $encoded);
		if (getenv('MAPPING_ACTION_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
