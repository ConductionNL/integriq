# Open Connector — Overheidsfunctionaliteiten

> Functiepagina voor Nederlandse overheidsorganisaties.
> Gebruik deze checklist om te toetsen aan uw Programma van Eisen.

**Product:** Open Connector
**Categorie:** Enterprise Service Bus (ESB) & API Gateway
**Licentie:** AGPL (vrije open source)
**Leverancier:** Conduction B.V.
**Platform:** Nextcloud (self-hosted / on-premise / cloud)

## Legenda

| Status | Betekenis |
|--------|-----------|
| Beschikbaar | Functionaliteit is beschikbaar in de huidige versie |
| Gepland | Functionaliteit staat op de roadmap |
| Via platform | Functionaliteit wordt geleverd door Nextcloud |
| Op aanvraag | Beschikbaar als maatwerk |
| N.v.t. | Niet van toepassing voor dit product |

---

## 1. Functionele eisen

### API Gateway & Service Bus

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-01 | API-aanroepen mappen en vertalen | Beschikbaar | REST-naar-REST, SOAP-naar-REST |
| F-02 | Databronnen synchroniseren | Beschikbaar | Geautomatiseerde bronsynchronisatie |
| F-03 | Cloud Events verzenden en ontvangen | Beschikbaar | Event-driven architectuur |
| F-04 | Geplande taken (cron-jobs) | Beschikbaar | Periodieke synchronisatie en verwerking |
| F-05 | Logbeheer en opschoning | Beschikbaar | Automatische log cleanup |

### Koppelingen & Integratie

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| F-06 | StUF-naar-REST vertaling | Beschikbaar | Legacy XML-standaard vertalen |
| F-07 | SOAP-naar-REST vertaling | Beschikbaar | Oude webservices ontsluiten |
| F-08 | Configureerbare endpoints | Beschikbaar | Admin-UI voor koppelingen |
| F-09 | Authenticatie-relay (OAuth, API keys, certificaten) | Beschikbaar | Doorvertaling van authenticatie |
| F-10 | Datavalidatie en -transformatie | Beschikbaar | Mapping en filtering van data |

---

## 2. Technische eisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| T-01 | On-premise / self-hosted | Beschikbaar | Nextcloud-app |
| T-02 | Open source | Beschikbaar | AGPL, GitHub |
| T-03 | RESTful API | Beschikbaar | API voor configuratie en monitoring |
| T-04 | Cron-gebaseerde taken | Beschikbaar | Background jobs via Nextcloud cron |
| T-05 | Database-onafhankelijkheid | Beschikbaar | PostgreSQL, MySQL, SQLite |
| T-06 | Containerisatie (Docker) | Beschikbaar | Docker Compose |
| T-07 | curl-gebaseerd (geen externe dependencies) | Beschikbaar | Alleen PHP curl vereist |

---

## 3. Beveiligingseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| B-01 | RBAC | Via platform | Nextcloud admin-rechten |
| B-02 | Audit trail / logging | Beschikbaar | Verwerking logs met opschoning |
| B-03 | BIO-compliance | Via platform | Nextcloud BIO |
| B-04 | 2FA | Via platform | Nextcloud 2FA |
| B-05 | SSO / SAML / LDAP | Via platform | Nextcloud SSO |
| B-06 | Versleuteling (rust + transit) | Via platform | Nextcloud encryption + TLS |
| B-07 | Certificaat-authenticatie naar externe systemen | Beschikbaar | PKI/mTLS ondersteuning |

---

## 4. Privacyeisen (AVG/GDPR)

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| P-01 | Geen permanente dataopslag van doorgevoerde gegevens | Beschikbaar | Connector verwerkt, slaat niet op |
| P-02 | Log-opschoning (configureerbaar) | Beschikbaar | Automatische verwijdering van oude logs |
| P-03 | Data minimalisatie | Beschikbaar | Alleen noodzakelijke velden doorgeven via mapping |

---

## 5. Toegankelijkheidseisen

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| A-01 | WCAG 2.1 AA (admin-UI) | Beschikbaar | Nextcloud-componenten |
| A-02 | Meertalig (NL/EN) | Beschikbaar | Volledige vertaling |

---

## 6. Integratiestandaarden

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| I-01 | Common Ground architectuur | Beschikbaar | Laag 3 (integratie) — ESB-functionaliteit |
| I-02 | StUF-koppelvlak | Beschikbaar | Vertaling van StUF XML naar REST |
| I-03 | SOAP-koppelvlak | Beschikbaar | Vertaling van SOAP naar REST |
| I-04 | REST API | Beschikbaar | Standaard REST-koppelingen |
| I-05 | Cloud Events | Beschikbaar | Event-driven integratie standaard |
| I-06 | OAuth 2.0 / OpenID Connect | Beschikbaar | Moderne authenticatie-relay |
| I-07 | API-key authenticatie | Beschikbaar | Eenvoudige API-toegang |
| I-08 | Certificaat-authenticatie (mTLS) | Beschikbaar | PKIoverheid-certificaten |

---

## 7. Beheer en onderhoud

| # | Eis | Status | Toelichting |
|---|-----|--------|-------------|
| BO-01 | Nextcloud App Store | Beschikbaar | Installatie via App Store |
| BO-02 | Automatische updates | Beschikbaar | Via Nextcloud app-updater |
| BO-03 | Beheerderspaneel | Beschikbaar | Nextcloud admin settings |
| BO-04 | Monitoring | Beschikbaar | Log-inzicht en foutmeldingen |
| BO-05 | Open source community | Beschikbaar | GitHub Issues |
| BO-06 | Professionele ondersteuning (SLA) | Op aanvraag | Via Conduction B.V. |

---

## 8. Onderscheidende kenmerken

| Kenmerk | Toelichting |
|---------|-------------|
| **StUF-vertaling** | Enige Nextcloud-app die StUF XML kan vertalen naar REST |
| **Nextcloud-native ESB** | Geen apart integratie-platform nodig |
| **Lichtgewicht** | Alleen PHP + curl, geen Java/Spring |
| **Common Ground laag 3** | Past in de Common Ground integratie-architectuur |
| **Event-driven** | Cloud Events voor real-time integratie |
| **Zero-footprint** | Connector verwerkt data door, slaat niets permanent op |
