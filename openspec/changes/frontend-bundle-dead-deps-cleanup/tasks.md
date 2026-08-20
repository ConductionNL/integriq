## 1. Confirm zero usage before removing anything

- [ ] 1.1 Re-run `grep -rln "apexcharts" src --include=*.js --include=*.vue`, `grep -rln "fortawesome" src --include=*.js --include=*.vue`, and `grep -rln "vue-apexcharts" src --include=*.js --include=*.vue` at HEAD and confirm all three return no matches (guards against a race with another PR that starts using one of them)

## 2. Remove dead dependencies

- [ ] 2.1 Remove `apexcharts` and `vue-apexcharts` from `package.json` `dependencies`
- [ ] 2.2 Remove `@fortawesome/fontawesome-svg-core` and `@fortawesome/free-solid-svg-icons` from `package.json` `dependencies`
- [ ] 2.3 Run `npm install` to regenerate `package-lock.json`; confirm no other declared dependency required any of the four removed packages as a peer/transitive requirement

## 3. Stop wholesale lodash import

- [ ] 3.1 In `src/modals/Endpoint/EditEndpoint.vue`, replace `import _ from 'lodash'` (line 122) with either an inline `String(value)` at both call sites or `import toString from 'lodash/toString.js'`
- [ ] 3.2 Update the two call sites (`_.toString(register.id)` at line 311, `_.toString(schema.id)` at line 414) to match the chosen replacement
- [ ] 3.3 Confirm no other symbol from the `_` namespace is used in this file (`grep -n "_\." src/modals/Endpoint/EditEndpoint.vue`)

## 4. Verify

- [ ] 4.1 `npm run build` completes with no missing-module errors
- [ ] 4.2 `npm run lint` passes on the touched file
- [ ] 4.3 `npm run test:unit` / relevant vitest suite for `EditEndpoint.vue` (if one exists) passes
- [ ] 4.4 Manually open the Endpoint edit modal in a running instance and confirm register/schema selection still resolves correctly (the `toString` call sites are used to match select option ids)
