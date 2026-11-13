<?php

/**
 * Integriq — the personal data fields setting.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/call-log-personal-data-masking/specs/outbound-call-log/spec.md#requirement-personal-data-is-masked-before-a-body-is-logged-req-pdm-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\PersonalDataSettingsController;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Settings\IntegriqAdmin;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Read and replace the added field names; admin only.
 */
class PersonalDataSettingsControllerTest extends TestCase {

	/**
	 * The stored setting, as the app config double holds it.
	 *
	 * @var string|null
	 */
	private ?string $stored = null;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The controller under test.
	 *
	 * @var PersonalDataSettingsController
	 */
	private PersonalDataSettingsController $controller;

	/**
	 * Build the controller on an app config that keeps what is written.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->stored ?? $default
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->assertSame(['integriq', SensitiveFieldRegistry::PERSONAL_DATA_CONFIG_KEY], [$app, $key]);
				$this->stored = $value;
				return true;
			}
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				$this->assertSame(['integriq', SensitiveFieldRegistry::PERSONAL_DATA_CONFIG_KEY], [$app, $key]);
				$this->stored = null;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		$this->request = $this->createMock(IRequest::class);
		$this->controller = new PersonalDataSettingsController($this->request, $appConfig, $l10n);
	}//end setUp()

	/**
	 * Send `fields`.
	 *
	 * @param mixed $fields The parameter.
	 *
	 * @return void
	 */
	private function sending(mixed $fields): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'fields' ? $fields : $default
		);
	}//end sending()

	/**
	 * Both methods are admin-only.
	 *
	 * @return void
	 */
	public function testBothMethodsAreAdminOnly(): void {
		foreach (['getConfig', 'setConfig'] as $method) {
			$attributes = (new ReflectionMethod(PersonalDataSettingsController::class, $method))->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $method);
			$this->assertSame([IntegriqAdmin::class], array_values($attributes[0]->getArguments()), $method);
		}
	}//end testBothMethodsAreAdminOnly()

	/**
	 * The read lists the defaults and nothing added yet.
	 *
	 * @return void
	 */
	public function testTheReadListsTheDefaults(): void {
		$data = $this->controller->getConfig()->getData();

		$this->assertSame(SensitiveFieldRegistry::DEFAULT_PERSONAL_DATA_FIELDS, $data['defaults']);
		$this->assertSame([], $data['added']);
	}//end testTheReadListsTheDefaults()

	/**
	 * Names are stored trimmed and once, from a list or a comma-separated string.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function fieldLists(): array {
		return [
			'list' => [[' kenteken ', 'kenteken', '', 'BG:aanschrijfnaam', ['nested']]],
			'comma string' => ['kenteken, BG:aanschrijfnaam,,'],
		];
	}//end fieldLists()

	/**
	 * An administrator adds `kenteken`; it is stored and answered.
	 *
	 * @param mixed $fields The parameter.
	 *
	 * @return void
	 */
	#[DataProvider('fieldLists')]
	public function testAnAdministratorAddsFields(mixed $fields): void {
		$this->sending($fields);

		$response = $this->controller->setConfig();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('["kenteken","BG:aanschrijfnaam"]', $this->stored);
		$this->assertSame(['kenteken', 'BG:aanschrijfnaam'], $response->getData()['added']);
	}//end testAnAdministratorAddsFields()

	/**
	 * An empty list removes every addition.
	 *
	 * @return void
	 */
	public function testAnEmptyListRemovesTheAdditions(): void {
		$this->stored = '["kenteken"]';
		$this->sending([]);

		$response = $this->controller->setConfig();

		$this->assertNull($this->stored);
		$this->assertSame([], $response->getData()['added']);
	}//end testAnEmptyListRemovesTheAdditions()

	/**
	 * A value that is not a field name is refused by name, and nothing is stored.
	 *
	 * @return void
	 */
	public function testANonNameIsRefused(): void {
		$this->sending(['kenteken', '!!!']);

		$response = $this->controller->setConfig();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => '"!!!" is not a field name.'], $response->getData());
		$this->assertNull($this->stored);
	}//end testANonNameIsRefused()

	/**
	 * A name longer than 100 characters is refused.
	 *
	 * @return void
	 */
	public function testAnOverlongNameIsRefused(): void {
		$this->sending([str_repeat('a', 101)]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->setConfig()->getStatus());
		$this->assertNull($this->stored);
	}//end testAnOverlongNameIsRefused()

	/**
	 * Fields sent as neither a list nor a string are refused.
	 *
	 * @return void
	 */
	public function testFieldsThatAreNotAListAreRefused(): void {
		$this->sending(42);

		$response = $this->controller->setConfig();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Send the field names as a list.'], $response->getData());
	}//end testFieldsThatAreNotAListAreRefused()
}//end class
