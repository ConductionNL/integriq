# developer-overheid-sources Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:

- [sources-developer-overheid](../../changes/sources-developer-overheid/)

## Purpose

An administrator connects the developer.overheid.nl OSS-register to integriq by storing one API key in OpenRegister's credential broker (ADR-064), and flows can read the register page by page and fetch public `publiccode.yml` files from gitlab.com next to github.com. OpenCatalogi's `publiccode-developer-overheid-harvest` is the first consumer.

## Requirements

The requirements are defined in the open change and land here when it is archived: [sources-developer-overheid delta spec](../../changes/sources-developer-overheid/specs/developer-overheid-sources/spec.md) (REQ-DOS-001 to REQ-DOS-005).

## Non-Functional Requirements

- **Performance:** A register listing of up to 100 repositories costs one request.
- **Accessibility:** n/a — no user interface beyond the existing connector catalogue.
- **Internationalization:** Dutch and English MUST be supported for any new user-facing string (ADR-007).

## Acceptance Criteria

- [ ] `developer-overheid-oss` and `gitlab-raw` are seeded and catalogued.
- [ ] The key is sent as `X-Api-Key` from the broker and never stored or logged.
- [ ] A one-page listing is one request.
- [ ] A mock of the register is available for live tests.

## Notes

- If the register refuses the API key on its list endpoint, the source moves to a `generic-oauth2` credential under the same slug.
