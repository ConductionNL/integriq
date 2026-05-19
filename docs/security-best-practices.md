# Security Best Practices for User API

The OpenConnector User API implements comprehensive security measures to protect against various attack vectors including Cross-Site Scripting (XSS) attacks and brute force login attempts.

## Overview

Our security implementation includes multiple layers of protection:

1. **Input Validation & Sanitization**
2. **Rate Limiting & Brute Force Protection**
3. **Security Headers**
4. **Session Management**
5. **Logging & Monitoring**

## Protection Against Cross-Site Scripting (XSS)

### Input Sanitization

All user inputs are automatically sanitized through our `SecurityService`:

```php
// Automatic sanitization of all inputs
$sanitizedData = $this->securityService->sanitizeInput($data);
```

**Features:**
- HTML entity encoding to prevent script injection
- Removal of dangerous patterns (script tags, event handlers)
- Null byte filtering
- Length limiting to prevent DoS attacks

### Security Headers

Every API response includes security headers:

- `X-Frame-Options: DENY` - Prevents clickjacking
- `X-Content-Type-Options: nosniff` - Prevents MIME sniffing
- `X-XSS-Protection: 1; mode=block` - Browser XSS protection
- `Content-Security-Policy` - Restricts resource loading
- `Referrer-Policy` - Controls referrer information

### Content Validation

Login credentials undergo strict validation:

```php
// Username validation
- Minimum 2 characters
- No dangerous characters (<, >, ', ', /, \)
- Maximum length limits
- Character encoding validation

// Password validation  
- Maximum length limits (prevents DoS)
- No HTML encoding (preserves actual password)
```

## Protection Against Brute Force Attacks

### Multi-Layer Rate Limiting

The system implements sophisticated rate limiting:

#### Per-User Rate Limiting
- **Limit:** 5 failed attempts per 15-minute window
- **Action:** Progressive delays then account lockout
- **Duration:** 1 hour lockout after threshold

#### Per-IP Rate Limiting
- **Limit:** 5 failed attempts per 15-minute window  
- **Action:** IP address blocking
- **Duration:** 1 hour lockout after threshold

#### Progressive Delays
- **Base Delay:** 2 seconds
- **Escalation:** Exponential backoff (2s → 4s → 8s → 16s → 32s → 60s max)
- **Reset:** Successful login clears delays

### Account Lockout Protection

```php
// Automatic lockout triggers
if ($userAttempts >= 5) {
    // Lock account for 1 hour
    $lockoutUntil = time() + 3600;
    $this->cache->set($userLockoutKey, $lockoutUntil, 3600);
}
```

### IP-based Protection

- Tracks failed attempts per IP address
- Blocks suspicious IP addresses automatically
- Considers proxy headers (X-Forwarded-For, etc.)
- Validates IP formats to prevent spoofing

## Security Event Logging

All security events are logged with contextual information:

### Logged Events
- `failed_login_attempt` - Invalid credentials
- `rate_limit_exceeded` - Too many attempts
- `user_locked_out` - Account temporarily disabled
- `ip_locked_out` - IP address blocked
- `successful_login` - Authentication succeeded
- `login_attempt_during_lockout` - Attempt during lockout period

### Log Information
```php
{
    'event': 'failed_login_attempt',
    'username': 'sanitized_username',
    'ip_address': '192.168.1.1',
    'reason': 'invalid_credentials',
    'user_attempts': 3,
    'ip_attempts': 2,
    'timestamp': '2024-01-15 14:30:22'
}
```

## Implementation Details

### SecurityService Features

The `SecurityService` class provides:

```php
// Rate limiting check
$rateLimitCheck = $this->securityService->checkLoginRateLimit($username, $clientIp);

// Input sanitization
$sanitizedInput = $this->securityService->sanitizeInput($input);

// Credential validation
$validation = $this->securityService->validateLoginCredentials($credentials);

// Security headers
$response = $this->securityService->addSecurityHeaders($response);

// IP address detection
$clientIp = $this->securityService->getClientIpAddress($request);
```

### Cache-based Storage

Security data is stored using NextCloud's distributed cache:

- **Storage:** Redis/Memcached (configurable)
- **Persistence:** Automatic expiration
- **Scalability:** Works across multiple servers
- **Performance:** Fast read/write operations

## API Response Codes

### Security-related HTTP Status Codes

- `400 Bad Request` - Invalid input or missing credentials
- `401 Unauthorized` - Authentication failed
- `429 Too Many Requests` - Rate limit exceeded
- `403 Forbidden` - Account disabled or insufficient permissions

### Response Examples

**Rate Limited:**
```json
{
    'error': 'Too many login attempts. Please wait before trying again.',
    'retry_after': 8,
    'lockout_until': null
}
```

**Account Locked:**
```json
{
    'error': 'Account temporarily locked due to too many failed login attempts',
    'retry_after': null,
    'lockout_until': 1705334622
}
```

**Successful Login:**
```json
{
    'message': 'Login successful',
    'user': { /* user data */ },
    'session_created': true
}
```

## Configuration Options

### Rate Limiting Settings

You can adjust these constants in `SecurityService.php`:

```php
private const RATE_LIMIT_ATTEMPTS = 5;      // Max attempts per window
private const RATE_LIMIT_WINDOW = 900;      // 15 minutes in seconds
private const LOCKOUT_DURATION = 3600;      // 1 hour in seconds
private const PROGRESSIVE_DELAY_BASE = 2;   // Base delay in seconds
private const MAX_PROGRESSIVE_DELAY = 60;   // Maximum delay in seconds
```

### Security Headers

Customize security headers in the `addSecurityHeaders()` method:

```php
// Content Security Policy
$response->addHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none';");

// Cache Control
$response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
```

## Best Practices for Implementation

### 1. Always Use Security Service

```php
// ✅ Correct - Use SecurityService
$response = $this->securityService->addSecurityHeaders($response);

// ❌ Incorrect - Direct response
return new JSONResponse($data);
```

### 2. Sanitize All Inputs

```php
// ✅ Correct - Sanitize inputs
$sanitizedData = $this->securityService->sanitizeInput($data);

// ❌ Incorrect - Direct usage
$username = $_POST['username']; // Never do this
```

### 3. Validate Before Processing

```php
// ✅ Correct - Validate first
$validation = $this->securityService->validateLoginCredentials($credentials);
if (!$validation['valid']) {
    return error_response($validation['error']);
}

// ❌ Incorrect - Process without validation
$user = $this->userManager->checkPassword($username, $password);
```

### 4. Log Security Events

All authentication attempts should be logged for monitoring and analysis.

### 5. Use Generic Error Messages

Avoid revealing system information:

```php
// ✅ Correct - Generic message
return new JSONResponse(['error' => 'Invalid username or password']);

// ❌ Incorrect - Reveals information
return new JSONResponse(['error' => 'User john.doe does not exist']);
```

## Monitoring and Alerting

### Key Metrics to Monitor

1. **Failed Login Rate** - Sudden spikes may indicate attacks
2. **Lockout Events** - High frequency suggests ongoing attacks  
3. **IP Blocking** - Geographic patterns may reveal bot networks
4. **Response Times** - Delays may indicate DoS attempts

### Log Analysis

Search for security events in logs:

```bash
# Find failed login attempts
grep 'failed_login_attempt' /var/log/nextcloud.log

# Find rate limit exceeded events
grep 'rate_limit_exceeded' /var/log/nextcloud.log

# Find lockout events
grep 'locked_out' /var/log/nextcloud.log
```

## Additional Security Recommendations

### 1. Use HTTPS Only
Ensure all API endpoints are served over HTTPS to prevent credential interception.

### 2. Regular Security Updates
Keep NextCloud and all dependencies updated to the latest versions.

### 3. Strong Password Policies
Implement password complexity requirements at the application level.

### 4. Two-Factor Authentication
Consider implementing 2FA for additional security layers.

### 5. Regular Security Audits
Perform regular penetration testing and security audits.

### 6. Web Application Firewall
Consider using a WAF for additional protection against common attacks.

## Conclusion

The OpenConnector User API implements industry-standard security practices to protect against both XSS attacks and brute force attempts. The multi-layered approach ensures comprehensive protection while maintaining usability for legitimate users.

For any security concerns or questions, please refer to the code documentation or contact the development team. 