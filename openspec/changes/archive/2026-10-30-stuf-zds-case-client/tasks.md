# Tasks: stuf-zds-case-client

- [x] `StufZdsMessageBuilder`: creeerZaak (Lk01), actualiseerZaak (Lk02), geefZaakdetails (Lv01),
      genereerZaakIdentificatie (Di02), vrij bericht (Du01), WS-Security header, referentienummer.
- [x] `StufZknClient::transmit()` and the `wsse` authentication mode.
- [x] `StufZdsResponseReader`: bevestiging, Du02, La01, fout classification, DOCTYPE refused.
- [x] `StufZdsCircuitBreaker`: four failures open the circuit for five minutes.
- [x] `StufZdsCredentialResolver`: UsernameToken from the encrypted source configuration.
- [x] `StufZdsMessageLog` and the `stuf_message` schema 1.1.0: operation, source, correlation,
      attempts, retrying status, envelope kept only while retrying.
- [x] `StufZdsCaseClient` and `StufZdsRetryJob`.
- [x] `StufZknSyncService::retryFailed()` leaves ZDS case rows to their own retry.
- [x] `StufZdsCaseRequestedEvent`, `StufZdsCaseRequestedListener`, `StufZdsCaseConcludedEvent`,
      registered in `Application`.
- [x] Unit tests, with every audit row validated against the real `stuf_message` schema.
- [x] `StufZdsConfirmationReceiver`, `StufZdsConfirmationController` (`/api/stuf-zkn/confirmations`),
      `StufZdsConfirmationReceivedEvent` and its listener (dossiq's `/api/stuf/inbound` forwards
      through it for one release); `stuf_message.confirmation` and `confirmedAt`.
