<?php

/**
 * Builds the real opt-out services over in-memory tables, for unit tests.
 *
 * Every class below the table is the production class: the registry, the
 * categories, the recipient key and the token service. Only the three tables,
 * the app config, the clock, the random source and the url generator are
 * doubles.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Helpers;

use OCA\Integriq\Outbound\Identity\MessageComposer;
use OCA\Integriq\Outbound\Identity\OptOutCategories;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\Identity\RecipientKey;
use OCA\Integriq\Outbound\Identity\UnsubscribeTokenService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The opt-out services, wired as the container wires them.
 */
class OptOutFixture {

	/**
	 * The opt-out table.
	 *
	 * @var InMemoryOptOutMapper
	 */
	public InMemoryOptOutMapper $table;

	/**
	 * The log table.
	 *
	 * @var InMemoryOptOutLogMapper
	 */
	public InMemoryOptOutLogMapper $log;

	/**
	 * The short link table.
	 *
	 * @var InMemoryShortLinkMapper
	 */
	public InMemoryShortLinkMapper $shortLinks;

	/**
	 * The app config values.
	 *
	 * @var array<string,string>
	 */
	public array $config = [UnsubscribeTokenService::CONFIG_SECRET => 'unit-secret-0123456789'];

	/**
	 * The unix time the clock answers.
	 *
	 * @var int
	 */
	public int $now = 1790000000;

	/**
	 * The base url the url generator answers.
	 *
	 * @var string
	 */
	public string $baseUrl = 'https://gem.nl/';

	/**
	 * Warnings logged, as messages.
	 *
	 * @var list<string>
	 */
	public array $warnings = [];

	/**
	 * How many random strings were asked for, so short ids differ.
	 *
	 * @var int
	 */
	private int $randomCount = 0;

	/**
	 * The test case doubles are made in.
	 *
	 * @var TestCase
	 */
	private TestCase $test;

	/**
	 * Reads the test's own config, when the test keeps one.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $configSource = null;

	/**
	 * Reads the test's own clock, when the test keeps one.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $nowSource = null;

	/**
	 * Constructor.
	 *
	 * @param TestCase $test The test, to make doubles with.
	 * @param IDBConnection $db A connection double; nothing reaches it.
	 * @param InMemoryOptOutMapper|null $table The test's own table, if it keeps one.
	 * @param \Closure|null $configSource Returns the test's config array, if it keeps one.
	 * @param \Closure|null $nowSource Returns the test's clock, if it keeps one.
	 */
	public function __construct(
		TestCase $test,
		IDBConnection $db,
		?InMemoryOptOutMapper $table = null,
		?\Closure $configSource = null,
		?\Closure $nowSource = null,
	) {
		$this->test = $test;
		$this->configSource = $configSource;
		$this->nowSource = $nowSource;
		$this->table = ($table ?? new InMemoryOptOutMapper($db));
		$this->log = new InMemoryOptOutLogMapper($db);
		$this->shortLinks = new InMemoryShortLinkMapper($db);

	}//end __construct()

	/**
	 * The registry over the real collaborators.
	 *
	 * @return OptOutRegistry The registry.
	 */
	public function registry(): OptOutRegistry {
		return new OptOutRegistry(
			$this->table,
			$this->appConfig(),
			$this->clock(),
			$this->categories(),
			$this->recipientKey(),
			$this->log,
			$this->tokens(),
			$this->logger()
		);

	}//end registry()

	/**
	 * The token service.
	 *
	 * @return UnsubscribeTokenService The service.
	 */
	public function tokens(): UnsubscribeTokenService {
		$urls = $this->test->getMockBuilder(IURLGenerator::class)->getMock();
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $url): string => rtrim($this->baseUrl, '/') . $url);

		return new UnsubscribeTokenService(
			$this->appConfig(),
			$this->random(),
			$this->categories(),
			$this->clock(),
			$this->shortLinks,
			$urls,
			$this->logger()
		);

	}//end tokens()

	/**
	 * The composer.
	 *
	 * @return MessageComposer The composer.
	 */
	public function composer(): MessageComposer {
		return new MessageComposer($this->tokens());

	}//end composer()

	/**
	 * The categories.
	 *
	 * @return OptOutCategories The categories.
	 */
	public function categories(): OptOutCategories {
		return new OptOutCategories($this->appConfig(), $this->logger());

	}//end categories()

	/**
	 * The recipient key.
	 *
	 * @return RecipientKey The key.
	 */
	public function recipientKey(): RecipientKey {
		return new RecipientKey($this->appConfig(), $this->random());

	}//end recipientKey()

	/**
	 * An app config over the array.
	 *
	 * @return IAppConfig The config.
	 */
	public function appConfig(): IAppConfig {
		$appConfig = $this->test->getMockBuilder(IAppConfig::class)->getMock();
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->configValues()[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return $appConfig;

	}//end appConfig()

	/**
	 * The config values: the test's own, over the fixture's defaults.
	 *
	 * @return array<string,string> The values.
	 */
	private function configValues(): array {
		if ($this->configSource === null) {
			return $this->config;
		}

		return array_merge($this->config, ($this->configSource)());

	}//end configValues()

	/**
	 * A clock reading $now.
	 *
	 * @return ITimeFactory The clock.
	 */
	public function clock(): ITimeFactory {
		$clock = $this->test->getMockBuilder(ITimeFactory::class)->getMock();
		$clock->method('getTime')->willReturnCallback(fn (): int => ($this->nowSource !== null ? ($this->nowSource)() : $this->now));

		return $clock;

	}//end clock()

	/**
	 * A random source that never repeats.
	 *
	 * @return ISecureRandom The source.
	 */
	public function random(): ISecureRandom {
		$random = $this->test->getMockBuilder(ISecureRandom::class)->getMock();
		$random->method('generate')->willReturnCallback(
			function (int $length): string {
				$this->randomCount++;
				return substr(str_pad('r' . $this->randomCount, $length, 'x'), 0, $length);
			}
		);

		return $random;

	}//end random()

	/**
	 * A logger that keeps the warnings.
	 *
	 * @return AbstractLogger The logger.
	 */
	public function logger(): AbstractLogger {
		$fixture = $this;

		return new class($fixture) extends AbstractLogger {

			/**
			 * Constructor.
			 *
			 * @param OptOutFixture $fixture Where the warnings go.
			 */
			public function __construct(private readonly OptOutFixture $fixture) {

			}//end __construct()

			/**
			 * Keep a warning.
			 *
			 * @param mixed $level The level.
			 * @param string|\Stringable $message The message.
			 * @param array<mixed> $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->fixture->warnings[] = (string)$message;

			}//end log()
		};

	}//end logger()

}//end class
