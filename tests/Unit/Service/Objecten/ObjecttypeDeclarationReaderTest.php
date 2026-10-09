<?php

/**
 * A leaf app declares the objecttypes it publishes in its own
 * `lib/Settings/objecttypes.json` (objecten-api-facade REQ-OAF-006, design D8).
 *
 * These tests put real declaration files in app directories on disk, read
 * them with the real reader and load them through the real gateway and
 * registry, so a declared objecttype is proven to reach the registry every
 * handler reads, and a configured one is proven to win over it.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md#requirement-a-leaf-app-declares-the-objecttypes-it-publishes-req-oaf-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Objecten;

use OCA\Integriq\Service\Objecten\ObjectenOpenRegisterAccess;
use OCA\Integriq\Service\Objecten\ObjecttypeDeclarationReader;
use OCA\Integriq\Service\Objecten\OpenRegisterObjectenGateway;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for reading leaf apps' objecttype declarations.
 */
class ObjecttypeDeclarationReaderTest extends TestCase {

	private const MELDING = '3a1e5b0c-6f7d-4c2e-9b8a-1d2c3e4f5a6b';

	private const BOOM = '7c9d8e1f-2a3b-4c5d-8e9f-0a1b2c3d4e5f';

	/**
	 * The directory the fake apps live in.
	 *
	 * @var string
	 */
	private string $apps;

	/**
	 * Warnings the logger received.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];


	/**
	 * Three apps: dossiq declares two objecttypes, broken ships a file that is
	 * not JSON, plain declares nothing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->apps = sys_get_temp_dir() . '/integriq-objecttypes-' . bin2hex(random_bytes(4));
		$this->declare(
			'dossiq',
			(string)json_encode(
				[
					'objecttypes' => [
						['uuid' => self::MELDING, 'name' => 'Melding openbare ruimte', 'register' => 'procest', 'schema' => 'caseObject', 'versions' => ['1']],
						['uuid' => self::BOOM, 'name' => 'Boom', 'register' => 'procest', 'schema' => 'tree'],
					],
				]
			)
		);
		$this->declare('broken', '{"objecttypes": [');
		mkdir($this->apps . '/plain', 0777, true);
	}//end setUp()

	/**
	 * Remove the fake apps.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (['dossiq', 'broken'] as $app) {
			@unlink($this->apps . '/' . $app . '/' . ObjecttypeDeclarationReader::DECLARATION_FILE);
			@rmdir($this->apps . '/' . $app . '/lib/Settings');
			@rmdir($this->apps . '/' . $app . '/lib');
			@rmdir($this->apps . '/' . $app);
		}

		@rmdir($this->apps . '/plain');
		@rmdir($this->apps);
	}//end tearDown()

	/**
	 * Write an app's declaration file.
	 *
	 * @param string $app     The app.
	 * @param string $content The file content.
	 *
	 * @return void
	 */
	private function declare(string $app, string $content): void {
		mkdir($this->apps . '/' . $app . '/lib/Settings', 0777, true);
		file_put_contents($this->apps . '/' . $app . '/' . ObjecttypeDeclarationReader::DECLARATION_FILE, $content);
	}//end declare()

	/**
	 * The reader over the three fake apps.
	 *
	 * @return ObjecttypeDeclarationReader The reader.
	 */
	private function reader(): ObjecttypeDeclarationReader {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getEnabledApps')->willReturn(['plain', 'dossiq', 'broken']);
		$appManager->method('getAppPath')->willReturnCallback(fn (string $app): string => $this->apps . '/' . $app);

		return new ObjecttypeDeclarationReader($appManager, $this->logger());
	}//end reader()

	/**
	 * A logger that keeps its warnings.
	 *
	 * @return LoggerInterface The logger.
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = strtr($message, ['{app}' => (string)($context['app'] ?? '')]);
			}
		);

		return $logger;
	}//end logger()

	/**
	 * Each declaration comes with the app that declared it; a broken file is
	 * skipped whole and named in the log; an app without a file adds nothing.
	 *
	 * @return void
	 */
	public function testTheReaderCollectsEachAppsDeclarations(): void {
		$declarations = $this->reader()->read();

		$this->assertCount(2, $declarations);
		$this->assertSame(self::MELDING, $declarations[0]['uuid']);
		$this->assertSame('dossiq', $declarations[0]['declaredBy']);
		$this->assertSame('dossiq', $declarations[1]['declaredBy']);
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('broken', $this->warnings[0]);
	}//end testTheReaderCollectsEachAppsDeclarations()

	/**
	 * The gateway with one configured objecttype that claims the MELDING uuid.
	 *
	 * @return OpenRegisterObjectenGateway The gateway.
	 */
	private function gateway(): OpenRegisterObjectenGateway {
		$access = $this->createMock(ObjectenOpenRegisterAccess::class);
		$access->method('declarations')->willReturnCallback(
			fn (string $schema): array => match ($schema) {
				ObjectenOpenRegisterAccess::OBJECTTYPE_SCHEMA => [
					['publishedUuid' => self::MELDING, 'name' => 'Melding (beheer)', 'register' => 'meldingen', 'schema' => 'melding'],
				],
				default => [],
			}
		);

		return new OpenRegisterObjectenGateway(access: $access, logger: $this->logger(), declarations: $this->reader());
	}//end gateway()

	/**
	 * REQ-OAF-006: dossiq publishes an objecttype by declaring it, and the
	 * registry every handler reads serves it.
	 *
	 * Red before: the gateway loaded only the configured objecttypes, so a
	 * leaf app had no way to publish one without a controller of its own.
	 *
	 * @return void
	 */
	public function testADeclaredObjecttypeReachesTheRegistry(): void {
		$boom = $this->gateway()->registry()->find(self::BOOM);

		$this->assertNotNull($boom);
		$this->assertSame('procest', $boom['register']);
		$this->assertSame('tree', $boom['schema']);
	}//end testADeclaredObjecttypeReachesTheRegistry()

	/**
	 * For one uuid the administrator's configuration wins, and the app's
	 * declaration is refused and logged with the app's name, never merged.
	 *
	 * @return void
	 */
	public function testAConfiguredObjecttypeWinsOverADeclaredOne(): void {
		$registry = $this->gateway()->registry();

		$this->assertSame('meldingen', $registry->find(self::MELDING)['register']);
		$this->assertCount(1, $registry->refused());
		$this->assertSame('dossiq', $registry->refused()[0]['declaration']['declaredBy']);
		$refusalLogged = array_filter($this->warnings, fn (string $warning): bool => str_contains($warning, 'declared by dossiq'));
		$this->assertCount(1, $refusalLogged);
	}//end testAConfiguredObjecttypeWinsOverADeclaredOne()
}//end class
