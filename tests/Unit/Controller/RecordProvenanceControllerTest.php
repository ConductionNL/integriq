<?php

/**
 * Unit tests for RecordProvenanceController: the read right is checked before anything is read.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/record-provenance-tab/specs/synchronization/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Controller;

use OCA\Integriq\Controller\RecordProvenanceController;
use OCA\Integriq\Service\RecordProvenanceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 400 without the three parameters, 404 for a record outside the caller's rights, the provenance otherwise.
 *
 * @spec openspec/changes/record-provenance-tab/specs/synchronization/spec.md#requirement-a-record-says-where-it-was-synchronized-from
 */
final class RecordProvenanceControllerTest extends TestCase {

	/**
	 * Build the controller.
	 *
	 * @param array<string, string> $params     The request parameters.
	 * @param ObjectEntity|null      $record     What the rights-checked read returns.
	 * @param RecordProvenanceService $provenance The service.
	 *
	 * @return RecordProvenanceController
	 */
	private function controller(array $params, ?ObjectEntity $record, RecordProvenanceService $provenance): RecordProvenanceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));
		$objects = $this->createMock(OrObjectService::class);
		if ($record === null) {
			$objects->method('find')->willThrowException(new RuntimeException('not found'));
		} else {
			$objects->expects($this->once())->method('find')
				->with('case-82', 'dossiq', 'case', true, true)
				->willReturn($record);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new RecordProvenanceController('integriq', $request, $provenance, $objects, $l10n);
	}//end controller()

	/**
	 * A readable record gets its provenance.
	 *
	 * @return void
	 */
	public function testAReadableRecordGetsItsProvenance(): void {
		$record = new ObjectEntity();
		$record->setUuid('case-82');
		$provenance = $this->createMock(RecordProvenanceService::class);
		$provenance->expects($this->once())->method('provenance')->with('case-82')->willReturn(['synced' => true]);

		$response = $this->controller(['register' => 'dossiq', 'schema' => 'case', 'id' => 'case-82'], $record, $provenance)->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['synced' => true], $response->getData());
	}//end testAReadableRecordGetsItsProvenance()

	/**
	 * A record outside the caller's rights is a 404 and nothing is read.
	 *
	 * @return void
	 */
	public function testARecordTheCallerCannotReadIsA404(): void {
		$provenance = $this->createMock(RecordProvenanceService::class);
		$provenance->expects($this->never())->method('provenance');

		$response = $this->controller(['register' => 'dossiq', 'schema' => 'case', 'id' => 'case-82'], null, $provenance)->show();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testARecordTheCallerCannotReadIsA404()

	/**
	 * Without register, schema and id the answer is 400.
	 *
	 * @return void
	 */
	public function testWithoutTheRecordItIsA400(): void {
		$provenance = $this->createMock(RecordProvenanceService::class);
		$provenance->expects($this->never())->method('provenance');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(['id' => 'case-82'], null, $provenance)->show()->getStatus());
	}//end testWithoutTheRecordItIsA400()
}//end class
