# Tasks: source-requested-event

- [x] `lib/Event/SourceRequestedEvent.php`: provenance, optional timeout, result slot and refusal.
- [x] `lib/EventListener/SourceRequestedListener.php`: validate the base URL, apply the
      remote-host rule, find by derived slug or create through `ConnectionStore`, log who asked.
- [x] Register the listener in `Application::register()`.
- [x] `FlowTemplate`: `{{ @item }}` resolves to the whole item record.
- [x] Unit tests: create, idempotent find, refusals, remote-host refusal, store failure, foreign
      event, registration; whole-item template; source-call body posting the whole item.
