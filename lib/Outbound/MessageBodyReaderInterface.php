<?php

/**
 * Contract of the MessageBodyReader.
 *
 * @category Contract
 * @package  OCA\Integriq\Outbound
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Outbound;

use OCP\IUser;

/**
 * Public operations of {@see MessageBodyReader} that other components call.
 */
interface MessageBodyReaderInterface {
	/**
	 * Read one message's body and its resolved recipients.
	 *
	 * @param string $uuid The record uuid.
	 * @param IUser $user Who is asking.
	 *
	 * @return array{body:string,subject:string,recipients:array<int,array<string,mixed>>} The body.
	 *
	 * @throws \OCP\AppFramework\OCS\OCSForbiddenException When the principal may not read bodies.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When there is no such record.
	 *
	 * @spec exclude declaration only, the behaviour and its spec live on MessageBodyReader::read()
	 */
	public function read(string $uuid, IUser $user): array;
}
