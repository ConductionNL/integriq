<?php

/**
 * Contract of the IBabsConnectorService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see IBabsConnectorService} that other components call.
 */
interface IBabsConnectorServiceInterface {
	/**
	 * Test the connection to an iBabs API source.
	 *
	 * Makes a lightweight GET request to list vergaderingen to verify connectivity.
	 *
	 * @param ObjectEntity $source The iBabs source configuration.
	 *
	 * @return array{success: bool, message: string} Result with success flag and message.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-1
	 */
	public function testConnection(ObjectEntity $source): array;

	/**
	 * Push a collegevoorstel document to iBabs.
	 *
	 * Uploads the document (PDF) and its bijlagen to iBabs and creates
	 * a vergaderstuk linked to the specified vergadering.
	 *
	 * @param ObjectEntity $source The iBabs source configuration.
	 * @param array $proposal The voorstel data including document path and metadata.
	 *                        Expected keys: onderwerp, portefeuillehouder, zaaktype,
	 *                        geheimhouding (bool), bijlagen (array of {name, content}).
	 *
	 * @return array{success: bool, message: string, vergaderstukId: string|null} Upload result.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-2
	 */
	public function pushProposal(ObjectEntity $source, array $proposal): array;

	/**
	 * Create an agendapunt in iBabs linked to a vergaderstuk.
	 *
	 * Queries available vergaderingen and selects the next upcoming one
	 * (auto-select) or uses the provided vergaderingId.
	 *
	 * @param ObjectEntity $source The iBabs source configuration.
	 * @param string $vergaderstukId The iBabs document/vergaderstuk ID.
	 * @param string|null $meetingId Specific vergadering to link to, or null for auto-select.
	 *
	 * @return array{success: bool, message: string, agendapuntId: string|null} Creation result.
	 *
	 * The inbound RISPollJob path (pollBesluiten) is live, but the outbound
	 * Zaak -> voorstel -> PDF -> iBabs-upload -> createAgendapunt pipeline that
	 * triggers this write is not yet built. This method is spec-required
	 * (REQ-RIS-003) and tested, so it is kept — not deleted and not fake-wired —
	 * pending that outbound pipeline, tracked as a follow-up issue.
	 *
	 * @spec                      openspec/changes/ibabs-notubiz-connector/tasks.md#task-3
	 * @orphaned-write-capability exclude Outbound-push entry point (REQ-RIS-003); its Zaak->iBabs pipeline is unbuilt.
	 */
	public function createAgendaItem(
		ObjectEntity $source,
		string $vergaderstukId,
		?string $meetingId = null,
	): array;

	/**
	 * Poll for besluiten from iBabs.
	 *
	 * Queries the iBabs API for besluiten related to previously pushed
	 * voorstellen and returns an array of besluit data with status mappings.
	 *
	 * @param ObjectEntity $source The iBabs source configuration.
	 * @param array $syncedItems List of sync records with iBabs agendapunt IDs to check.
	 *
	 * @return array<int, array{caseId: string, ibabsStatus: string, zaakStatus: string, besluitdatum: string|null}> Besluit records.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-4
	 */
	public function pollDecisions(ObjectEntity $source, array $syncedItems = []): array;

	/**
	 * Retrieve and store the besluitenlijst PDF from iBabs.
	 *
	 * Downloads the besluitenlijst for a concluded vergadering and stores it
	 * in Nextcloud Files under /RIS-besluiten/{year}/{datum}/.
	 *
	 * @param ObjectEntity $source The iBabs source configuration.
	 * @param string $meetingId The iBabs vergadering ID.
	 * @param string $meetingDate The vergadering date (YYYY-MM-DD).
	 * @param string $userId Nextcloud user ID under whose account to store.
	 *
	 * @return array{success: bool, message: string, filePath: string|null} Result with file path.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-5
	 */
	public function retrieveBesluitenlijst(
		ObjectEntity $source,
		string $meetingId,
		string $meetingDate,
		string $userId,
	): array;

	/**
	 * Map an iBabs besluit status to a Procest zaak status.
	 *
	 * @param string $ibabsStatus The iBabs besluit status.
	 *
	 * @return string The corresponding Procest zaak status.
	 *
	 * @spec openspec/changes/ibabs-notubiz-connector/tasks.md#task-4
	 */
	public function mapDecisionStatus(string $ibabsStatus): string;
}
