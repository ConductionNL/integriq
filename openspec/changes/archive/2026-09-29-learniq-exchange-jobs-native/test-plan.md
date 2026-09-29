# Test plan: learniq-exchange-jobs-native

Unit tests only; the adapters' live bindings stay dormant (D9) and every service is mocked at
its public method.

| Test class | Covers |
|---|---|
| `tests/Unit/Settings/LearniqExchangeJobsFragmentTest.php` | REQ-001 enum of 14 targets, REQ-007 the 23 slugs plus five vocabulary rows, versions bumped, no new open schema |
| `tests/Unit/Service/Exchange/ExchangeTargetCatalogueTest.php` | targets, directions, handled flags |
| `tests/Unit/Service/Exchange/ExchangeGateClientTest.php` | REQ-003 allow, refuse, app absent, unanswered, listener throws, owner missing |
| `tests/Unit/Service/Exchange/ExchangeJobServiceTest.php` | REQ-001 create and refusals, REQ-002 history, idempotent legacy id, mapping upsert |
| `tests/Unit/Service/Exchange/ExchangeJobRunnerTest.php` | REQ-003 to REQ-006 and REQ-009: no-handler before the gate, mapping-missing, refused, mapped records, partial, resubmission scoping and reopen, concluded event |
| `tests/Unit/Service/Exchange/ExchangeTargetDispatcherTest.php` | REQ-005 every handled target, kenmerk, parameter precedence, translation vs send failures, source-missing |
| `tests/Unit/Service/Exchange/ExchangeRejectionServiceTest.php` | REQ-006 record, resubmit, reopen, waive |
| `tests/Unit/Service/Exchange/ExchangeErrorCodeCatalogueTest.php` | REQ-007 label resolution and fallback |
| `tests/Unit/Service/Exchange/ExchangeReadModelTest.php` | REQ-008 owner filtering, bounds, enrichment |
| `tests/Unit/Controller/ExchangeControllerTest.php` | REQ-008 403 without action, 400 without ownerApp, 404 foreign job, waive reason |
| `tests/Unit/EventListener/ExchangeJobRequestedListenerTest.php` | listener never throws into the sender |
| `tests/Unit/Service/SyncItemDeadLetterServiceTest.php` (extended) | replay of an exchange rejection resubmits |

Events are constructed as the real classes, never as doubles, so a wrong accessor fails the test.
