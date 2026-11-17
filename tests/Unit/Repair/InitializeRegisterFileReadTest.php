<?php

/**
 * How the register initialisation step reads its descriptor and fragment files.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/repair-and-app-boot/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\InitializeRegister;
use OCA\Integriq\Repair\RegisterImportRun;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * A descriptor that does not parse stops the import with a warning; a fragment
 * that does not parse is skipped and leaves the descriptor and signature alone.
 */
#[CoversClass(InitializeRegister::class)]
#[UsesClass(RegisterImportRun::class)]
final class InitializeRegisterFileReadTest extends TestCase {

	/**
	 * Temporary directory holding this test's files.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Create the temporary directory and the logger double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/integriq-init-register-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Remove the temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ((glob($this->dir . '/*') ?: []) as $file) {
			unlink($file);
		}

		rmdir($this->dir);
		parent::tearDown();
	}//end tearDown()

	/**
	 * Write a file into the temporary directory.
	 *
	 * @param string $name    File name.
	 * @param string $content File content.
	 *
	 * @return string Absolute path.
	 */
	private function file(string $name, string $content): string {
		$path = $this->dir . '/' . $name;
		file_put_contents($path, $content);
		return $path;
	}//end file()

	/**
	 * Invoke a private method of the step.
	 *
	 * @param string               $method Method name.
	 * @param array<string, mixed> $args   Named arguments.
	 *
	 * @return mixed
	 */
	private function invoke(string $method, array $args): mixed {
		$step = new InitializeRegister(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->logger
		);

		return (new ReflectionMethod(InitializeRegister::class, $method))->invokeArgs($step, $args);
	}//end invoke()

	/**
	 * A descriptor that is not JSON warns, logs and reports failure.
	 *
	 * @return void
	 */
	public function testAMalformedDescriptorWarnsLogsAndFails(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Integriq: register descriptor JSON parse failed: Syntax error');
		$this->logger->expects($this->once())->method('error')
			->with('Integriq: descriptor JSON parse failed', ['exception' => 'Syntax error']);
		$run = new RegisterImportRun(output: $output);

		$result = $this->invoke('decodeDescriptor', ['run' => $run, 'descriptorPath' => $this->file('register.json', '{"openapi": ')]);

		$this->assertFalse($result);
		$this->assertNull($run->descriptor);
	}//end testAMalformedDescriptorWarnsLogsAndFails()

	/**
	 * A well-formed descriptor is decoded into the run.
	 *
	 * @return void
	 */
	public function testAWellFormedDescriptorIsDecodedIntoTheRun(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');
		$run = new RegisterImportRun(output: $output);

		$result = $this->invoke('decodeDescriptor', ['run' => $run, 'descriptorPath' => $this->file('register.json', '{"openapi":"3.0.0","info":{"version":"2.1.0"}}')]);

		$this->assertTrue($result);
		$this->assertSame(['openapi' => '3.0.0', 'info' => ['version' => '2.1.0']], $run->descriptor);
	}//end testAWellFormedDescriptorIsDecodedIntoTheRun()

	/**
	 * A malformed fragment is skipped with a warning on both channels.
	 *
	 * @return void
	 */
	public function testAMalformedFragmentIsSkipped(): void {
		$message = 'Integriq: skipping malformed register fragment broken.json: Syntax error';
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($message);
		$this->logger->expects($this->once())->method('warning')->with($message);
		$run = new RegisterImportRun(output: $output);
		$run->descriptor = ['components' => ['schemas' => ['source' => ['type' => 'object']]]];

		$this->invoke('mergeFragment', ['run' => $run, 'fragmentFile' => $this->file('broken.json', '{"components": [')]);

		$this->assertSame(['components' => ['schemas' => ['source' => ['type' => 'object']]]], $run->descriptor);
		$this->assertSame('', $run->fragmentSignature);
	}//end testAMalformedFragmentIsSkipped()

	/**
	 * A well-formed fragment is merged and recorded in the signature.
	 *
	 * @return void
	 */
	public function testAWellFormedFragmentIsMergedAndSigned(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('warning');
		$run = new RegisterImportRun(output: $output);
		$run->descriptor = ['components' => ['schemas' => ['source' => ['type' => 'object']]]];
		$content = '{"components":{"schemas":{"job":{"type":"object"}}}}';

		$this->invoke('mergeFragment', ['run' => $run, 'fragmentFile' => $this->file('jobs.json', $content)]);

		$this->assertSame(
			['components' => ['schemas' => ['source' => ['type' => 'object'], 'job' => ['type' => 'object']]]],
			$run->descriptor
		);
		$this->assertSame('jobs.json:' . md5($content) . ';', $run->fragmentSignature);
	}//end testAWellFormedFragmentIsMergedAndSigned()
}//end class
