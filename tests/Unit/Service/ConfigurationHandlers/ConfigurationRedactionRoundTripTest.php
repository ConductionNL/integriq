<?php

/**
 * Export and re-import of redacted configurations.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\ConfigurationHandlers;

use OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler;
use OCA\Integriq\Service\ConfigurationHandlers\JobHandler;
use OCA\Integriq\Service\ConfigurationHandlers\MappingHandler;
use OCA\Integriq\Service\ConfigurationHandlers\RuleHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SourceHandler;
use OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\SaveCallShape;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;

/**
 * An exported rule, source or endpoint, imported again, keeps every setting
 * its authorisation and signature checks read. The masked credentials are left
 * out of the import, never stored as the public placeholder.
 * Each handler masks session headers and URL secrets in its configuration.
 *
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\RuleHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\SourceHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\EndpointHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\JobHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\MappingHandler
 * @covers \OCA\Integriq\Service\ConfigurationHandlers\SynchronizationHandler
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @uses   \OCA\Integriq\Service\Security\StructuredSecretRedactor
 * @uses   \OCA\Integriq\Service\Security\RedactedValueFilter
 * @uses   \OCA\Integriq\Service\Security\SensitiveValueMasker
 */
class ConfigurationRedactionRoundTripTest extends TestCase {

	private const MASK = SensitiveFieldRegistry::PLACEHOLDER;

	/**
	 * What import handed to saveObject.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * An OpenRegister object service that records what is saved.
	 *
	 * @return OrObjectService
	 */
	private function objects(): OrObjectService {
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('saveObject')->willReturnCallback(SaveCallShape::callback(
			function (array $object) {
				$this->saved[] = $object;
				return new ObjectEntity();
			}
		));
		return $objects;
	}//end objects()

	/**
	 * A stored entity with the given fields.
	 *
	 * @param array<string, mixed> $fields The stored fields.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $fields): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($fields);
		$entity->setUuid('entity-uuid');
		return $entity;
	}//end entity()

	/**
	 * Export then import one entity through a handler; the saved object.
	 *
	 * @param RuleHandler|SourceHandler|EndpointHandler $handler The handler.
	 * @param array<string, mixed>                      $fields  The stored fields.
	 *
	 * @return array<string, mixed>
	 */
	private function roundTrip(RuleHandler|SourceHandler|EndpointHandler $handler, array $fields): array {
		$this->saved = [];
		$handler->import($handler->export($this->entity(fields: $fields), []), []);
		$this->assertCount(1, $this->saved);
		return $this->saved[0];
	}//end roundTrip()

	/**
	 * An authentication rule keeps its type, users, groups and header; its api
	 * keys are left out, so OpenRegister keeps the stored write-only keys.
	 *
	 * @return void
	 */
	public function testAnAuthenticationRuleStillAuthorisesAfterARoundTrip(): void {
		$authentication = ['type' => 'apiKey', 'users' => ['alice'], 'groups' => ['admin'], 'header' => 'X-Api-Key', 'keys' => [['live-key' => 'alice']]];
		$saved = $this->roundTrip(
			handler: new RuleHandler($this->objects(), new SensitiveFieldRegistry()),
			fields: ['slug' => 'auth-rule', 'type' => 'authentication', 'configuration' => ['authentication' => $authentication]]
		);

		$restored = $saved['configuration']['authentication'];
		foreach (['type', 'users', 'groups', 'header'] as $setting) {
			$this->assertSame($authentication[$setting], $restored[$setting], $setting);
		}

		$this->assertSame('authentication', $saved['type']);
		$this->assertArrayNotHasKey('keys', $restored);
		$this->assertStringNotContainsString('live-key', json_encode($saved));
	}//end testAnAuthenticationRuleStillAuthorisesAfterARoundTrip()

	/**
	 * A webhook signature rule keeps its scheme, header and tolerance; its
	 * secret is left out rather than stored as the public placeholder.
	 *
	 * @return void
	 */
	public function testAWebhookSignatureRuleKeepsItsSchemeAfterARoundTrip(): void {
		$signature = ['scheme' => 'hmac-sha256', 'header' => 'X-Signature', 'toleranceSeconds' => 300, 'secret' => 'live-secret'];
		$saved = $this->roundTrip(
			handler: new RuleHandler($this->objects(), new SensitiveFieldRegistry()),
			fields: ['slug' => 'sig-rule', 'type' => 'webhook_signature', 'configuration' => ['webhook_signature' => $signature]]
		);

		$this->assertSame(
			['scheme' => 'hmac-sha256', 'header' => 'X-Signature', 'toleranceSeconds' => 300],
			$saved['configuration']['webhook_signature']
		);
	}//end testAWebhookSignatureRuleKeepsItsSchemeAfterARoundTrip()

	/**
	 * A source keeps how it authenticates and its URL templates; its secrets are left out.
	 *
	 * @return void
	 */
	public function testASourceKeepsItsAuthenticationSettingsAfterARoundTrip(): void {
		$saved = $this->roundTrip(
			handler: new SourceHandler($this->objects(), new SensitiveFieldRegistry()),
			fields: [
				'slug' => 'src',
				'configuration' => [
					'authentication' => ['scheme' => 'oauth2', 'mode' => 'client_credentials', 'grant_type' => 'client_credentials', 'client_id' => 'cid', 'client_secret' => 'live-cs'],
					'url' => 'https://api.example/v1?token={{ source.token }}&key=live-k',
					'headers' => ['X-Session-Id' => 'live-session'],
				],
			]
		);

		$this->assertSame(
			['scheme' => 'oauth2', 'mode' => 'client_credentials', 'grant_type' => 'client_credentials', 'client_id' => 'cid'],
			$saved['configuration']['authentication']
		);
		$this->assertSame('https://api.example/v1?token={{ source.token }}&key=' . self::MASK, $saved['configuration']['url']);
		$this->assertArrayNotHasKey('headers', $saved['configuration']);
		$this->assertStringNotContainsString('live-', json_encode($saved));
	}//end testASourceKeepsItsAuthenticationSettingsAfterARoundTrip()

	/**
	 * An endpoint keeps its path template and an api key id map; its inline secrets are left out.
	 *
	 * @return void
	 */
	public function testAnEndpointKeepsItsTemplatesAfterARoundTrip(): void {
		$saved = $this->roundTrip(
			handler: new EndpointHandler($this->objects(), new SensitiveFieldRegistry()),
			fields: [
				'slug' => 'ep',
				'endpoint' => '/api/x?token={{ request.token }}',
				'apiKeys' => ['id' => 'key-uuid'],
				'apiKey' => 'live-top',
			]
		);

		$this->assertSame('/api/x?token={{ request.token }}', $saved['endpoint']);
		$this->assertSame(['id' => 'key-uuid'], $saved['apiKeys']);
		$this->assertArrayNotHasKey('apiKey', $saved);
	}//end testAnEndpointKeepsItsTemplatesAfterARoundTrip()

	/**
	 * Every handler masks a session header and a URL secret in its configuration.
	 *
	 * @return void
	 */
	public function testEveryHandlerMasksSessionHeadersAndUrlSecrets(): void {
		$configuration = [
			'headers' => ['X-Session-Id' => 'live-session', 'Accept' => 'json'],
			'url' => 'https://user:live-pw@host.example/p?token=live-t&q=1',
		];
		$handlers = [
			'job' => new JobHandler($this->objects(), new SensitiveFieldRegistry()),
			'rule' => new RuleHandler($this->objects(), new SensitiveFieldRegistry()),
			'source' => new SourceHandler($this->objects(), new SensitiveFieldRegistry()),
			'mapping' => new MappingHandler($this->objects(), new SensitiveFieldRegistry()),
		];

		foreach ($handlers as $name => $handler) {
			$export = $handler->export($this->entity(fields: ['slug' => $name, 'configuration' => $configuration]), []);
			$this->assertSame(['X-Session-Id' => self::MASK, 'Accept' => 'json'], $export['configuration']['headers'], $name);
			$this->assertSame('https://user:' . self::MASK . '@host.example/p?token=' . self::MASK . '&q=1', $export['configuration']['url'], $name);
		}
	}//end testEveryHandlerMasksSessionHeadersAndUrlSecrets()

	/**
	 * Fields outside `configuration` are redacted too, on every handler whose
	 * schema keeps secrets there; a mapping's cast types stay readable.
	 *
	 * @return void
	 */
	public function testFieldsOutsideConfigurationAreRedacted(): void {
		$registry = new SensitiveFieldRegistry();
		$source = (new SourceHandler($this->objects(), $registry))->export(
			$this->entity(fields: ['slug' => 's', 'headers' => ['X-Session-Id' => 'live-1'], 'location' => 'https://u:live-2@api.example/?api_key=live-3', 'test' => ['token' => 'live-4']]),
			[]
		);
		$sync = (new SynchronizationHandler($this->objects(), $registry))->export(
			$this->entity(fields: ['slug' => 'y', 'sourceConfig' => ['headers' => ['Authorization' => 'live-5']], 'targetConfig' => ['url' => 'https://h.example/?token=live-6']]),
			[]
		);
		$job = (new JobHandler($this->objects(), $registry))->export(
			$this->entity(fields: ['slug' => 'j', 'arguments' => ['apiKey' => 'live-7', 'synchronizationId' => 'sync-1']]),
			[]
		);
		$mapping = (new MappingHandler($this->objects(), $registry))->export(
			$this->entity(fields: ['slug' => 'm', 'mapping' => ['apiKey' => 'live-8', 'token' => '{{ input.token }}', 'name' => '{{ input.name }}'], 'cast' => ['password' => 'string']]),
			[]
		);
		$rule = (new RuleHandler($this->objects(), $registry))->export(
			$this->entity(fields: ['slug' => 'r', 'action' => 'post', 'conditions' => ['token' => 'live-9'], 'secret' => 'live-10']),
			[]
		);

		foreach (['source' => $source, 'sync' => $sync, 'job' => $job, 'mapping' => $mapping, 'rule' => $rule] as $name => $export) {
			$this->assertStringNotContainsString('live-', json_encode($export), $name);
		}

		$this->assertSame('https://u:' . self::MASK . '@api.example/?api_key=' . self::MASK, $source['location']);
		$this->assertSame('sync-1', $job['arguments']['synchronizationId']);
		$this->assertSame(['apiKey' => self::MASK, 'token' => '{{ input.token }}', 'name' => '{{ input.name }}'], $mapping['mapping']);
		$this->assertSame(['password' => 'string'], $mapping['cast']);
		$this->assertSame('post', $rule['action']);
	}//end testFieldsOutsideConfigurationAreRedacted()

	/**
	 * Import leaves every masked value out, so the public placeholder is never stored.
	 *
	 * @return void
	 */
	public function testImportNeverStoresThePlaceholder(): void {
		$registry = new SensitiveFieldRegistry();
		$handlers = [
			new RuleHandler($this->objects(), $registry),
			new SourceHandler($this->objects(), $registry),
			new EndpointHandler($this->objects(), $registry),
			new JobHandler($this->objects(), $registry),
			new MappingHandler($this->objects(), $registry),
			new SynchronizationHandler($this->objects(), $registry),
		];

		foreach ($handlers as $handler) {
			$this->saved = [];
			$handler->import(['slug' => 'x', 'secret' => self::MASK, 'configuration' => ['webhook_signature' => ['scheme' => 'hmac-sha256', 'secret' => self::MASK]]], []);
			$this->assertStringNotContainsString(self::MASK, json_encode($this->saved), get_class($handler));
			$this->assertSame(['webhook_signature' => ['scheme' => 'hmac-sha256']], $this->saved[0]['configuration'], get_class($handler));
		}
	}//end testImportNeverStoresThePlaceholder()
}//end class
