<?php

/**
 * Unit tests for S3Adapter.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\DataInfra\S3Adapter;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the S3-compatible reference adapter (REQ-DIC-001).
 *
 * @spec openspec/changes/connector-category-adapter-scaffolding/tasks.md#task-5
 */
class S3AdapterTest extends TestCase {

	/**
	 * @var CredentialBrokerService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $credentialBroker;

	/**
	 * @var S3Adapter
	 */
	private S3Adapter $adapter;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->credentialBroker = $this->createMock(CredentialBrokerService::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('cred-uuid-s3');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->adapter = new S3Adapter(
			credentialBroker: $this->credentialBroker,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n
		);
	}//end setUp()

	/**
	 * Declares the read/write/list capability vocabulary.
	 *
	 * @return void
	 */
	public function testCapabilities(): void {
		$this->assertSame(['object-read', 'object-write', 'object-list'], $this->adapter->getCapabilities());
	}//end testCapabilities()

	/**
	 * `listObjects()` parses a real `ListObjectsV2` XML response shape.
	 *
	 * @return void
	 */
	public function testListObjectsParsesListObjectsV2Xml(): void {
		$xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
    <Name>my-bucket</Name>
    <Contents>
        <Key>folder/report.pdf</Key>
        <LastModified>2026-01-01T00:00:00.000Z</LastModified>
        <ETag>&quot;d41d8cd98f00b204e9800998ecf8427e&quot;</ETag>
        <Size>12345</Size>
    </Contents>
</ListBucketResult>
XML;

		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				'cred-uuid-s3',
				'openconnector',
				'GET',
				$this->stringContains('/my-bucket?list-type=2'),
				[],
				null,
				null
			)
			->willReturn(['status' => 200, 'headers' => [], 'body' => $xml]);

		$objects = $this->adapter->listObjects('my-bucket');

		$this->assertCount(1, $objects);
		$this->assertSame('folder/report.pdf', $objects[0]['key']);
		$this->assertSame(12345, $objects[0]['size']);
		$this->assertSame('d41d8cd98f00b204e9800998ecf8427e', $objects[0]['etag']);
	}//end testListObjectsParsesListObjectsV2Xml()

	/**
	 * A prefix filter is appended to the list path.
	 *
	 * @return void
	 */
	public function testListObjectsAppliesPrefixFilter(): void {
		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				'cred-uuid-s3',
				'openconnector',
				'GET',
				$this->stringContains('prefix=reports%2F'),
				[],
				null,
				null
			)
			->willReturn(['status' => 200, 'headers' => [], 'body' => '<ListBucketResult></ListBucketResult>']);

		$this->adapter->listObjects('my-bucket', 'reports/');
	}//end testListObjectsAppliesPrefixFilter()

	/**
	 * `readObject()` returns the raw response body on a 2xx status.
	 *
	 * @return void
	 */
	public function testReadObjectReturnsRawBody(): void {
		$this->credentialBroker->method('request')
			->willReturn(['status' => 200, 'headers' => [], 'body' => 'binary-content']);

		$this->assertSame('binary-content', $this->adapter->readObject('my-bucket', 'folder/report.pdf'));
	}//end testReadObjectReturnsRawBody()

	/**
	 * `readObject()` returns null (not an exception) on a 404.
	 *
	 * @return void
	 */
	public function testReadObjectReturnsNullOnNotFound(): void {
		$this->credentialBroker->method('request')
			->willReturn(['status' => 404, 'headers' => [], 'body' => '']);

		$this->assertNull($this->adapter->readObject('my-bucket', 'missing.txt'));
	}//end testReadObjectReturnsNullOnNotFound()

	/**
	 * `writeObject()` issues a PUT with the raw body and returns the upstream status.
	 *
	 * @return void
	 */
	public function testWriteObjectIssuesPut(): void {
		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				'cred-uuid-s3',
				'openconnector',
				'PUT',
				'/my-bucket/report.pdf',
				[],
				'file-bytes',
				null
			)
			->willReturn(['status' => 200, 'headers' => [], 'body' => '']);

		$result = $this->adapter->writeObject('my-bucket', 'report.pdf', 'file-bytes');

		$this->assertSame(200, $result['status']);
	}//end testWriteObjectIssuesPut()

	/**
	 * Object keys containing `/` keep the slash literal (path separator),
	 * rather than percent-encoding it to `%2F`.
	 *
	 * @return void
	 */
	public function testObjectKeyWithSlashPreservesPathSeparators(): void {
		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				'cred-uuid-s3',
				'openconnector',
				'GET',
				'/my-bucket/folder/sub%20folder/report.pdf',
				[],
				null,
				null
			)
			->willReturn(['status' => 200, 'headers' => [], 'body' => 'x']);

		$this->adapter->readObject('my-bucket', 'folder/sub folder/report.pdf');
	}//end testObjectKeyWithSlashPreservesPathSeparators()

	/**
	 * `get()` (the generic IntegrationProvider entry point) expects a
	 * "bucket/key" shaped entityId and throws `DoesNotExistException` for
	 * a malformed shape.
	 *
	 * @return void
	 */
	public function testGetThrowsOnMalformedEntityId(): void {
		$this->expectException(DoesNotExistException::class);

		$this->adapter->get('register', 'schema', 'object-id', 'no-slash-here');
	}//end testGetThrowsOnMalformedEntityId()

	/**
	 * `get()` splits "bucket/key" and delegates to `readObject()`.
	 *
	 * @return void
	 */
	public function testGetSplitsBucketAndKey(): void {
		$this->credentialBroker->method('request')
			->willReturn(['status' => 200, 'headers' => [], 'body' => 'content-here']);

		$result = $this->adapter->get('register', 'schema', 'object-id', 'my-bucket/folder/file.txt');

		$this->assertSame('my-bucket', $result['bucket']);
		$this->assertSame('folder/file.txt', $result['key']);
		$this->assertSame('content-here', $result['content']);
	}//end testGetSplitsBucketAndKey()

	/**
	 * `list()` requires `$filters['bucket']`; missing it returns an empty array.
	 *
	 * @return void
	 */
	public function testListRequiresBucketFilter(): void {
		$this->credentialBroker->expects($this->never())->method('request');

		$this->assertSame([], $this->adapter->list('register', 'schema', 'object-id', []));
	}//end testListRequiresBucketFilter()

	// ---------------------------------------------------------------------
	// The write seam (integriq#1191).
	//
	// `object-write` has been advertised by getCapabilities() and implemented
	// by writeObject() since this adapter was scaffolded, but create()/update()
	// were never overridden — so every write arriving through the
	// IntegrationProvider interface hit AbstractIntegrationProvider's default
	// and threw NotImplementedException. These tests pin the seam that makes
	// the advertised capability reachable, and the two failure shapes that must
	// never be reported as a successful write.
	// ---------------------------------------------------------------------

	/**
	 * `create()` PUTs the content to `/{bucket}/{key}` and reports the upstream
	 * status. The METHOD is asserted because a GET here would silently read
	 * instead of write, and the path is asserted because writing to the wrong
	 * key is indistinguishable from success at the status level.
	 *
	 * @return void
	 */
	public function testCreatePutsTheContentToTheBucketKeyPath(): void {
		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				credentialId: 'cred-uuid-s3',
				appId: 'openconnector',
				method: 'PUT',
				path: '/my-bucket/folder/file.txt',
				headers: [],
				body: 'the-content'
			)
			->willReturn(['status' => 200, 'headers' => [], 'body' => '']);

		$result = $this->adapter->create(
			'register',
			'schema',
			'object-id',
			['bucket' => 'my-bucket', 'key' => 'folder/file.txt', 'content' => 'the-content']
		);

		$this->assertSame('my-bucket', $result['bucket']);
		$this->assertSame('folder/file.txt', $result['key']);
		$this->assertSame(200, $result['status']);
	}//end testCreatePutsTheContentToTheBucketKeyPath()

	/**
	 * A payload without a bucket or key is refused before anything is sent —
	 * a PUT to a path assembled from empty segments would target the bucket
	 * root.
	 *
	 * @return void
	 */
	public function testCreateWithoutBucketOrKeyNeverReachesTheBroker(): void {
		$this->credentialBroker->expects($this->never())->method('request');

		$this->expectException(DoesNotExistException::class);

		$this->adapter->create('register', 'schema', 'object-id', ['content' => 'orphan']);
	}//end testCreateWithoutBucketOrKeyNeverReachesTheBroker()

	/**
	 * `update()` takes the same `"bucket/key"` address `get()` accepts, so an
	 * object that was read can be written back without re-deriving it. S3 has
	 * no distinct update verb — a PUT to an existing key replaces it.
	 *
	 * @return void
	 */
	public function testUpdateAddressesTheObjectTheSameWayGetDoes(): void {
		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				credentialId: 'cred-uuid-s3',
				appId: 'openconnector',
				method: 'PUT',
				path: '/my-bucket/folder/file.txt',
				headers: [],
				body: 'replacement'
			)
			->willReturn(['status' => 204, 'headers' => [], 'body' => '']);

		$result = $this->adapter->update(
			'register',
			'schema',
			'object-id',
			'my-bucket/folder/file.txt',
			['content' => 'replacement']
		);

		$this->assertSame('my-bucket', $result['bucket']);
		$this->assertSame('folder/file.txt', $result['key']);
		$this->assertSame(204, $result['status']);
	}//end testUpdateAddressesTheObjectTheSameWayGetDoes()

	/**
	 * A malformed entityId is refused before anything is sent, matching
	 * `get()`'s contract rather than writing to a guessed address.
	 *
	 * @return void
	 */
	public function testUpdateRejectsAMalformedEntityIdWithoutWriting(): void {
		$this->credentialBroker->expects($this->never())->method('request');

		$this->expectException(DoesNotExistException::class);

		$this->adapter->update('register', 'schema', 'object-id', 'no-slash-here', ['content' => 'x']);
	}//end testUpdateRejectsAMalformedEntityIdWithoutWriting()

	/**
	 * FAILURE SHAPE 1 — the brokered request never completed. The broker
	 * enforces four guards (owner, allowed-app, allow-rules, host-lock) and
	 * raises on denial; `brokeredRequest()` catches that and returns null, so
	 * `writeObject()` returns null and no status ever exists. Returning an
	 * array here would report a write that never left the process.
	 *
	 * @return void
	 */
	public function testAnIncompleteBrokeredRequestIsNotReportedAsAWrite(): void {
		$this->credentialBroker->method('request')->willThrowException(
			new CredentialAccessDeniedException('host-lock denied')
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('did not complete');

		$this->adapter->create(
			'register',
			'schema',
			'object-id',
			['bucket' => 'my-bucket', 'key' => 'k', 'content' => 'c']
		);
	}//end testAnIncompleteBrokeredRequestIsNotReportedAsAWrite()

	/**
	 * FAILURE SHAPE 2 — the bucket answered, and refused. A 403 is a completed
	 * HTTP exchange, so the null check above does not catch it; without the
	 * status check a permission denial would be returned as `status: 403` in a
	 * success-shaped array and read as a stored object.
	 *
	 * @return void
	 */
	public function testAnUpstreamRejectionIsNotReportedAsAWrite(): void {
		$this->credentialBroker->method('request')
			->willReturn(['status' => 403, 'headers' => [], 'body' => 'AccessDenied']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('rejected upstream (status 403)');

		$this->adapter->update(
			'register',
			'schema',
			'object-id',
			'my-bucket/k',
			['content' => 'c']
		);
	}//end testAnUpstreamRejectionIsNotReportedAsAWrite()

	/**
	 * An absent `content` key writes an empty object rather than failing — an
	 * empty object is a legitimate S3 write (it is how a "directory marker" is
	 * created), and the caller asked for a write.
	 *
	 * @return void
	 */
	public function testAbsentContentWritesAnEmptyObject(): void {
		$this->credentialBroker->expects($this->once())
			->method('request')
			->with(
				credentialId: 'cred-uuid-s3',
				appId: 'openconnector',
				method: 'PUT',
				path: '/my-bucket/marker/',
				headers: [],
				body: ''
			)
			->willReturn(['status' => 200, 'headers' => [], 'body' => '']);

		$result = $this->adapter->create(
			'register',
			'schema',
			'object-id',
			['bucket' => 'my-bucket', 'key' => 'marker/']
		);

		$this->assertSame(200, $result['status']);
	}//end testAbsentContentWritesAnEmptyObject()
}//end class
