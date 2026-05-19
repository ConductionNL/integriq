# User API Quick Reference

Quick reference guide for developers using the OpenConnector User API with security features.

## Endpoints Overview

| Method | Endpoint | Purpose | Authentication | Security Features |
|--------|----------|---------|----------------|-------------------|
| `GET` | `/api/user/me` | Get current user info + groups | Required | ✅ XSS protection, Security headers |
| `PUT` | `/api/user/me` | Update user info | Required | ✅ Input sanitization, XSS protection |
| `POST` | `/api/user/login` | User authentication | None (creates auth) | ✅ Rate limiting, Brute force protection, XSS protection |

## Quick Start Examples

### JavaScript/Fetch

```javascript
// Login user
const loginResponse = await fetch('/api/user/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    username: 'john.doe',
    password: 'securepassword'
  })
});

if (loginResponse.status === 429) {
  const rateLimitData = await loginResponse.json();
  console.log('Rate limited:', rateLimitData.retry_after, 'seconds');
} else if (loginResponse.ok) {
  const userData = await loginResponse.json();
  console.log('Login successful:', userData.user);
}

// Get current user
const userResponse = await fetch('/api/user/me');
const currentUser = await userResponse.json();

// Update user
const updateResponse = await fetch('/api/user/me', {
  method: 'PUT',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    displayName: 'New Display Name',
    email: 'newemail@example.com'
  })
});
```

### PHP/cURL

```php
// Login user
$loginData = [
    'username' => 'john.doe',
    'password' => 'securepassword'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://your-nextcloud.com/apps/openconnector/api/user/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt'); // Save session cookies

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($httpCode === 429) {
    $rateLimitData = json_decode($response, true);
    echo "Rate limited. Wait: " . $rateLimitData['retry_after'] . " seconds\n";
} elseif ($httpCode === 200) {
    $userData = json_decode($response, true);
    echo "Login successful: " . $userData['user']['displayName'] . "\n";
}

curl_close($ch);
```

### Python/Requests

```python
import requests
import time

session = requests.Session()

# Login user
login_data = {
    'username': 'john.doe',
    'password': 'securepassword'
}

response = session.post(
    'https://your-nextcloud.com/apps/openconnector/api/user/login',
    json=login_data
)

if response.status_code == 429:
    rate_limit_data = response.json()
    print(f"Rate limited. Wait: {rate_limit_data.get('retry_after', 0)} seconds")
    if 'retry_after' in rate_limit_data:
        time.sleep(rate_limit_data['retry_after'])
elif response.status_code == 200:
    user_data = response.json()
    print(f"Login successful: {user_data['user']['displayName']}")

# Get current user (uses session from login)
user_response = session.get(
    'https://your-nextcloud.com/apps/openconnector/api/user/me'
)
current_user = user_response.json()
```

## Security Response Codes

| Code | Meaning | Action Required |
|------|---------|-----------------|
| `200` | Success | Continue normally |
| `400` | Bad input | Fix request format/data |
| `401` | Auth failed | Check credentials |
| `429` | Rate limited | Wait and retry (check `retry_after`) |
| `500` | Server error | Check logs, contact support |

## Rate Limiting Behavior

### Progressive Delays
```
Attempt 1-5: Normal response time
Attempt 6:   2 second delay
Attempt 7:   4 second delay  
Attempt 8:   8 second delay
Attempt 9:   16 second delay
Attempt 10:  32 second delay
Attempt 11+: 60 second delay (max)
```

### Lockout Thresholds
- **User Account**: 5 failed attempts → 1 hour lockout
- **IP Address**: 5 failed attempts → 1 hour lockout
- **Time Window**: 15 minutes for rate limiting

## Error Handling Best Practices

### Handle Rate Limiting

```javascript
async function loginWithRetry(username, password, maxRetries = 3) {
  for (let attempt = 1; attempt <= maxRetries; attempt++) {
    const response = await fetch('/api/user/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, password })
    });
    
    if (response.status === 429) {
      const data = await response.json();
      
      if (data.lockout_until) {
        // Account or IP locked - don't retry
        throw new Error(`Account locked until ${new Date(data.lockout_until * 1000)}`);
      }
      
      if (data.retry_after && attempt < maxRetries) {
        // Progressive delay - wait and retry
        await new Promise(resolve => setTimeout(resolve, data.retry_after * 1000));
        continue;
      }
    }
    
    return response;
  }
  
  throw new Error('Max login attempts exceeded');
}
```

### Validate Input Client-Side

```javascript
function validateLoginInput(username, password) {
  const errors = [];
  
  if (!username || username.length < 2) {
    errors.push('Username must be at least 2 characters');
  }
  
  if (!password || password.length === 0) {
    errors.push('Password is required');
  }
  
  if (username.length > 320) {
    errors.push('Username is too long');
  }
  
  if (password.length > 1000) {
    errors.push('Password is too long');
  }
  
  // Check for dangerous characters
  if (/[<>"'\/\\]/.test(username)) {
    errors.push('Username contains invalid characters');
  }
  
  return errors;
}
```

## Security Headers Reference

All API responses include these security headers:

| Header | Value | Purpose |
|--------|-------|---------|
| `X-Frame-Options` | `DENY` | Prevent clickjacking |
| `X-Content-Type-Options` | `nosniff` | Prevent MIME sniffing |
| `X-XSS-Protection` | `1; mode=block` | Browser XSS protection |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Control referrer info |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none';` | Restrict resource loading |
| `Cache-Control` | `no-store, no-cache, must-revalidate, private` | Prevent caching |

## Monitoring and Debugging

### Check Security Events

```bash
# Monitor failed login attempts
tail -f /var/log/nextcloud.log | grep "failed_login_attempt"

# Check rate limiting
tail -f /var/log/nextcloud.log | grep "rate_limit_exceeded"

# Monitor lockouts
tail -f /var/log/nextcloud.log | grep "locked_out"
```

### Test Security Features

```bash
# Test rate limiting (bash)
for i in {1..6}; do
  curl -X POST "https://your-nextcloud.com/apps/openconnector/api/user/login" \
    -H "Content-Type: application/json" \
    -d '{"username":"testuser","password":"wrong"}' \
    -w "HTTP %{http_code} - Time: %{time_total}s\n"
done

# Test XSS protection
curl -X POST "https://your-nextcloud.com/apps/openconnector/api/user/login" \
  -H "Content-Type: application/json" \
  -d '{"username":"<script>alert(1)</script>","password":"test"}' \
  -i
```

## Configuration Quick Reference

### Default Security Settings

```php
// lib/Service/SecurityService.php
RATE_LIMIT_ATTEMPTS = 5;        // Max attempts per window
RATE_LIMIT_WINDOW = 900;        // 15 minutes
LOCKOUT_DURATION = 3600;        // 1 hour
PROGRESSIVE_DELAY_BASE = 2;     // Base delay seconds
MAX_PROGRESSIVE_DELAY = 60;     // Max delay seconds
```

### Cache Requirements

- **Production**: Redis or Memcached
- **Development**: File cache (limited functionality)
- **Memory Usage**: ~3.5 MB per 10,000 active users

## Common Patterns

### Session Management

```javascript
class UserAPI {
  constructor(baseUrl) {
    this.baseUrl = baseUrl;
    this.sessionActive = false;
  }
  
  async login(username, password) {
    const response = await fetch(`${this.baseUrl}/api/user/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include', // Include cookies
      body: JSON.stringify({ username, password })
    });
    
    if (response.ok) {
      this.sessionActive = true;
      return await response.json();
    }
    
    throw new Error(await response.text());
  }
  
  async getCurrentUser() {
    if (!this.sessionActive) {
      throw new Error('Not authenticated');
    }
    
    const response = await fetch(`${this.baseUrl}/api/user/me`, {
      credentials: 'include'
    });
    
    if (response.status === 401) {
      this.sessionActive = false;
      throw new Error('Session expired');
    }
    
    return await response.json();
  }
}
```

### Error Display

```javascript
function displayLoginError(error, retryAfter = null, lockoutUntil = null) {
  let message = error;
  
  if (retryAfter) {
    message += ` Please wait ${retryAfter} seconds before trying again.`;
  }
  
  if (lockoutUntil) {
    const lockoutDate = new Date(lockoutUntil * 1000);
    message += ` Account locked until ${lockoutDate.toLocaleString()}.`;
  }
  
  // Display in UI
  document.getElementById('error-message').textContent = message;
  
  // Auto-hide after delay
  setTimeout(() => {
    document.getElementById('error-message').textContent = '';
  }, 5000);
}
```

## Related Documentation

- [User API Documentation](user-api.md) - Complete API reference
- [Security Best Practices](security-best-practices.md) - Detailed security information  
- [User API Setup](user-api-setup.md) - Installation and configuration guide 