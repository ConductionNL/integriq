# Retrofit — software-catalogus-events

Describes observed behavior of 23 methods under `software-catalogus-events` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/EventListener/SoftwareCatalogEventListener.php::handleObjectCreated()`
- `lib/EventListener/SoftwareCatalogEventListener.php::handleObjectUpdated()`
- `lib/EventListener/SoftwareCatalogEventListener.php::handleObjectDeleted()`
- `lib/Service/SoftwareCatalogueService.php::extendModel()`
- `lib/Service/SoftwareCatalogueService.php::extendView()`
- `lib/Service/SoftwareCatalogueService.php::extendNode()`
- `lib/Service/SoftwareCatalogueService.php::extendConnection()`
- `lib/Service/SoftwareCatalogueService.php::findElementForNode()`
- `lib/Service/SoftwareCatalogueService.php::findRelationForConnection()`
- `lib/Service/SoftwareCatalogueService.php::findRelationsForElement()`
- `lib/Service/SoftwareCatalogueService.php::handleNewOrganization()`
- `lib/Service/SoftwareCatalogueService.php::handleNewContact()`
- `lib/Service/SoftwareCatalogueService.php::handleContactUpdate()`
- `lib/Service/SoftwareCatalogueService.php::handleContactDeletion()`
- `lib/Service/SoftwareCatalogueService.php::sendWelcomeEmail()` (stub)
- `lib/Service/SoftwareCatalogueService.php::sendVngNotification()` (stub)
- `lib/Service/SoftwareCatalogueService.php::createSecurityGroup()` (stub)
- `lib/Service/SoftwareCatalogueService.php::createOrEnableUser()` (stub)
- `lib/Service/SoftwareCatalogueService.php::updateUser()` (stub)
- `lib/Service/SoftwareCatalogueService.php::disableUser()` (stub)
- `lib/Service/SoftwareCatalogueService.php::sendContactWelcomeEmail()` (stub)
- `lib/Service/SoftwareCatalogueService.php::sendContactUpdateEmail()` (stub)
- `lib/Service/SoftwareCatalogueService.php::sendContactDeletionEmail()` (stub)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Cap at 5 REQs by splitting the cluster along its two concerns: ArchiMate model graph extension (REQ-001..003), lifecycle event handling (REQ-004), provisioning stubs (REQ-005). The stubs share a "log-only no-op" contract that's worth a single REQ — their orchestrators (REQ-004) call them in fixed order.
- design.md flags the **stub-scan finding**: 9 of 23 methods are `// TODO: Implement` bodies whose only behaviour is `LoggerInterface::info`. Wired through the EventListener, every organisation / contact created in the SC produces a log line but NO welcome email, NO VNG notification, NO security group, NO NC user. The pipeline LOOKS like it's running.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
