## 1. Confirm zero usage before removing anything

- [x] 1.1 Re-run `grep -rln "apexcharts" src --include=*.js --include=*.vue`, `grep -rln "fortawesome" src --include=*.js --include=*.vue`, and `grep -rln "vue-apexcharts" src --include=*.js --include=*.vue` at HEAD and confirm all three return no matches (guards against a race with another PR that starts using one of them) (Re-run at HEAD 29 Sep 2026: all three greps return nothing.)

## 2. Remove dead dependencies

- [x] 2.1 Remove `apexcharts` and `vue-apexcharts` from `package.json` `dependencies` (Already gone at HEAD: `apexcharts`/`vue-apexcharts` left `dependencies` in the Vue 3 migration (#1063); only an `overrides` range remains for the transitive copy (#1108 licence pin).)
- [x] 2.2 Remove `@fortawesome/fontawesome-svg-core` and `@fortawesome/free-solid-svg-icons` from `package.json` `dependencies` (Already gone at HEAD: removed in #1171 (b28a5b8a).)
- [x] 2.3 Run `npm install` to regenerate `package-lock.json`; confirm no other declared dependency required any of the four removed packages as a peer/transitive requirement (Nothing to regenerate: no package.json change in this PR.)

## 3. Stop wholesale lodash import

- [x] 3.1 In `src/modals/Endpoint/EditEndpoint.vue`, replace `import _ from 'lodash'` (line 122) with either an inline `String(value)` at both call sites or `import toString from 'lodash/toString.js'` (Already done at HEAD: no lodash import in EditEndpoint.vue (4cc0d2a9).)
- [x] 3.2 Update the two call sites (`_.toString(register.id)` at line 311, `_.toString(schema.id)` at line 414) to match the chosen replacement (Already done: the call sites read `String(register.id)` (:222) and `String(schema.id)` (:342).)
- [x] 3.3 Confirm no other symbol from the `_` namespace is used in this file (`grep -n "_\." src/modals/Endpoint/EditEndpoint.vue`) (Confirmed: no `_.` use left in the file.)

## 4. Verify

- [x] 4.1 `npm run build` completes with no missing-module errors (`npm run build` exit 0 at the stack head (logs/npm-stackhead.log).)
- [x] 4.2 `npm run lint` passes on the touched file (`npm run lint` exit 0 at the stack head.)
- [x] 4.3 `npm run test:unit` / relevant vitest suite for `EditEndpoint.vue` (if one exists) passes (vitest 427/427 at the stack head (no EditEndpoint suite exists).)
- [ ] 4.4 Manually open the Endpoint edit modal in a running instance and confirm register/schema selection still resolves correctly (the `toString` call sites are used to match select option ids) (Not run: no instance this session. The change at HEAD is the one the Vue 3 migration made and shipped.)
