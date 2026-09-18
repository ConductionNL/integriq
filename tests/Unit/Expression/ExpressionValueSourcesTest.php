<?php

/**
 * The allowlist, the registry and the `env:` source — what they admit, what
 * they refuse, and who may change it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Expression
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/allowlisted-expression-sources/specs/expression-value-sources/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Expression;

use OCA\Integriq\Expression\EnvironmentAllowlist;
use OCA\Integriq\Expression\ExpressionValueRefused;
use OCA\Integriq\Expression\ExpressionValueSourceInterface;
use OCA\Integriq\Expression\ExpressionValueSourceRegistry;
use OCA\Integriq\Expression\Source\EnvironmentValueSource;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies REQ-EVS-001 through REQ-EVS-005.
 */
class ExpressionValueSourcesTest extends TestCase {

	/**
	 * What app config holds.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * An app-config double over an array.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end appConfig()

	/**
	 * The allowlist.
	 *
	 * @return EnvironmentAllowlist The allowlist.
	 */
	private function allowlist(): EnvironmentAllowlist {
		return new EnvironmentAllowlist(
			appConfig: $this->appConfig(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end allowlist()

	/**
	 * The `env:` source over a fake environment.
	 *
	 * @param EnvironmentAllowlist  $allowlist   The allowlist.
	 * @param array<string, string> $environment The fake environment.
	 *
	 * @return EnvironmentValueSource The source.
	 */
	private function source(EnvironmentAllowlist $allowlist, array $environment = []): EnvironmentValueSource {
		return new EnvironmentValueSource(
			allowlist: $allowlist,
			environmentRead: static function (string $key) use ($environment) {
				return ($environment[$key] ?? false);
			}
		);
	}//end source()

	/**
	 * 🔴 The allowlist admits EXACT KEYS and refuses every shape that could
	 * widen later.
	 *
	 * Each of these is a rule rather than a list, and a rule also admits
	 * whatever is added to the environment next year — which is where the
	 * database password lives.
	 *
	 * @return void
	 */
	public function testTheAllowlistRefusesEveryShapeThatCouldWidenLater(): void {
		$allowlist = $this->allowlist();

		foreach (['*', '', '   ', 'DB_*', 'db.*', '/^DB_/', 'DB PASSWORD', 'DB-PASSWORD', '.*'] as $candidate) {
			$this->assertNotNull(
				$allowlist->refusalFor(key: $candidate),
				sprintf('"%s" is a rule, not a key, and a rule widens on its own', $candidate)
			);
		}
	}//end testTheAllowlistRefusesEveryShapeThatCouldWidenLater()

	/**
	 * The control: an ordinary variable name is accepted.
	 *
	 * Without it, the refusals above could be passing on a validator that
	 * refuses everything.
	 *
	 * @return void
	 */
	public function testAnOrdinaryVariableNameIsAccepted(): void {
		$allowlist = $this->allowlist();

		foreach (['SMTP_HOST', 'BRP_BASE_URL', '_UNDERSCORE_FIRST', 'A1'] as $candidate) {
			$this->assertNull(
				$allowlist->refusalFor(key: $candidate),
				sprintf('the control: "%s" is a plain environment variable name', $candidate)
			);
		}
	}//end testAnOrdinaryVariableNameIsAccepted()

	/**
	 * A key is listed with who added it and when, and the value is never stored.
	 *
	 * @return void
	 */
	public function testAKeyIsListedWithItsPrincipalAndTheValueIsNeverStored(): void {
		$allowlist = $this->allowlist();

		$this->assertSame(['added' => true, 'reason' => ''], $allowlist->add(key: 'BRP_BASE_URL', principal: 'beheerder'));

		$entries = $allowlist->entries();
		$this->assertArrayHasKey('BRP_BASE_URL', $entries);
		$this->assertSame('beheerder', $entries['BRP_BASE_URL']['addedBy']);
		$this->assertNotSame('', $entries['BRP_BASE_URL']['addedAt']);

		$stored = (string)json_encode($this->config);
		$this->assertStringNotContainsString(
			'value',
			$stored,
			'the list holds keys and provenance; a stored value would publish the secret to everyone who can read the config'
		);
	}//end testAKeyIsListedWithItsPrincipalAndTheValueIsNeverStored()

	/**
	 * A key added by nobody is refused: there would be nobody to ask about it.
	 *
	 * @return void
	 */
	public function testAKeyAddedByNobodyIsRefused(): void {
		$result = $this->allowlist()->add(key: 'SMTP_HOST', principal: '  ');

		$this->assertFalse($result['added']);
		$this->assertStringContainsString('principal', $result['reason']);
	}//end testAKeyAddedByNobodyIsRefused()

	/**
	 * 🔴 A stored entry that would be refused today is dropped on READ too.
	 *
	 * A list edited around the validator — by hand, by an import, by an older
	 * release — must not become the way to get a wildcard in.
	 *
	 * @return void
	 */
	public function testAStoredWildcardIsDroppedOnRead(): void {
		$this->config[EnvironmentAllowlist::CONFIG_KEY] = (string)json_encode(
			[
				'*' => ['addedBy' => 'someone', 'addedAt' => 'then'],
				'SMTP_HOST' => ['addedBy' => 'beheerder', 'addedAt' => 'then'],
			]
		);

		$entries = $this->allowlist()->entries();

		$this->assertArrayNotHasKey('*', $entries, 'a wildcard smuggled into storage must not be honoured on read');
		$this->assertArrayHasKey('SMTP_HOST', $entries, 'while the real entry beside it survives');
	}//end testAStoredWildcardIsDroppedOnRead()

	/**
	 * 🔴 An unreadable list is an EMPTY list, never "allow everything".
	 *
	 * @return void
	 */
	public function testAnUnreadableListAllowsNothing(): void {
		$this->config[EnvironmentAllowlist::CONFIG_KEY] = '{"SMTP_HOST": ';

		$this->assertSame([], $this->allowlist()->entries());
		$this->assertFalse($this->allowlist()->allows(key: 'SMTP_HOST'));
	}//end testAnUnreadableListAllowsNothing()

	/**
	 * 🔴 The match is case sensitive, as an environment variable is.
	 *
	 * @return void
	 */
	public function testTheMatchIsCaseSensitive(): void {
		$allowlist = $this->allowlist();
		$allowlist->add(key: 'DB_PASSWORD', principal: 'beheerder');

		$this->assertTrue($allowlist->allows(key: 'DB_PASSWORD'));
		$this->assertFalse(
			$allowlist->allows(key: 'db_password'),
			'a case-insensitive check admits a different variable that nobody looked at'
		);
	}//end testTheMatchIsCaseSensitive()

	/**
	 * An allowlisted variable resolves.
	 *
	 * @return void
	 */
	public function testAnAllowlistedVariableResolves(): void {
		$allowlist = $this->allowlist();
		$allowlist->add(key: 'SMTP_HOST', principal: 'beheerder');

		$this->assertSame(
			'mail.gemeente.nl',
			$this->source(allowlist: $allowlist, environment: ['SMTP_HOST' => 'mail.gemeente.nl'])
				->resolve(key: 'SMTP_HOST')
		);
	}//end testAnAllowlistedVariableResolves()

	/**
	 * 🔴 A variable nobody allowed fails loudly — no value AND no empty string.
	 *
	 * An empty string renders as nothing: an email with a blank host, a URL
	 * with a blank base. The refusal has to be loud or it is not a refusal.
	 *
	 * @return void
	 */
	public function testAVariableNobodyAllowedFailsLoudly(): void {
		$source = $this->source(
			allowlist: $this->allowlist(),
			environment: ['DATABASE_PASSWORD' => 'hunter2']
		);

		try {
			$source->resolve(key: 'DATABASE_PASSWORD');
			$this->fail('an unallowlisted variable must not resolve');
		} catch (ExpressionValueRefused $refused) {
			$this->assertSame('DATABASE_PASSWORD', $refused->getKey(), 'the refusal names the key');
			$this->assertStringNotContainsString(
				'hunter2',
				$refused->getMessage(),
				'and never the value: a message that helpfully appends what it found is the leak with an apology attached'
			);
		}
	}//end testAVariableNobodyAllowedFailsLoudly()

	/**
	 * Allowlisted but unset is its own answer, not an empty value.
	 *
	 * @return void
	 */
	public function testAllowlistedButUnsetIsItsOwnAnswer(): void {
		$allowlist = $this->allowlist();
		$allowlist->add(key: 'BRP_BASE_URL', principal: 'beheerder');

		$this->expectException(ExpressionValueRefused::class);
		$this->source(allowlist: $allowlist)->resolve(key: 'BRP_BASE_URL');
	}//end testAllowlistedButUnsetIsItsOwnAnswer()

	/**
	 * A prefix is answered by its own source.
	 *
	 * @return void
	 */
	public function testAPrefixIsAnsweredByItsOwnSource(): void {
		$allowlist = $this->allowlist();
		$allowlist->add(key: 'SMTP_HOST', principal: 'beheerder');

		$registry = new ExpressionValueSourceRegistry(logger: $this->createMock(LoggerInterface::class));
		$registry->register($this->source(allowlist: $allowlist, environment: ['SMTP_HOST' => 'mail.gemeente.nl']));

		$this->assertSame('mail.gemeente.nl', $registry->resolve(reference: 'env:SMTP_HOST'));
	}//end testAPrefixIsAnsweredByItsOwnSource()

	/**
	 * 🔴 An unregistered prefix fails naming itself and consults NO other
	 * source.
	 *
	 * A fall-through means `secret:DATABASE_PASSWORD` gets answered by
	 * whichever source is next, and the author never learns their prefix does
	 * not exist.
	 *
	 * @return void
	 */
	public function testAnUnregisteredPrefixFailsAndDoesNotFallThrough(): void {
		$other = new class implements ExpressionValueSourceInterface {
			/**
			 * How many times this source was consulted.
			 *
			 * @var int
			 */
			public int $consulted = 0;

			/**
			 * @return string The prefix.
			 */
			public function prefix(): string {
				return 'other';
			}

			/**
			 * @param string               $key     The key.
			 * @param array<string, mixed> $context The context.
			 *
			 * @return mixed The value.
			 */
			public function resolve(string $key, array $context = []): mixed {
				$this->consulted++;
				return 'should never be reached';
			}

			/**
			 * @return array{prefix: string, writable: bool, allowlisted: bool, description: string} The description.
			 */
			public function describe(): array {
				return ['prefix' => 'other', 'writable' => false, 'allowlisted' => false, 'description' => 'test'];
			}

			/**
			 * @param string $key The key.
			 *
			 * @return bool Whether it is secret.
			 */
			public function isSecret(string $key): bool {
				return false;
			}
		};

		$registry = new ExpressionValueSourceRegistry(logger: $this->createMock(LoggerInterface::class));
		$registry->register($other);

		try {
			$registry->resolve(reference: 'secret:DATABASE_PASSWORD');
			$this->fail('an unregistered prefix must not resolve');
		} catch (ExpressionValueRefused $refused) {
			$this->assertSame('secret', $refused->getPrefix(), 'the refusal names the prefix, so a typo reads as a typo');
		}

		$this->assertSame(0, $other->consulted, 'no other source may be consulted');
	}//end testAnUnregisteredPrefixFailsAndDoesNotFallThrough()

	/**
	 * A reference with no prefix at all is refused, not treated as a key.
	 *
	 * @return void
	 */
	public function testAReferenceWithNoPrefixIsRefused(): void {
		$registry = new ExpressionValueSourceRegistry(logger: $this->createMock(LoggerInterface::class));

		$this->expectException(ExpressionValueRefused::class);
		$registry->resolve(reference: 'SMTP_HOST');
	}//end testAReferenceWithNoPrefixIsRefused()

	/**
	 * A key containing a colon survives: the split is on the FIRST separator.
	 *
	 * @return void
	 */
	public function testTheSplitIsOnTheFirstSeparator(): void {
		$allowlist = $this->allowlist();
		$registry = new ExpressionValueSourceRegistry(logger: $this->createMock(LoggerInterface::class));
		$registry->register($this->source(allowlist: $allowlist));

		try {
			$registry->resolve(reference: 'env:A:B');
			$this->fail('the key is not allowlisted, so it must refuse');
		} catch (ExpressionValueRefused $refused) {
			$this->assertSame('A:B', $refused->getKey(), 'the key keeps its colon rather than being truncated');
		}
	}//end testTheSplitIsOnTheFirstSeparator()

	/**
	 * 🔴 Two sources claiming one prefix: first wins, and the collision is
	 * visible.
	 *
	 * Resolving by "last registered" would make the answer depend on app load
	 * order — a value that changes when an unrelated app is enabled.
	 *
	 * @return void
	 */
	public function testFirstWinsOnACollisionAndTheCollisionIsVisible(): void {
		$allowlist = $this->allowlist();
		$allowlist->add(key: 'SMTP_HOST', principal: 'beheerder');

		$registry = new ExpressionValueSourceRegistry(logger: $this->createMock(LoggerInterface::class));
		$this->assertTrue(
			$registry->register($this->source(allowlist: $allowlist, environment: ['SMTP_HOST' => 'first']))
		);
		$this->assertFalse(
			$registry->register($this->source(allowlist: $allowlist, environment: ['SMTP_HOST' => 'second'])),
			'the second source claiming a prefix is refused'
		);

		$this->assertSame('first', $registry->resolve(reference: 'env:SMTP_HOST'));
		$this->assertSame(['env' => 1], $registry->collisions(), 'and the collision is visible, not swallowed');
	}//end testFirstWinsOnACollisionAndTheCollisionIsVisible()

	/**
	 * `describe()` says the prefix, the write capability and the allowlisting.
	 *
	 * @return void
	 */
	public function testDescribeSaysWhatTheSourceCanAnswer(): void {
		$described = $this->source(allowlist: $this->allowlist())->describe();

		$this->assertSame('env', $described['prefix']);
		$this->assertFalse($described['writable'], 'a caller can ask before it tries');
		$this->assertTrue($described['allowlisted']);
	}//end testDescribeSaysWhatTheSourceCanAnswer()

	/**
	 * 🔴 `env:` refuses a write, naming the prefix — never a silent success.
	 *
	 * @return void
	 */
	public function testEnvRefusesAWrite(): void {
		try {
			$this->source(allowlist: $this->allowlist())->store(key: 'SMTP_HOST', value: 'x');
			$this->fail('a write must not succeed silently');
		} catch (ExpressionValueRefused $refused) {
			$this->assertSame('env', $refused->getPrefix());
		}
	}//end testEnvRefusesAWrite()

	/**
	 * 🔴 Every `env:` value is a secret, and an unknown prefix is too.
	 *
	 * @return void
	 */
	public function testEveryEnvironmentValueIsTreatedAsASecret(): void {
		$registry = new ExpressionValueSourceRegistry(logger: $this->createMock(LoggerInterface::class));
		$registry->register($this->source(allowlist: $this->allowlist()));

		$this->assertTrue(
			$registry->isSecret(reference: 'env:SMTP_HOST'),
			'the distinction between a hostname and a password is not one a key name draws reliably'
		);
		$this->assertTrue(
			$registry->isSecret(reference: 'unknown:THING'),
			'and an unresolvable reference is more likely a typo for a real one than something worth printing'
		);
	}//end testEveryEnvironmentValueIsTreatedAsASecret()

	/**
	 * Removing a key takes it off the list.
	 *
	 * @return void
	 */
	public function testRemovingAKeyTakesItOffTheList(): void {
		$allowlist = $this->allowlist();
		$allowlist->add(key: 'SMTP_HOST', principal: 'beheerder');

		$this->assertTrue($allowlist->remove(key: 'SMTP_HOST'));
		$this->assertFalse($allowlist->allows(key: 'SMTP_HOST'));
		$this->assertFalse($allowlist->remove(key: 'SMTP_HOST'), 'removing what is not there is not a removal');
	}//end testRemovingAKeyTakesItOffTheList()
}//end class
