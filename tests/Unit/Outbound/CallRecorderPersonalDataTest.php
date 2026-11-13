<?php

/**
 * Integriq — personal data masked in an outbound call record and a message record.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
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

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\BodyRedactor;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Outbound\Call\CallRecorder;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\Integriq\Tests\Helpers\RegisterSchemaValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A call to a source whose investigation window is open, so its bodies are kept.
 */
class CallRecorderPersonalDataTest extends TestCase {

	/**
	 * The source the calls go to.
	 *
	 * @var string
	 */
	private const SOURCE = '7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f';

	/**
	 * Stored call records by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $records = [];

	/**
	 * The recorder under test.
	 *
	 * @var CallRecorder
	 */
	private CallRecorder $recorder;

	/**
	 * Build the recorder on a capturing object service and an open window.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				$this->assertSame([], RegisterSchemaValidator::errors((string)$schema, $object));
				$uuid = ($uuid ?? 'call-1');
				$this->records[$uuid] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);
		$objectService->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null) {
				if ($schema === 'source') {
					return ObjectServiceMockBuilder::objectEntity($this, ['bodyCaptureUntil' => (new \DateTimeImmutable('+1 hour'))->format('c')], (string)$id);
				}

				return ObjectServiceMockBuilder::objectEntity($this, ($this->records[$id] ?? []), (string)$id);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$this->recorder = new CallRecorder(
			$objectService,
			new BodyRedactor(new SensitiveFieldRegistry()),
			new BodyCapturePolicy($appConfig),
			new SensitiveFieldRegistry()
		);
	}//end setUp()

	/**
	 * A call with a surname in its JSON request and an e-mail address in its response.
	 *
	 * @param integer $statusCode The status the partner answered.
	 *
	 * @return array<string,mixed> The call.
	 */
	private static function call(int $statusCode): array {
		return [
			'source' => self::SOURCE,
			'target' => 'https://partner.example/api',
			'statusCode' => $statusCode,
			'statusMessage' => 'answered',
			'request' => ['method' => 'POST', 'body' => '{"geslachtsnaam":"Jansen","zaaktype":"z1"}'],
			'response' => ['body' => '{"email":"piet@example.invalid","status":"ok"}'],
		];
	}//end call()

	/**
	 * The stored request and response read the placeholder; the rest stays.
	 *
	 * @return void
	 */
	public function testTheStoredRequestAndResponseAreMasked(): void {
		$this->recorder->record(self::call(200));

		$record = $this->records['call-1'];
		$this->assertSame('{"geslachtsnaam":"[afgeschermd]","zaaktype":"z1"}', $record['request']['body']);
		$this->assertSame('{"email":"[afgeschermd]","status":"ok"}', $record['response']['body']);
		$this->assertArrayNotHasKey('replayRequest', $record);
	}//end testTheStoredRequestAndResponseAreMasked()

	/**
	 * A failed call keeps the real request as `replayRequest`, which a replay sends.
	 *
	 * @return void
	 */
	public function testAFailedCallKeepsTheRealRequestForItsReplay(): void {
		$this->recorder->record(self::call(502));

		$record = $this->records['call-1'];
		$this->assertSame('{"geslachtsnaam":"[afgeschermd]","zaaktype":"z1"}', $record['request']['body']);
		$this->assertSame('{"geslachtsnaam":"Jansen","zaaktype":"z1"}', $record['replayRequest']['body']);
	}//end testAFailedCallKeepsTheRealRequestForItsReplay()

	/**
	 * The response of a later attempt is masked too.
	 *
	 * @return void
	 */
	public function testAnAttemptsResponseIsMasked(): void {
		$this->recorder->record(self::call(502));
		$this->recorder->appendAttempt('call-1', ['statusCode' => 200, 'response' => ['body' => '<telefoonnummer>0612345678</telefoonnummer>']]);

		$this->assertSame('<telefoonnummer>[afgeschermd]</telefoonnummer>', $this->records['call-1']['response']['body']);
	}//end testAnAttemptsResponseIsMasked()

	/**
	 * The body redactor masks secrets and personal data in one pass, and only secrets when asked.
	 *
	 * @return void
	 */
	public function testTheBodyRedactorMasksPersonalDataInTheSamePass(): void {
		$redactor = new BodyRedactor();

		// Personal data is masked while the JSON is whole; the secret text
		// pattern then runs over the result as it always did.
		$this->assertSame(
			'{"voornamen":"[afgeschermd]","note":"token: [redacted]}',
			$redactor->redactBody('{"voornamen":"Piet","note":"token: abcdef123"}')
		);
		$this->assertSame(
			['postcode' => '[afgeschermd]', 'password' => '[redacted]', 'nested' => ['geboortedatum' => '[afgeschermd]', 'n' => 1], 'adres' => null],
			$redactor->redactContext(['postcode' => '1234AB', 'password' => 'p', 'nested' => ['geboortedatum' => '1980-01-01', 'n' => 1], 'adres' => null])
		);
		$this->assertSame(
			['postcode' => '1234AB', 'body' => '{"voornamen":"Piet"}'],
			$redactor->redactContext(['postcode' => '1234AB', 'body' => '{"voornamen":"Piet"}'], personalData: false)
		);
		$this->assertSame(['email' => '[afgeschermd]'], $redactor->maskPersonalData(['email' => 'a@b.nl']));
	}//end testTheBodyRedactorMasksPersonalDataInTheSamePass()
}//end class
