---
kind: spec
---

# Retrofit — user-management-and-login

Describes observed behavior of 31 methods under `user-management-and-login` as 5 new REQs.
Code already exists — this change retroactively specifies it. New capability
`user-management-and-login`.

## Affected code units

- lib/Controller/UserController.php::me()
- lib/Controller/UserController.php::updateMe()
- lib/Controller/UserController.php::login()
- lib/Controller/UserController.php::logout()
- lib/Controller/UserController.php::preflightedCorsMe()
- lib/Controller/UserController.php::preflightedCorsLogin()
- lib/Controller/UserController.php::buildCorsPreflightResponse()
- lib/Controller/UserController.php::addCorsHeaders()
- lib/Controller/UserController.php::convertToBytes()
- lib/Service/UserService.php::getCurrentUser()
- lib/Service/UserService.php::buildUserDataArray()
- lib/Service/UserService.php::updateUserProperties()
- lib/Service/UserService.php::getCustomNameFields()
- lib/Service/UserService.php::setCustomNameFields()
- lib/Service/UserService.php::buildQuotaInformation()
- lib/Service/UserService.php::getUsedSpaceMemorySafe()
- lib/Service/UserService.php::getLanguageAndLocale()
- lib/Service/UserService.php::getAdditionalProfileInfo()
- lib/Service/UserService.php::getAccountManagerPropertiesSelectively()
- lib/Service/UserService.php::updateStandardUserProperties()
- lib/Service/UserService.php::updateProfileProperties()
- lib/Service/UserService.php::getDefaultPropertyScope()
- lib/Service/SecurityService.php::checkLoginRateLimit()
- lib/Service/SecurityService.php::recordFailedLoginAttempt()
- lib/Service/SecurityService.php::recordSuccessfulLogin()
- lib/Service/SecurityService.php::sanitizeInput()
- lib/Service/SecurityService.php::validateLoginCredentials()
- lib/Service/SecurityService.php::addSecurityHeaders()
- lib/Service/SecurityService.php::getClientIpAddress()
- lib/Service/SecurityService.php::sanitizeForCacheKey()
- lib/Service/SecurityService.php::logSecurityEvent()

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior: reflected-Origin CORS with
  `Allow-Credentials: true`; `me()` inline Basic auth vs route auth posture;
  `getUsedSpaceMemorySafe()` overwrite ordering; `succesful_login` typo

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
