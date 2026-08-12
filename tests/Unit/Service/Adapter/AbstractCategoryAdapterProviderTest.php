<?php

/**
 * Unit tests for AbstractCategoryAdapterProvider.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Adapter;

use OCA\OpenConnector\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A minimal fake adapter used only to exercise the shared base class's
 * contract (capability vocabulary, health check, credential resolution)
 * without depending on any of the four real vendor adapters.
 */
class FakeCategoryAdapter extends AbstractCategoryAdapterProvider {
	public function getId(): string {
		return 'fake-adapter';
	}

	public function getLabel(): string {
		return 'Fake Adapter';
	}

	public function getIcon(): string {
		return 'Puzzle';
	}

	public function getRequiredApp(): ?string {
		return null;
	}

	public function getCapabilities(): array {
		return ['fake-capability'];
	}

	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return [];
	}

	/**
	 * Expose the protected helper for direct testing.
	 *
	 * @param string $method HTTP method.
	 * @param string $path Vendor-relative path.
	 * @param array<string, string> $headers Optional headers.
	 * @param string|null $body Optional body.
	 *
	 * @return array|null
	 */
	public function callBrokeredRequest(string $method, string $path, array $headers = [], ?string $body = null): ?array {
		return $this->brokeredRequest(method: $method, path: $path, headers: $headers, body: $body);
	}
}

/**
 * Tests for the shared category-adapter base class's capability-vocabulary
 * + health-check + credential-broker contract, using {@see FakeCategoryAdapter}.
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-1
 */
class AbstractCategoryAdapterProviderTest extends TestCase {

	/**
	 * @var CredentialBrokerService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $credentialBroker;

	/**
	 * @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $appConfig;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var array<string,string>
	 */
	private array $configValues = [];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->configValues = [];
		$this->credentialBroker = $this->createMock(CredentialBrokerService::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				return ($this->configValues[$key] ?? $default);
			});
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the fake adapter under test.
	 *
	 * @return FakeCategoryAdapter
	 */
	private function buildAdapter(): FakeCategoryAdapter {
		return new FakeCategoryAdapter(
			credentialBroker: $this->credentialBroker,
			appConfig: $this->appConfig,
			logger: $this->logger
		);
	}//end buildAdapter()

	/**
	 * The capability vocabulary is exactly what the concrete adapter declares.
	 *
	 * @return void
	 */
	public function testCapabilityVocabularyIsDeclared(): void {
		$adapter = $this->buildAdapter();
		$this->assertSame(['fake-capability'], $adapter->getCapabilities());
	}//end testCapabilityVocabularyIsDeclared()

	/**
	 * Every category adapter uses the 'query-time' storage strategy.
	 *
	 * @return void
	 */
	public function testStorageStrategyIsQueryTime(): void {
		$adapter = $this->buildAdapter();
		$this->assertSame('query-time', $adapter->getStorageStrategy());
	}//end testStorageStrategyIsQueryTime()

	/**
	 * Without a configured credential UUID, the adapter is disabled and its
	 * health descriptor reports 'unavailable' / 'missing'.
	 *
	 * @return void
	 */
	public function testUnconfiguredAdapterIsDisabledAndUnhealthy(): void {
		$adapter = $this->buildAdapter();

		$this->assertFalse($adapter->isEnabled());

		$health = $adapter->health();
		$this->assertSame('unavailable', $health['status']);
		$this->assertSame('missing', $health['authStatus']);
		$this->assertStringContainsString('fake-adapter_credential_id', $health['message']);
	}//end testUnconfiguredAdapterIsDisabledAndUnhealthy()

	/**
	 * With a configured credential UUID, the adapter is enabled and healthy.
	 *
	 * @return void
	 */
	public function testConfiguredAdapterIsEnabledAndHealthy(): void {
		$this->configValues['fake-adapter_credential_id'] = 'cred-uuid-123';
		$adapter = $this->buildAdapter();

		$this->assertTrue($adapter->isEnabled());

		$health = $adapter->health();
		$this->assertSame('ok', $health['status']);
		$this->assertSame('configured', $health['authStatus']);
		$this->assertNull($health['message']);
	}//end testConfiguredAdapterIsEnabledAndHealthy()

	/**
	 * `brokeredRequest()` returns null (not an exception) when no credential
	 * is configured — callers treat this as "not configured yet", not an error.
	 *
	 * @return void
	 */
	public function testBrokeredRequestReturnsNullWhenUnconfigured(): void {
		$adapter = $this->buildAdapter();

		$this->credentialBroker->expects($this->never())->method('request');

		$this->assertNull($adapter->callBrokeredRequest('GET', '/anything'));
	}//end testBrokeredRequestReturnsNullWhenUnconfigured()

	/**
	 * `brokeredRequest()` calls the broker with the resolved credential id,
	 * the fixed appId 'openconnector', and passes through method/path/headers/body.
	 *
	 * @return void
	 */
	public function testBrokeredRequestDelegatesToBrokerWithResolvedCredential(): void {
		$this->configValues['fake-adapter_credential_id'] = 'cred-uuid-123';
		$adapter = $this->buildAdapter();

		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				'cred-uuid-123',
				'openconnector',
				'GET',
				'/v1.0/me',
				['X-Extra' => '1'],
				null,
				null
			)
			->willReturn(['status' => 200, 'headers' => [], 'body' => '{"ok":true}']);

		$result = $adapter->callBrokeredRequest('GET', '/v1.0/me', ['X-Extra' => '1']);

		$this->assertSame(200, $result['status']);
		$this->assertSame('{"ok":true}', $result['body']);
	}//end testBrokeredRequestDelegatesToBrokerWithResolvedCredential()

	/**
	 * A guard failure (`CredentialAccessDeniedException`) from the broker is
	 * caught and normalised to null, not propagated as an uncaught exception
	 * — an adapter's caller should see "no data", not a 500.
	 *
	 * @return void
	 */
	public function testBrokeredRequestReturnsNullOnAccessDenied(): void {
		$this->configValues['fake-adapter_credential_id'] = 'cred-uuid-123';
		$adapter = $this->buildAdapter();

		$this->credentialBroker->method('request')
			->willThrowException(new CredentialAccessDeniedException('host-lock violation'));

		$this->assertNull($adapter->callBrokeredRequest('GET', '/v1.0/me'));
	}//end testBrokeredRequestReturnsNullOnAccessDenied()

	/**
	 * Auth requirements always describe the credential-broker contract, not
	 * a hardcoded-secret shape.
	 *
	 * @return void
	 */
	public function testAuthRequirementsDescribeCredentialBroker(): void {
		$adapter = $this->buildAdapter();
		$this->assertSame('credential-broker', $adapter->authRequirements()['type']);
	}//end testAuthRequirementsDescribeCredentialBroker()
}//end class
