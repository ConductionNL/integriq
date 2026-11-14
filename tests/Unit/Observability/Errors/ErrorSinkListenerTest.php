<?php

/**
 * Unit tests for the error sink listener (REQ-OTEL-006).
 *
 * Every test builds the real `OCP\Log\BeforeMessageLoggedEvent`, with the
 * constructor Nextcloud 28 gave it, so a wrong accessor cannot hide behind a
 * fake event.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Observability\Errors
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/observability-opentelemetry-export-errors-and-app-spans/specs/execution-trace/spec.md#requirement-errors-from-the-apps-an-administrator-selects-go-to-an-error-sink-req-otel-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Observability\Errors;

use OCA\Integriq\AppInfo\Application;
use OCA\Integriq\BackgroundJob\ErrorSinkExportJob;
use OCA\Integriq\Observability\Errors\ErrorSinkListener;
use OCA\Integriq\Observability\Errors\ErrorSinkSettings;
use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Tests\Helpers\AppContainerInjection;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Log\BeforeMessageLoggedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * The listener queues the selected messages, and only those.
 */
class ErrorSinkListenerTest extends TestCase {

	use AppContainerInjection;

	/**
	 * Stored app settings, as an in-memory IAppConfig.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * The jobs added to the job list: class and argument.
	 *
	 * @var list<array{0: string, 1: mixed}>
	 */
	private array $queued = [];

	/**
	 * Switch the sink on with the default app list.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = [
			'errorsink_enabled' => true,
			'errorsink_kind' => 'sentry',
			'errorsink_endpoint' => 'https://key@sentry.example.org/42',
		];
		$this->queued = [];

	}//end setUp()

	/**
	 * Error sink settings over $this->config.
	 *
	 * @return ErrorSinkSettings
	 */
	private function settings(): ErrorSinkSettings {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$get = fn (string $app, string $key, $default) => ($this->config[$key] ?? $default);
		foreach (['getValueBool', 'getValueString', 'getValueInt'] as $getter) {
			$appConfig->method($getter)->willReturnCallback($get);
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ErrorSinkSettings(appConfig: $appConfig, l: $l10n);

	}//end settings()

	/**
	 * The listener over a recording job list, or the given one.
	 *
	 * @param IJobList|null $jobList The job list; a recording one when absent.
	 *
	 * @return ErrorSinkListener
	 */
	private function listener(?IJobList $jobList = null): ErrorSinkListener {
		if ($jobList === null) {
			$jobList = $this->createMock(originalClassName: IJobList::class);
			$jobList->method('add')->willReturnCallback(
				function (string $job, $argument): void {
					$this->queued[] = [$job, $argument];
				}
			);
		}

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getId')->willReturn('req-1');

		return new ErrorSinkListener(settings: $this->settings(), redactor: new BodyRedactor(), jobList: $jobList, time: $time, request: $request);

	}//end listener()

	/**
	 * An openregister exception at level error is queued once, as the sink event.
	 *
	 * @return void
	 */
	public function testAnOpenRegisterErrorIsQueuedForTheSink(): void {
		$event = new BeforeMessageLoggedEvent(
			'openregister',
			3,
			[
				'Exception' => 'OCA\\OpenRegister\\Exception\\ValidationException',
				'Message' => 'The object does not match its schema',
				'Code' => 0,
				'Trace' => [['file' => '/var/www/a.php', 'line' => 1, 'args' => ['secret-arg']]],
				'File' => '/var/www/html/apps/openregister/lib/Service/ObjectService.php',
				'Line' => 412,
				'CustomMessage' => 'Saving failed',
			]
		);

		$this->listener()->handle($event);

		$this->assertCount(expectedCount: 1, haystack: $this->queued);
		$this->assertSame(expected: ErrorSinkExportJob::class, actual: $this->queued[0][0]);
		$this->assertSame(
			expected: [
				'event' => [
					'app' => 'openregister',
					'level' => 3,
					'time' => 1760000000,
					'exceptionClass' => 'OCA\\OpenRegister\\Exception\\ValidationException',
					'file' => '/var/www/html/apps/openregister/lib/Service/ObjectService.php',
					'line' => 412,
					'requestId' => 'req-1',
					'message' => 'Saving failed: The object does not match its schema',
				],
				'attempt' => 0,
			],
			actual: $this->queued[0][1]
		);
		$this->assertStringNotContainsString(needle: 'secret-arg', haystack: (string)json_encode($this->queued[0][1]), message: 'The trace never leaves.');

	}//end testAnOpenRegisterErrorIsQueuedForTheSink()

	/**
	 * An unlisted app, a level below the minimum, a switched-off sink and an
	 * event of another class queue nothing.
	 *
	 * @return void
	 */
	public function testUnlistedAppsAndLowLevelsAreNotQueued(): void {
		$listener = $this->listener();

		$listener->handle(new BeforeMessageLoggedEvent('dossiq', 3, ['message' => 'dossiq failed']));
		$listener->handle(new BeforeMessageLoggedEvent('openregister', 2, ['message' => 'a warning']));
		$listener->handle(new Event());
		$this->assertSame(expected: [], actual: $this->queued);

		$this->config['errorsink_enabled'] = false;
		$this->listener()->handle(new BeforeMessageLoggedEvent('openregister', 4, ['message' => 'fatal']));
		$this->assertSame(expected: [], actual: $this->queued);

		$this->config['errorsink_enabled'] = true;
		$this->config['errorsink_apps'] = 'dossiq';
		$this->config['errorsink_min_level'] = 2;
		$this->listener()->handle(new BeforeMessageLoggedEvent('dossiq', 2, ['message' => 'now listed']));
		$this->assertCount(expectedCount: 1, haystack: $this->queued);
		$this->assertSame(expected: 'now listed', actual: $this->queued[0][1]['event']['message']);

	}//end testUnlistedAppsAndLowLevelsAreNotQueued()

	/**
	 * The listener has no HTTP client and no logger to call: queuing is all
	 * it does during the request.
	 *
	 * @return void
	 */
	public function testTheListenerMakesNoHttpCall(): void {
		$constructor = (new ReflectionClass(ErrorSinkListener::class))->getConstructor();
		$this->assertNotNull(actual: $constructor);
		$types = [];
		foreach ($constructor->getParameters() as $parameter) {
			$type = $parameter->getType();
			$this->assertInstanceOf(expected: ReflectionNamedType::class, actual: $type);
			$types[] = $type->getName();
		}

		$this->assertNotContains(needle: IClientService::class, haystack: $types);
		$this->assertNotContains(needle: LoggerInterface::class, haystack: $types);

		$this->listener()->handle(new BeforeMessageLoggedEvent('integriq', 4, ['message' => 'sink down']));
		$this->assertCount(expectedCount: 1, haystack: $this->queued, message: 'The message is only queued.');

	}//end testTheListenerMakesNoHttpCall()

	/**
	 * A job list that throws is swallowed, and the next message is handled again.
	 *
	 * @return void
	 */
	public function testAFailingQueueIsSwallowedWithoutLogging(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->exactly(count: 2))->method('add')->willThrowException(new RuntimeException('database gone'));
		$listener = $this->listener(jobList: $jobList);

		$listener->handle(new BeforeMessageLoggedEvent('openregister', 3, ['message' => 'first']));
		$listener->handle(new BeforeMessageLoggedEvent('openregister', 3, ['message' => 'second']));

		$this->addToAssertionCount(count: 1);

	}//end testAFailingQueueIsSwallowedWithoutLogging()

	/**
	 * A message logged while the export job sends is not forwarded again.
	 *
	 * @return void
	 */
	public function testAMessageLoggedWhileSendingIsNotForwarded(): void {
		$listener = $this->listener();

		ErrorSinkListener::whileSending(
			send: static function () use ($listener): void {
				$listener->handle(new BeforeMessageLoggedEvent('integriq', 3, ['message' => 'the sink refused']));
			}
		);
		$this->assertSame(expected: [], actual: $this->queued);

		$listener->handle(new BeforeMessageLoggedEvent('integriq', 3, ['message' => 'after the send']));
		$this->assertCount(expectedCount: 1, haystack: $this->queued);

	}//end testAMessageLoggedWhileSendingIsNotForwarded()

	/**
	 * A message too long for a job argument is shortened until it fits, and
	 * a plain message without an exception is forwarded as it was logged.
	 *
	 * @return void
	 */
	public function testALongMessageIsShortenedToFitTheJobArgument(): void {
		$this->listener()->handle(new BeforeMessageLoggedEvent('integriq', 3, ['message' => str_repeat('é', 5000)]));

		$this->assertCount(expectedCount: 1, haystack: $this->queued);
		$this->assertLessThanOrEqual(expected: 3900, actual: strlen((string)json_encode($this->queued[0][1])));
		$this->assertNotSame(expected: '', actual: $this->queued[0][1]['event']['message']);
		$this->assertSame(expected: '', actual: $this->queued[0][1]['event']['exceptionClass']);

	}//end testALongMessageIsShortenedToFitTheJobArgument()

	/**
	 * `Application::register()` registers the listener for
	 * `BeforeMessageLoggedEvent`: a listener with a green suite and no
	 * registration is the defect this test exists to prevent.
	 *
	 * @return void
	 */
	public function testApplicationRegistersTheListenerForBeforeMessageLoggedEvent(): void {
		$listeners = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener) use (&$listeners): void {
				$listeners[] = [$event, $listener];
			}
		);

		$container = $this->createMock(originalClassName: $this->appContainerType());
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === IEventDispatcher::class) {
					return $this->createMock(originalClassName: IEventDispatcher::class);
				}

				throw new RuntimeException('unexpected container id: ' . $id);
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$this->injectAppContainer(app: $app, container: $container);
		$app->register($context);

		$this->assertContains(needle: [BeforeMessageLoggedEvent::class, ErrorSinkListener::class], haystack: $listeners);

	}//end testApplicationRegistersTheListenerForBeforeMessageLoggedEvent()
}//end class
