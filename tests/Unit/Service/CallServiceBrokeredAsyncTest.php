<?php

/**
 * A brokered source is never dispatched asynchronously.
 *
 * `BrokeredCallService::assertScopeGuards()` refuses asynchronous dispatch
 * outright — "the brokered call is synchronous in-process" — and that refusal
 * becomes a persisted synthetic 409 CallLog rather than a thrown error. So a
 * caller reaching `callAsync()` with a credentialRef source got a 409 instead
 * of a response, whatever the source pointed at.
 *
 * It was not a corner case. `openconnector.source-call` fans out through
 * `callAsync()` unconditionally, so every flow step against a brokered source
 * failed. Measured on hydra, whose forge source is brokered:
 *
 *     The call to source "5c5c1ca6-…" endpoint "/rate_limit" returned status 409
 *     (credentialRef does not support asynchronous dispatch (v1 scope) — the
 *      brokered call is synchronous in-process.)
 *
 * `find-work`, `describe-repo`, `advance` and the record read all sit on that
 * node, which is the entire forge path of the pipeline.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\BrokeredCallService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Brokered-source detection ahead of asynchronous dispatch.
 */
class CallServiceBrokeredAsyncTest extends TestCase {

	/**
	 * A source whose own configuration carries the given authentication block.
	 *
	 * A REAL ObjectEntity: its accessors go through `Entity::__call`, and
	 * PHPUnit refuses to stub magic methods.
	 *
	 * @param array $authentication The source's `configuration.authentication`.
	 *
	 * @return ObjectEntity The source.
	 */
	private function source(array $authentication): ObjectEntity {
		$source = new ObjectEntity();
		$source->setObject(
			[
				'name' => 'probe source',
				'location' => 'https://api.example.test',
				'configuration' => ['authentication' => $authentication],
			]
		);

		return $source;
	}//end source()

	/**
	 * Ask the real detection helper about a source/config pair.
	 *
	 * Drives `CallService::dispatchesThroughBroker()` through reflection on an
	 * UNCONSTRUCTED instance: the method reads only `$this->brokeredCallService`,
	 * and building the full service would need its whole dependency graph to
	 * answer a question about two arrays.
	 *
	 * @param ObjectEntity $source The source.
	 * @param array $config The per-call configuration.
	 *
	 * @return boolean The helper's answer.
	 */
	private function dispatchesThroughBroker(ObjectEntity $source, array $config): bool {
		$service = (new \ReflectionClass(\OCA\OpenConnector\Service\CallService::class))
			->newInstanceWithoutConstructor();

		$broker = new \ReflectionProperty(\OCA\OpenConnector\Service\CallService::class, 'brokeredCallService');
		$broker->setAccessible(true);
		$broker->setValue(
			$service,
			(new \ReflectionClass(BrokeredCallService::class))->newInstanceWithoutConstructor()
		);

		$method = new ReflectionMethod(\OCA\OpenConnector\Service\CallService::class, 'dispatchesThroughBroker');
		$method->setAccessible(true);

		return (bool)$method->invoke($service, $source, $config);
	}//end dispatchesThroughBroker()

	/**
	 * A credentialRef on the SOURCE is detected.
	 *
	 * This is hydra's shape: the forge source carries the reference, and the
	 * flow node passes no authentication of its own.
	 *
	 * @return void
	 */
	public function testACredentialRefOnTheSourceIsDetected(): void {
		$source = $this->source(['credentialRef' => ['credentialId' => 'abc']]);

		$this->assertTrue($this->dispatchesThroughBroker($source, []));

	}//end testACredentialRefOnTheSourceIsDetected()

	/**
	 * A credentialRef on the CALL config is detected.
	 *
	 * @return void
	 */
	public function testACredentialRefOnTheCallConfigIsDetected(): void {
		$source = $this->source([]);

		$this->assertTrue(
			$this->dispatchesThroughBroker($source, ['authentication' => ['credentialRef' => ['credentialId' => 'abc']]])
		);

	}//end testACredentialRefOnTheCallConfigIsDetected()

	/**
	 * A source with no credentialRef is NOT diverted.
	 *
	 * The counterpart that keeps the fix honest: without it, the helper could
	 * return true unconditionally, every test above would pass, and
	 * concurrency would be silently removed from every unbrokered source in
	 * the fleet.
	 *
	 * @return void
	 */
	public function testAnUnbrokeredSourceStillDispatchesAsynchronously(): void {
		$source = $this->source(['apikey' => 'literal-not-a-reference']);

		$this->assertFalse($this->dispatchesThroughBroker($source, []));

	}//end testAnUnbrokeredSourceStillDispatchesAsynchronously()

	/**
	 * A source with no authentication block at all is not diverted.
	 *
	 * @return void
	 */
	public function testASourceWithoutAuthenticationIsNotDiverted(): void {
		$source = new ObjectEntity();
		$source->setObject(['name' => 'bare', 'location' => 'https://api.example.test']);

		$this->assertFalse($this->dispatchesThroughBroker($source, []));

	}//end testASourceWithoutAuthenticationIsNotDiverted()

}//end class
