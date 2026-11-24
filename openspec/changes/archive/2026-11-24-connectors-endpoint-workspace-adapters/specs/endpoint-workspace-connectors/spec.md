# endpoint-workspace-connectors Specification

## ADDED Requirements

### Requirement: An administrator sees the sessions or devices of a connected workspace manager (REQ-EWC-008)

Integriq MUST ship adapters for Microsoft Intune, Jamf Pro and Citrix DaaS beside the Azure Virtual Desktop adapter. Each adapter MUST call its vendor through the credential broker, never with a secret held in integriq. A source of one of these types MUST show a tab that lists what the vendor reports: user sessions for a virtual desktop, devices for a device manager. Each entry MUST name the Nextcloud user it maps to through REQ-EWC-003, or say that no user matched.

#### Scenario: an administrator connects Intune and sees the devices
- GIVEN an Intune source with a brokered credential
- WHEN the administrator opens the source's "Apparaten" tab
- THEN the devices Intune reports are listed with their owner and last check-in
- AND a device whose owner maps to a Nextcloud user shows that user

#### Scenario: a Citrix session without a matching user says so
- GIVEN a Citrix DaaS source with one session for an unknown account
- WHEN the administrator opens the source's "Sessies" tab
- THEN that session shows "Geen gebruiker gevonden" rather than a guessed user

#### Scenario: a source without a credential lists nothing
- GIVEN a Jamf Pro source without a brokered credential
- WHEN the tab opens
- THEN it explains that the credential is missing and calls nothing
