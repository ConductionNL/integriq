# User API Setup and Configuration

This guide explains how to set up and configure the secure User API endpoints in your OpenConnector application.

## Overview

The User API provides three secure endpoints:
- `GET /api/user/me` - Get current user information
- `PUT /api/user/me` - Update current user information  
- `POST /api/user/login` - Secure user authentication

All endpoints include comprehensive security measures against XSS attacks and brute force attempts.

## Prerequisites

- NextCloud 25+ or compatible environment
- PHP 8.1+
- Redis or Memcached for distributed caching (recommended for rate limiting)
- Write access to the OpenConnector app directory

## Installation Steps

### 1. File Structure

The secure User API implementation consists of these files:

```
lib/
├── Controller/
│   └── UserController.php          # Enhanced user controller with security
├── Service/
│   └── SecurityService.php         # New security service for protection
└── ...

appinfo/
└── routes.php                      # Updated with user routes

website/docs/
├── user-api.md                     # API documentation
├── user-api-setup.md              # This setup guide
└── security-best-practices.md     # Security documentation
```

### 2. Route Configuration

The user routes are automatically configured in `appinfo/routes.php`:

```php
// User endpoints
['name' => 'user#me', 'url' => '/api/user/me', 'verb' => 'GET'],
['name' => 'user#updateMe', 'url' => '/api/user/me', 'verb' => 'PUT'],
['name' => 'user#login', 'url' => '/api/user/login', 'verb' => 'POST'],
```

### 3. Dependencies

The implementation uses these NextCloud services:
- `IUserManager` - User management operations
- `IUserSession` - Session management
- `ICacheFactory` - Distributed caching for rate limiting
- `LoggerInterface` - Security event logging
- `AuthorizationService` - Existing authorization service

### 4. Cache Configuration

For optimal security, configure a distributed cache backend:

**Redis Configuration (recommended):**
```php
// config/config.php
'memcache.distributed' => '\OC\Memcache\Redis',
'redis' => [
    'host' => 'localhost',
    'port' => 6379,
    'timeout' => 0.0,
    'password' => '', // Optional
],
```

**Memcached Configuration:**
```php
// config/config.php
'memcache.distributed' => '\OC\Memcache\Memcached',
'memcached_servers' => [
    ['localhost', 11211],
],
```

## Configuration Options

### Security Settings

You can customize security parameters by modifying constants in `lib/Service/SecurityService.php`:

```php
class SecurityService
{
    // Rate limiting configuration
    private const RATE_LIMIT_ATTEMPTS = 5;      // Max attempts per window
    private const RATE_LIMIT_WINDOW = 900;      // 15 minutes in seconds
    private const LOCKOUT_DURATION = 3600;      // 1 hour in seconds
    private const PROGRESSIVE_DELAY_BASE = 2;   // Base delay in seconds
    private const MAX_PROGRESSIVE_DELAY = 60;   // Maximum delay in seconds
}
```

**Configuration Parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `RATE_LIMIT_ATTEMPTS` | 5 | Maximum failed attempts before lockout |
| `RATE_LIMIT_WINDOW` | 900 | Time window for rate limiting (seconds) |
| `LOCKOUT_DURATION` | 3600 | Account/IP lockout duration (seconds) |
| `PROGRESSIVE_DELAY_BASE` | 2 | Base delay for progressive backoff |
| `MAX_PROGRESSIVE_DELAY` | 60 | Maximum delay cap (seconds) |

### Security Headers

Customize security headers in the `addSecurityHeaders()` method:

```php
public function addSecurityHeaders(JSONResponse $response): JSONResponse
{
    // Prevent clickjacking
    $response->addHeader('X-Frame-Options', 'DENY');
    
    // Prevent MIME type sniffing
    $response->addHeader('X-Content-Type-Options', 'nosniff');
    
    // Enable XSS protection
    $response->addHeader('X-XSS-Protection', '1; mode=block');
    
    // Referrer policy
    $response->addHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    
    // Content Security Policy
    $response->addHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none';");
    
    // Prevent caching of sensitive responses
    $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    $response->addHeader('Pragma', 'no-cache');
    $response->addHeader('Expires', '0');

    return $response;
}
```

## Testing the Setup

### 1. Basic Functionality Test

Test that the endpoints are working:

```bash
# Test user info endpoint (requires authentication)
curl -X GET "https://your-nextcloud.com/apps/openconnector/api/user/me" \
  -H "Content-Type: application/json" \
  -b "cookies.txt"

# Test login endpoint
curl -X POST "https://your-nextcloud.com/apps/openconnector/api/user/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"testuser","password":"testpassword"}' \
  -c "cookies.txt"
```

### 2. Security Features Test

Test rate limiting protection:

```bash
# Make multiple failed login attempts to trigger rate limiting
for i in {1..6}; do
  curl -X POST "https://your-nextcloud.com/apps/openconnector/api/user/login" \
    -H "Content-Type: application/json" \
    -d '{"username":"testuser","password":"wrongpassword"}'
  echo "Attempt $i completed"
done
```

Expected behavior:
- First 5 attempts: Return 401 with 'Invalid username or password'
- 6th attempt: Return 429 with rate limiting message and delay

### 3. XSS Protection Test

Test input sanitization:

```bash
# Test XSS attempt in username
curl -X POST "https://your-nextcloud.com/apps/openconnector/api/user/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"<script>alert(\"xss\")</script>","password":"test"}'
```

Expected behavior:
- Input should be sanitized
- No script execution
- Proper error response with security headers

## Monitoring and Logging

### Security Event Logs

Security events are logged to the NextCloud log with these event types:

- `failed_login_attempt` - Invalid credentials provided
- `rate_limit_exceeded` - Too many attempts from user/IP
- `user_locked_out` - User account temporarily locked
- `ip_locked_out` - IP address temporarily blocked
- `successful_login` - Authentication succeeded
- `login_attempt_during_lockout` - Attempt during active lockout

### Log Format

```json
{
  "event": "failed_login_attempt",
  "username": "sanitized_username",
  "ip_address": "192.168.1.100",
  "reason": "invalid_credentials",
  "user_attempts": 3,
  "ip_attempts": 2,
  "timestamp": "2024-01-15 14:30:22"
}
```

### Monitoring Commands

Search logs for security events:

```bash
# Find failed login attempts
grep "failed_login_attempt" /var/log/nextcloud.log

# Find rate limiting events
grep "rate_limit_exceeded" /var/log/nextcloud.log

# Find lockout events
grep "locked_out" /var/log/nextcloud.log

# Count failed attempts by IP
grep "failed_login_attempt" /var/log/nextcloud.log | jq -r '.ip_address' | sort | uniq -c | sort -nr
```

## Performance Considerations

### Cache Performance

- **Redis**: Recommended for production environments
- **Memcached**: Good alternative for distributed setups
- **File Cache**: Not recommended for rate limiting (performance issues)

### Rate Limiting Impact

- Cache operations are very fast (< 1ms typically)
- Progressive delays only affect failed attempts
- Successful logins clear rate limiting data immediately
- IP-based tracking doesn't affect legitimate users from same network

### Memory Usage

Estimated cache memory usage per user:
- Rate limiting data: ~200 bytes per user
- Progressive delay data: ~100 bytes per user/IP combination
- Lockout data: ~50 bytes per locked user/IP

For 10,000 active users: ~3.5 MB cache memory

## Troubleshooting

### Common Issues

**1. Rate limiting not working:**
- Check cache configuration
- Verify Redis/Memcached is running
- Check cache connectivity

```bash
# Test cache connectivity
redis-cli ping
# or
echo "stats" | nc localhost 11211
```

**2. Security headers not appearing:**
- Verify SecurityService is properly injected
- Check for proxy/CDN overriding headers
- Test directly against NextCloud (bypass proxy)

**3. Progressive delays not working:**
- Check system time synchronization
- Verify cache expiration settings
- Test with different user/IP combinations

**4. Logs not appearing:**
- Check NextCloud log level configuration
- Verify log file permissions
- Check if logging is enabled

### Debug Mode

Enable debug logging by modifying the SecurityService:

```php
private function logSecurityEvent(string $event, array $context = []): void
{
    // Add debug information
    $context['debug'] = true;
    $context['cache_key_info'] = [
        'user_attempts_key' => self::CACHE_PREFIX_LOGIN_ATTEMPTS . $context['username'],
        'ip_attempts_key' => self::CACHE_PREFIX_IP_ATTEMPTS . $context['ip_address']
    ];
    
    // ... existing logging code
}
```

## Security Recommendations

### Production Deployment

1. **Use HTTPS Only**: Ensure all API endpoints are served over HTTPS
2. **Configure WAF**: Use a Web Application Firewall for additional protection
3. **Monitor Logs**: Set up log monitoring and alerting
4. **Regular Updates**: Keep NextCloud and dependencies updated
5. **Backup Configuration**: Backup security configurations

### Additional Security Layers

1. **Two-Factor Authentication**: Consider implementing 2FA
2. **IP Whitelisting**: Restrict API access to known IP ranges
3. **API Rate Limiting**: Implement global API rate limiting
4. **Request Signing**: Consider request signing for sensitive operations

## Support and Maintenance

### Regular Maintenance Tasks

1. **Log Rotation**: Ensure security logs are rotated regularly
2. **Cache Monitoring**: Monitor cache performance and memory usage
3. **Security Audits**: Perform regular security assessments
4. **Update Checks**: Check for security updates regularly

### Getting Help

- Check the [Security Best Practices](security-best-practices.md) documentation
- Review the [User API](user-api.md) documentation
- Check NextCloud logs for error details
- Verify cache configuration and connectivity

For additional support, refer to the OpenConnector documentation or contact the development team. 