# User API Endpoints

The User API provides JSON-based endpoints for external applications to interact with user data without requiring form posts. This API is designed to facilitate easier integration with external systems and includes comprehensive security measures to protect against XSS attacks and brute force attempts.

## Architecture

The User API follows a clean service-oriented architecture:

- **UserController**: Thin controller handling HTTP requests/responses and security
- **UserService**: Business logic for user data management and custom field handling  
- **SecurityService**: Comprehensive protection against XSS and brute force attacks
- **Cross-app compatibility**: Custom name fields (firstName, lastName, middleName) stored in 'core' namespace for access by other NextCloud apps

### Custom Fields Accessibility

The custom name fields are stored in the 'core' namespace making them accessible to other NextCloud apps:

```php
// Any NextCloud app can access these fields:
$config = \OC::$server->getConfig();
$firstName = $config->getUserValue($userId, 'core', 'firstName', '');
$lastName = $config->getUserValue($userId, 'core', 'lastName', '');
$middleName = $config->getUserValue($userId, 'core', 'middleName', '');
```

See [examples/other-app-integration.php](../../examples/other-app-integration.php) for complete integration examples.

## Security Features

🔒 **Enterprise-Grade Security Protection:**
- **Rate Limiting**: 5 attempts per 15-minute window (per user and per IP)
- **Progressive Delays**: Exponential backoff (2s → 4s → 8s → 16s → 32s → 60s max)
- **Account Lockout**: 1-hour automatic lockout after failed attempts
- **IP Blocking**: Suspicious IP addresses are temporarily blocked
- **XSS Protection**: Comprehensive input sanitization and security headers
- **Security Logging**: All authentication events are logged for monitoring

For detailed security information, see [Security Best Practices](security-best-practices.md).

## Available Endpoints

### GET /api/user/me

Retrieves the current authenticated user's information in JSON format including user groups and complete profile data.

**Authentication Required:** Yes

**Features:**
- ✅ Complete user profile information
- ✅ User groups and permissions (`groups` array contains group IDs)
- ✅ Quota information with actual usage calculation
- ✅ Complete profile fields (phone, address, website, twitter, fediverse, organisation, role, headline, biography) when set
- ✅ Smart language/locale detection with fallbacks
- ✅ Backend capabilities and restrictions
- ✅ Email verification status
- ✅ Security headers included

**Request:**
```http
GET /api/user/me
Content-Type: application/json
```

**Response (Success - 200):**
```json
{
  'uid': 'john.doe',
  'displayName': 'John Doe',
  'email': 'john.doe@example.com',
  'enabled': true,
  'quota': {
    'free': '5 GB',
    'used': 1073741824,
    'total': 5368709120,
    'relative': 20.0
  },
  'avatarScope': 'contacts',
  'lastLogin': 1640995200,
      'backend': 'Database',
    'subadmin': [],
      'groups': ['users', 'admin'],
  'language': 'en',
  'locale': 'en_US',
  'firstName': 'Ruben',           // Optional: if set in profile
  'lastName': 'van der Linde',    // Optional: if set in profile
  'middleName': '2',              // Optional: if set in profile
  'phone': '+31645536677',        // Optional: if set in profile
  'address': 'Amsterdam',         // Optional: if set in profile
  'website': 'https://example.com', // Optional: if set in profile
  'twitter': '@username',         // Optional: if set in profile
  'fediverse': '@user@mastodon.social', // Optional: if set in profile
  'organisation': 'Conduction',   // Optional: if set in profile
  'role': 'Directeur',           // Optional: if set in profile
  'headline': 'Your headline',    // Optional: if set in profile
  'biography': 'Your biography',  // Optional: if set in profile
  'organisations': {
    'total': 2,
    'active': {
      'uuid': '550e8400-e29b-41d4-a716-446655440000',
      'name': 'Default Organisation',
      'description': 'Default organization for all users',
      'isDefault': true,
      'owner': 'admin',
      'users': ['admin', 'john.doe'],
      'created': '2024-01-01T00:00:00+00:00',
      'updated': '2024-01-01T00:00:00+00:00'
    },
    'results': [
      {
        'uuid': '550e8400-e29b-41d4-a716-446655440000',
        'name': 'Default Organisation',
        'description': 'Default organization for all users',
        'isDefault': true,
        'owner': 'admin',
        'users': ['admin', 'john.doe'],
        'created': '2024-01-01T00:00:00+00:00',
        'updated': '2024-01-01T00:00:00+00:00'
      },
      {
        'uuid': '550e8400-e29b-41d4-a716-446655440001',
        'name': 'Another Organisation',
        'description': 'Another organization',
        'isDefault': false,
        'owner': 'admin',
        'users': ['admin', 'john.doe'],
        'created': '2024-01-02T00:00:00+00:00',
        'updated': '2024-01-02T00:00:00+00:00'
      }
    ],
    'available': true
  },
  'backendCapabilities': {
    'displayName': true,
    'email': true,
    'password': true,
    'avatar': true
  }
}
```

**Response (Error - 401):**
```json
{
  'error': 'User not authenticated'
}
```

### PUT /api/user/me

Updates the current authenticated user's information based on the provided JSON data.

**Authentication Required:** Yes

**Request:**
```http
PUT /api/user/me
Content-Type: application/json

{
  'displayName': 'Updated Name',
  'email': 'newemail@example.com',
  'firstName': 'John',
  'lastName': 'Doe',
  'middleName': 'William',
  'language': 'nl',
  'locale': 'nl_NL',
  'activeOrganisation': '550e8400-e29b-41d4-a716-446655440001'
}
```

**Response (Success - 200):**
```json
{
  'uid': 'john.doe',
  'displayName': 'Updated Name',
  'email': 'newemail@example.com',
  'enabled': true,
  'quota': {
    'free': '5 GB',
    'used': 1073741824,
    'total': 5368709120,
    'relative': 20.0
  },
  'avatarScope': 'contacts',
  'lastLogin': 1640995200,
  'backend': 'Database',
  'subadmin': [],
  'groups': ['users', 'admin'],
  'language': 'nl',
  'locale': 'nl_NL',
  'firstName': 'John',            // Optional: if set in profile
  'lastName': 'Doe',              // Optional: if set in profile
  'middleName': 'William',        // Optional: if set in profile
  'phone': '+31645536677',        // Optional: if set in profile
  'address': 'Amsterdam',         // Optional: if set in profile
  'website': 'https://example.com', // Optional: if set in profile
  'twitter': '@username',         // Optional: if set in profile
  'fediverse': '@user@mastodon.social', // Optional: if set in profile
  'organisation': 'Conduction',   // Optional: if set in profile
  'role': 'Directeur',           // Optional: if set in profile
  'headline': 'Your headline',    // Optional: if set in profile
  'biography': 'Your biography',  // Optional: if set in profile
  'organisations': {
    'total': 2,
    'active': {
      'uuid': '550e8400-e29b-41d4-a716-446655440001',
      'name': 'Another Organisation',
      'description': 'Another organization',
      'isDefault': false,
      'owner': 'admin',
      'users': ['admin', 'john.doe'],
      'created': '2024-01-02T00:00:00+00:00',
      'updated': '2024-01-02T00:00:00+00:00'
    },
    'results': [
      {
        'uuid': '550e8400-e29b-41d4-a716-446655440000',
        'name': 'Default Organisation',
        'description': 'Default organization for all users',
        'isDefault': true,
        'owner': 'admin',
        'users': ['admin', 'john.doe'],
        'created': '2024-01-01T00:00:00+00:00',
        'updated': '2024-01-01T00:00:00+00:00'
      },
      {
        'uuid': '550e8400-e29b-41d4-a716-446655440001',
        'name': 'Another Organisation',
        'description': 'Another organization',
        'isDefault': false,
        'owner': 'admin',
        'users': ['admin', 'john.doe'],
        'created': '2024-01-02T00:00:00+00:00',
        'updated': '2024-01-02T00:00:00+00:00'
      }
    ],
    'available': true
  },
  'update_message': 'Active organization updated successfully',
  'backendCapabilities': {
    'displayName': true,
    'email': true,
    'password': true,
    'avatar': true
  }
}
```

**Response (Error - 401):**
```json
{
  'error': 'User not authenticated'
}
```

**Updatable Fields:**
- `displayName`: User's display name (if backend allows)
- `email`: User's email address (if backend allows)
- `password`: User's password (if backend allows)
- `firstName`: User's first name (stored in core namespace, accessible to other apps)
- `lastName`: User's last name (stored in core namespace, accessible to other apps)
- `middleName`: User's middle name (stored in core namespace, accessible to other apps)
- `phone`: User's phone number (stored in AccountManager)
- `activeOrganisation`: UUID of the organization to set as active (requires OpenRegister app)
- `address`: User's address (stored in AccountManager)
- `website`: User's website URL (stored in AccountManager)
- `twitter`: User's Twitter handle (stored in AccountManager)
- `fediverse`: User's Fediverse handle (stored in AccountManager)
- `organisation`: User's organization (stored in AccountManager)
- `role`: User's role/job title (stored in AccountManager)
- `headline`: User's headline (stored in AccountManager)
- `biography`: User's biography (stored in AccountManager)
- `language`: User's preferred language
- `locale`: User's locale setting

## Organization Management

The User API includes integration with the OpenRegister app's organization management system, providing multi-tenancy capabilities for users.

### Organization Data Structure

The `organisations` field in user responses contains:

- `total`: Number of organizations the user belongs to
- `active`: Currently active organization (null if none set)
- `results`: Array of all organizations the user belongs to
- `available`: Boolean indicating if organization service is available (OpenRegister app installed)

### Organization Response Examples

**Successful Organization Switch Response:**
```json
{
  "uid": "admin",
  "displayName": "Administrator",
  "organisations": {
    "total": 2,
    "active": {
      "uuid": "e6d272630b866cad2dee3aa3ac879281",
      "name": "Another Organisation",
      "description": "Another organization",
      "isDefault": false,
      "owner": "admin",
      "users": ["admin", "john.doe"],
      "created": "2024-01-02T00:00:00+00:00",
      "updated": "2024-01-02T00:00:00+00:00"
    },
    "results": [
      {
        "uuid": "0a2083b5602d9ac663abae79a985d453",
        "name": "Default Organisation",
        "description": "Default organization for all users",
        "isDefault": true,
        "owner": "admin",
        "users": ["admin", "john.doe"],
        "created": "2024-01-01T00:00:00+00:00",
        "updated": "2024-01-01T00:00:00+00:00"
      },
      {
        "uuid": "e6d272630b866cad2dee3aa3ac879281",
        "name": "Another Organisation",
        "description": "Another organization",
        "isDefault": false,
        "owner": "admin",
        "users": ["admin", "john.doe"],
        "created": "2024-01-02T00:00:00+00:00",
        "updated": "2024-01-02T00:00:00+00:00"
      }
    ],
    "available": true
  },
  "update_message": "Active organization updated successfully"
}
```

**Error Response (Invalid Organization UUID):**
```json
{
  "uid": "admin",
  "displayName": "Administrator",
  "organisations": {
    "total": 2,
    "active": {
      "uuid": "0a2083b5602d9ac663abae79a985d453",
      "name": "Default Organisation",
      "description": "Default organization for all users",
      "isDefault": true,
      "owner": "admin",
      "users": ["admin", "john.doe"],
      "created": "2024-01-01T00:00:00+00:00",
      "updated": "2024-01-01T00:00:00+00:00"
    },
    "results": [
      {
        "uuid": "0a2083b5602d9ac663abae79a985d453",
        "name": "Default Organisation",
        "description": "Default organization for all users",
        "isDefault": true,
        "owner": "admin",
        "users": ["admin", "john.doe"],
        "created": "2024-01-01T00:00:00+00:00",
        "updated": "2024-01-01T00:00:00+00:00"
      }
    ],
    "available": true
  },
  "organisation_message": "Invalid organization UUID provided"
}
```

**Response When OpenRegister App Not Available:**
```json
{
  "uid": "admin",
  "displayName": "Administrator",
  "organisations": {
    "total": 0,
    "active": null,
    "results": [],
    "available": false
  }
}
```

### Organization Switching

Users can switch their active organization by including the `activeOrganisation` field in PUT requests:

```json
{
  "activeOrganisation": "550e8400-e29b-41d4-a716-446655440001"
}
```

**Requirements:**
- OpenRegister app must be installed and enabled
- User must belong to the specified organization
- Organization UUID must be valid

**Response:**
- Success: Returns updated user data with new active organization
- Failure: Returns error message in `organisation_message` field

### Organization Integration

This integration provides:
- **Multi-tenancy**: Users can belong to multiple organizations
- **Context switching**: Users can switch between organizations
- **Fallback behavior**: Graceful handling when OpenRegister is not installed
- **Security**: Organization access is validated before switching

### POST /api/user/login

Securely authenticates a user using username/email and password combination with comprehensive protection against brute force attacks and XSS.

**Authentication Required:** No (Public endpoint - creates authentication)

**🔒 Security Features:**
- ✅ Input validation and sanitization (XSS protection)
- ✅ Rate limiting: 5 attempts per 15-minute window (per user and IP)
- ✅ Progressive delays: Exponential backoff (2s → 4s → 8s → 16s → 32s → 60s max)
- ✅ Account lockout: 1-hour automatic lockout after threshold
- ✅ IP blocking: Suspicious IP addresses temporarily blocked
- ✅ Security event logging for monitoring
- ✅ Comprehensive security headers

**Request:**
```http
POST /api/user/login
Content-Type: application/json

{
  'username': 'john.doe',
  'password': 'secretpassword'
}
```

**Response (Success - 200):**
```json
{
  'message': 'Login successful',
  'user': {
    'uid': 'john.doe',
    'displayName': 'John Doe',
    'email': 'john.doe@example.com',
    'enabled': true,
    'quota': {
      'free': '5 GB',
      'used': 1073741824,
      'total': 5368709120,
      'relative': 20.0
    },
    'avatarScope': 'contacts',
    'lastLogin': 1640995200,
    'backend': 'Database',
    'subadmin': [],
    'groups': ['users', 'admin'],
    'language': 'en',
    'locale': 'en_US',
    'firstName': 'John',            // Optional: if set in profile
    'lastName': 'Doe',              // Optional: if set in profile
    'middleName': 'William',        // Optional: if set in profile
    'phone': '+31645536677',        // Optional: if set in profile
    'address': 'Amsterdam',         // Optional: if set in profile
    'website': 'https://example.com', // Optional: if set in profile
    'twitter': '@username',         // Optional: if set in profile
    'fediverse': '@user@mastodon.social', // Optional: if set in profile
    'organisation': 'Conduction',   // Optional: if set in profile
    'role': 'Directeur',           // Optional: if set in profile
    'headline': 'Your headline',    // Optional: if set in profile
    'biography': 'Your biography',  // Optional: if set in profile
    'backendCapabilities': {
      'displayName': true,
      'email': true,
      'password': true,
      'avatar': true
    }
  },
  'session_created': true
}
```

**Response (Rate Limited - 429):**
```json
{
  'error': 'Too many login attempts. Please wait before trying again.',
  'retry_after': 8,
  'lockout_until': null
}
```

**Response (Account Locked - 429):**
```json
{
  'error': 'Account temporarily locked due to too many failed login attempts',
  'retry_after': null,
  'lockout_until': 1705334622
}
```

**Response (IP Blocked - 429):**
```json
{
  'error': 'IP address temporarily blocked due to suspicious activity',
  'retry_after': null,
  'lockout_until': 1705334622
}
```

**Response (Invalid Input - 400):**
```json
{
  'error': 'Username and password are required'
}
```

**Response (Invalid Credentials - 401):**
```json
{
  'error': 'Invalid username or password'
}
```

**Response (Account Disabled - 401):**
```json
{
  'error': 'Account is disabled'
}
```

**Response (System Error - 500):**
```json
{
  'error': 'Login failed due to a system error'
}
```

**Security Headers Included in All Responses:**
- `X-Frame-Options: DENY` - Prevents clickjacking
- `X-Content-Type-Options: nosniff` - Prevents MIME sniffing
- `X-XSS-Protection: 1; mode=block` - Browser XSS protection
- `Referrer-Policy: strict-origin-when-cross-origin` - Controls referrer info
- `Content-Security-Policy: default-src 'none'; frame-ancestors 'none';` - Restricts resource loading
- `Cache-Control: no-store, no-cache, must-revalidate, private` - Prevents caching

## Error Handling

All endpoints return appropriate HTTP status codes with comprehensive security considerations:

- **200 OK**: Request successful
- **400 Bad Request**: Invalid request parameters or malformed input
- **401 Unauthorized**: Authentication required, failed, or account disabled
- **429 Too Many Requests**: Rate limit exceeded, account locked, or IP blocked
- **500 Internal Server Error**: Server error occurred

**Security-Enhanced Error Responses:**

All error responses include:
- An 'error' field with a descriptive (but not revealing) message
- Security headers to prevent XSS and other attacks
- Rate limiting information when applicable (retry_after, lockout_until)
- Generic error messages to prevent information disclosure

**Rate Limiting Information:**
- `retry_after`: Seconds to wait before next attempt (progressive delay)
- `lockout_until`: Unix timestamp when lockout expires (account/IP lockout)

## Usage Examples

### JavaScript/Fetch Examples

#### Basic Login with Error Handling

```javascript
/**
 * Login user with comprehensive error handling and rate limiting support
 * @param {string} username - User's username or email
 * @param {string} password - User's password
 * @returns {Promise<Object>} Login result with user data and session info
 */
async function loginUser(username, password) {
  try {
    const response = await fetch('/index.php/apps/openconnector/api/user/login', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',

      },
      credentials: 'include',  // Include cookies for session management
      body: JSON.stringify({
        username: username,
        password: password
      })
    });
    
    const result = await response.json();
    
    if (response.ok) {
      console.log('Login successful:', result.message);
      console.log('User data:', result.user);
      
      // Store user data in session/localStorage if needed
      if (result.user && result.user.organisations) {
        localStorage.setItem('userOrganisations', JSON.stringify(result.user.organisations));
      }
      
      return {
        success: true,
        user: result.user,
        message: result.message,
        sessionCreated: result.session_created
      };
    } else {
      // Handle different error scenarios
      switch (response.status) {
        case 400:
          throw new Error(result.error || 'Invalid request parameters');
        case 401:
          throw new Error(result.error || 'Invalid credentials');
        case 429:
          // Rate limiting - provide retry information
          const retryInfo = {
            error: result.error,
            retryAfter: result.retry_after,
            lockoutUntil: result.lockout_until
          };
          throw new Error(`Rate limited: ${result.error}. Retry after: ${result.retry_after}s`);
        case 500:
          throw new Error('Server error occurred. Please try again later.');
        default:
          throw new Error(result.error || 'Login failed');
      }
    }
  } catch (error) {
    console.error('Login failed:', error.message);
    return {
      success: false,
      error: error.message
    };
  }
}

/**
 * Get current user information including organization data
 * @returns {Promise<Object>} User data with organization information
 */
async function getCurrentUser() {
  try {
    const response = await fetch('/index.php/apps/openconnector/api/user/me', {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include'
    });
    
    if (response.ok) {
      const userData = await response.json();
      console.log('Current user:', userData);
      
      // Update stored organization data
      if (userData.organisations) {
        localStorage.setItem('userOrganisations', JSON.stringify(userData.organisations));
      }
      
      return {
        success: true,
        user: userData
      };
    } else {
      const error = await response.json();
      throw new Error(error.error || 'Failed to get user information');
    }
  } catch (error) {
    console.error('Failed to get user:', error.message);
    return {
      success: false,
      error: error.message
    };
  }
}

/**
 * Update user information including organization switching
 * @param {Object} updates - Object containing fields to update
 * @param {string} [updates.activeOrganisation] - UUID of organization to set as active
 * @returns {Promise<Object>} Updated user data
 */
async function updateUser(updates) {
  try {
    const response = await fetch('/index.php/apps/openconnector/api/user/me', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'include',
      body: JSON.stringify(updates)
    });
    
    if (response.ok) {
      const userData = await response.json();
      console.log('User updated:', userData);
      
      // Update stored organization data if organizations were included
      if (userData.organisations) {
        localStorage.setItem('userOrganisations', JSON.stringify(userData.organisations));
      }
      
      return {
        success: true,
        user: userData,
        updateMessage: userData.update_message
      };
    } else {
      const error = await response.json();
      throw new Error(error.error || 'Update failed');
    }
  } catch (error) {
    console.error('Update failed:', error.message);
    return {
      success: false,
      error: error.message
    };
  }
}

/**
 * Switch user's active organization
 * @param {string} organisationUuid - UUID of the organization to set as active
 * @returns {Promise<Object>} Result of organization switch
 */
async function switchActiveOrganisation(organisationUuid) {
  return await updateUser({
    activeOrganisation: organisationUuid
  });
}

/**
 * Get user's organization information
 * @returns {Object|null} Organization data from localStorage or null
 */
function getUserOrganisations() {
  try {
    const stored = localStorage.getItem('userOrganisations');
    return stored ? JSON.parse(stored) : null;
  } catch (error) {
    console.error('Failed to parse stored organizations:', error);
    return null;
  }
}

/**
 * Get currently active organization
 * @returns {Object|null} Active organization or null
 */
function getActiveOrganisation() {
  const organisations = getUserOrganisations();
  return organisations?.active || null;
}
```

#### Advanced Login with Organization Management

```javascript
/**
 * Complete login flow with organization handling
 * @param {string} username - User's username or email
 * @param {string} password - User's password
 * @param {string} [preferredOrganisation] - Optional preferred organization UUID
 * @returns {Promise<Object>} Complete login result
 */
async function loginWithOrganisation(username, password, preferredOrganisation = null) {
  // Step 1: Login user
  const loginResult = await loginUser(username, password);
  
  if (!loginResult.success) {
    return loginResult;
  }
  
  // Step 2: Get fresh user data (including organizations)
  const userResult = await getCurrentUser();
  
  if (!userResult.success) {
    return userResult;
  }
  
  const user = userResult.user;
  
  // Step 3: Handle organization selection
  if (user.organisations && user.organisations.available) {
    const organisations = user.organisations;
    
    // If no active organization is set, set the first available one
    if (!organisations.active && organisations.results.length > 0) {
      const firstOrg = organisations.results[0];
      console.log('Setting first organization as active:', firstOrg.name);
      
      const switchResult = await switchActiveOrganisation(firstOrg.uuid);
      if (switchResult.success) {
        user.organisations = switchResult.user.organisations;
      }
    }
    
    // If preferred organization is specified and user belongs to it, switch to it
    if (preferredOrganisation && organisations.results.some(org => org.uuid === preferredOrganisation)) {
      console.log('Switching to preferred organization');
      const switchResult = await switchActiveOrganisation(preferredOrganisation);
      if (switchResult.success) {
        user.organisations = switchResult.user.organisations;
      }
    }
  }
  
  return {
    success: true,
    user: user,
    message: 'Login successful with organization context'
  };
}

/**
 * Login form handler with comprehensive error handling
 * @param {Event} event - Form submit event
 */
async function handleLoginForm(event) {
  event.preventDefault();
  
  const form = event.target;
  const username = form.querySelector('[name="username"]').value;
  const password = form.querySelector('[name="password"]').value;
  const submitButton = form.querySelector('[type="submit"]');
  const errorDiv = form.querySelector('.error-message');
  
  // Disable form during login
  submitButton.disabled = true;
  submitButton.textContent = 'Logging in...';
  errorDiv.textContent = '';
  
  try {
    const result = await loginUser(username, password);
    
    if (result.success) {
      // Login successful - redirect or update UI
      window.location.href = '/dashboard';
    } else {
      // Show error message
      errorDiv.textContent = result.error;
      submitButton.disabled = false;
      submitButton.textContent = 'Login';
    }
  } catch (error) {
    errorDiv.textContent = 'An unexpected error occurred. Please try again.';
    submitButton.disabled = false;
    submitButton.textContent = 'Login';
  }
}

/**
 * Organization selector component
 * @param {string} currentOrganisationUuid - Currently active organization UUID
 * @param {Array} organisations - Array of user's organizations
 */
function createOrganisationSelector(currentOrganisationUuid, organisations) {
  const container = document.createElement('div');
  container.className = 'organisation-selector';
  
  const select = document.createElement('select');
  select.addEventListener('change', async (event) => {
    const newOrganisationUuid = event.target.value;
    if (newOrganisationUuid && newOrganisationUuid !== currentOrganisationUuid) {
      const result = await switchActiveOrganisation(newOrganisationUuid);
      if (result.success) {
        // Update UI to reflect new organization
        console.log('Switched to organization:', result.user.organisations.active.name);
        // Optionally reload page or update UI components
        window.location.reload();
      } else {
        alert('Failed to switch organization: ' + result.error);
      }
    }
  });
  
  organisations.forEach(org => {
    const option = document.createElement('option');
    option.value = org.uuid;
    option.textContent = org.name;
    option.selected = org.uuid === currentOrganisationUuid;
    select.appendChild(option);
  });
  
  container.appendChild(select);
  return container;
}
```

#### Organization Switching Examples

```javascript
// Example 1: Basic organization switching
async function switchToOrganization(organisationUuid) {
  console.log('Switching to organization:', organisationUuid);
  
  const result = await switchActiveOrganisation(organisationUuid);
  
  if (result.success) {
    console.log('✅ Successfully switched to:', result.user.organisations.active.name);
    console.log('Active organization details:', result.user.organisations.active);
    return result.user.organisations.active;
  } else {
    console.error('❌ Failed to switch organization:', result.error);
    return null;
  }
}

// Example 2: Switch with validation
async function switchToOrganizationWithValidation(organisationUuid) {
  // First, get current user to check available organizations
  const userResult = await getCurrentUser();
  
  if (!userResult.success) {
    console.error('Failed to get user data');
    return null;
  }
  
  const user = userResult.user;
  const organisations = user.organisations;
  
  // Check if organization service is available
  if (!organisations?.available) {
    console.error('Organization service not available');
    return null;
  }
  
  // Check if user belongs to the specified organization
  const targetOrg = organisations.results.find(org => org.uuid === organisationUuid);
  if (!targetOrg) {
    console.error('User does not belong to organization:', organisationUuid);
    console.log('Available organizations:', organisations.results.map(org => ({ uuid: org.uuid, name: org.name })));
    return null;
  }
  
  // Switch to the organization
  return await switchToOrganization(organisationUuid);
}

// Example 3: Switch to default organization
async function switchToDefaultOrganization() {
  const userResult = await getCurrentUser();
  
  if (!userResult.success) {
    console.error('Failed to get user data');
    return null;
  }
  
  const organisations = userResult.user.organisations;
  
  if (!organisations?.available || organisations.results.length === 0) {
    console.error('No organizations available');
    return null;
  }
  
  // Find the default organization
  const defaultOrg = organisations.results.find(org => org.isDefault);
  
  if (defaultOrg) {
    console.log('Switching to default organization:', defaultOrg.name);
    return await switchToOrganization(defaultOrg.uuid);
  } else {
    console.log('No default organization found, using first available');
    return await switchToOrganization(organisations.results[0].uuid);
  }
}

// Example 4: Organization selector with real-time updates
function createAdvancedOrganisationSelector(containerId) {
  const container = document.getElementById(containerId);
  
  async function updateSelector() {
    const userResult = await getCurrentUser();
    
    if (!userResult.success || !userResult.user.organisations?.available) {
      container.innerHTML = '<p>No organizations available</p>';
      return;
    }
    
    const organisations = userResult.user.organisations;
    const activeOrg = organisations.active;
    
    // Create selector HTML
    let html = '<div class="org-selector">';
    html += '<label for="org-select">Active Organization:</label>';
    html += '<select id="org-select">';
    
    organisations.results.forEach(org => {
      const selected = activeOrg && org.uuid === activeOrg.uuid ? 'selected' : '';
      const defaultBadge = org.isDefault ? ' (Default)' : '';
      html += `<option value="${org.uuid}" ${selected}>${org.name}${defaultBadge}</option>`;
    });
    
    html += '</select>';
    html += '<div class="org-info">';
    
    if (activeOrg) {
      html += `<p><strong>Current:</strong> ${activeOrg.name}</p>`;
      html += `<p><strong>Description:</strong> ${activeOrg.description || 'No description'}</p>`;
      html += `<p><strong>Members:</strong> ${activeOrg.users.length} users</p>`;
    }
    
    html += '</div></div>';
    
    container.innerHTML = html;
    
    // Add event listener
    const select = document.getElementById('org-select');
    select.addEventListener('change', async (event) => {
      const newOrgUuid = event.target.value;
      const result = await switchToOrganization(newOrgUuid);
      
      if (result) {
        // Update the selector with new data
        updateSelector();
        
        // Show success message
        showNotification(`Switched to organization: ${result.name}`, 'success');
      } else {
        showNotification('Failed to switch organization', 'error');
      }
    });
  }
  
  // Initial load
  updateSelector();
  
  // Refresh button
  const refreshBtn = document.createElement('button');
  refreshBtn.textContent = 'Refresh Organizations';
  refreshBtn.onclick = updateSelector;
  container.appendChild(refreshBtn);
}

// Example 5: Organization context management
class OrganizationManager {
  constructor() {
    this.currentOrganization = null;
    this.organizations = [];
  }
  
  async initialize() {
    const userResult = await getCurrentUser();
    
    if (userResult.success && userResult.user.organisations?.available) {
      this.organizations = userResult.user.organisations.results;
      this.currentOrganization = userResult.user.organisations.active;
      
      console.log('Organization manager initialized');
      console.log('Available organizations:', this.organizations.length);
      console.log('Current organization:', this.currentOrganization?.name);
      
      return true;
    }
    
    return false;
  }
  
  async switchTo(organisationUuid) {
    const result = await switchActiveOrganisation(organisationUuid);
    
    if (result.success) {
      this.currentOrganization = result.user.organisations.active;
      this.organizations = result.user.organisations.results;
      
      // Trigger custom event for other components
      window.dispatchEvent(new CustomEvent('organizationChanged', {
        detail: { organization: this.currentOrganization }
      }));
      
      return this.currentOrganization;
    }
    
    return null;
  }
  
  getCurrent() {
    return this.currentOrganization;
  }
  
  getAll() {
    return this.organizations;
  }
  
  isDefault(organisationUuid) {
    const org = this.organizations.find(o => o.uuid === organisationUuid);
    return org?.isDefault || false;
  }
  
  getUserCount(organisationUuid) {
    const org = this.organizations.find(o => o.uuid === organisationUuid);
    return org?.users?.length || 0;
  }
}

// Example 6: Complete organization workflow
async function completeOrganizationWorkflow() {
  console.log('🚀 Starting organization workflow...');
  
  // Step 1: Initialize organization manager
  const orgManager = new OrganizationManager();
  const initialized = await orgManager.initialize();
  
  if (!initialized) {
    console.error('❌ Failed to initialize organization manager');
    return;
  }
  
  console.log('✅ Organization manager initialized');
  
  // Step 2: Display current organization
  const current = orgManager.getCurrent();
  if (current) {
    console.log(`📍 Currently in: ${current.name}`);
  } else {
    console.log('📍 No active organization');
  }
  
  // Step 3: List all available organizations
  const allOrgs = orgManager.getAll();
  console.log('📋 Available organizations:');
  allOrgs.forEach(org => {
    const defaultBadge = org.isDefault ? ' (Default)' : '';
    const userCount = orgManager.getUserCount(org.uuid);
    console.log(`  - ${org.name}${defaultBadge} (${userCount} users)`);
  });
  
  // Step 4: Switch to a different organization (if available)
  if (allOrgs.length > 1) {
    const targetOrg = allOrgs.find(org => !org.isDefault) || allOrgs[0];
    console.log(`🔄 Switching to: ${targetOrg.name}`);
    
    const result = await orgManager.switchTo(targetOrg.uuid);
    if (result) {
      console.log(`✅ Successfully switched to: ${result.name}`);
    } else {
      console.error('❌ Failed to switch organization');
    }
  }
  
  // Step 5: Set up organization change listener
  window.addEventListener('organizationChanged', (event) => {
    const newOrg = event.detail.organization;
    console.log(`🎉 Organization changed to: ${newOrg.name}`);
    
    // Update UI elements that depend on organization context
    updateOrganizationDependentUI(newOrg);
  });
  
  console.log('✅ Organization workflow completed');
}

// Example 7: Error handling for organization operations
async function safeOrganizationSwitch(organisationUuid) {
  try {
    // Validate UUID format
    if (!organisationUuid || !/^[0-9a-f]{32}$/.test(organisationUuid)) {
      throw new Error('Invalid organization UUID format');
    }
    
    // Attempt to switch
    const result = await switchActiveOrganisation(organisationUuid);
    
    if (!result.success) {
      throw new Error(result.error || 'Unknown error occurred');
    }
    
    return {
      success: true,
      organization: result.user.organisations.active,
      message: `Successfully switched to ${result.user.organisations.active.name}`
    };
    
  } catch (error) {
    console.error('Organization switch failed:', error.message);
    
    return {
      success: false,
      error: error.message,
      suggestions: [
        'Check if the organization UUID is correct',
        'Verify you have access to this organization',
        'Ensure the OpenRegister app is installed and enabled'
      ]
    };
  }
}

// Example 8: Batch organization operations
async function batchOrganizationOperations() {
  const operations = [
    { type: 'get', description: 'Get current organization' },
    { type: 'switch', uuid: 'e6d272630b866cad2dee3aa3ac879281', description: 'Switch to specific organization' },
    { type: 'get', description: 'Get updated organization info' },
    { type: 'switch', uuid: '0a2083b5602d9ac663abae79a985d453', description: 'Switch to another organization' }
  ];
  
  console.log('🔄 Starting batch organization operations...');
  
  for (const operation of operations) {
    console.log(`\n📋 ${operation.description}...`);
    
    try {
      if (operation.type === 'get') {
        const userResult = await getCurrentUser();
        if (userResult.success) {
          const activeOrg = userResult.user.organisations?.active;
          console.log(`  ✅ Current organization: ${activeOrg?.name || 'None'}`);
        }
      } else if (operation.type === 'switch') {
        const result = await switchActiveOrganisation(operation.uuid);
        if (result.success) {
          console.log(`  ✅ Switched to: ${result.user.organisations.active.name}`);
        } else {
          console.log(`  ❌ Failed: ${result.error}`);
        }
      }
    } catch (error) {
      console.log(`  ❌ Error: ${error.message}`);
    }
  }
  
  console.log('\n✅ Batch operations completed');
}

// Usage examples
console.log('=== Organization Switching Examples ===');

// Basic switching
switchToOrganization('e6d272630b866cad2dee3aa3ac879281');

// Validated switching
switchToOrganizationWithValidation('0a2083b5602d9ac663abae79a985d453');

// Switch to default
switchToDefaultOrganization();

// Create advanced selector
createAdvancedOrganisationSelector('org-selector-container');

// Initialize organization manager
const orgManager = new OrganizationManager();
orgManager.initialize().then(() => {
  console.log('Organization manager ready');
});

// Complete workflow
completeOrganizationWorkflow();

// Safe switching with error handling
safeOrganizationSwitch('e6d272630b866cad2dee3aa3ac879281');

// Batch operations
batchOrganizationOperations();
```

### cURL Examples

```bash
# Login user (creates session)
curl -X POST 'https://your-nextcloud.com/index.php/apps/openconnector/api/user/login' \
  -H 'Content-Type: application/json' \
  -c 'cookies.txt' \
  -d '{
    "username": "john.doe",
    "password": "secretpassword"
  }'

# Get current user information (requires authentication)
curl -X GET 'https://your-nextcloud.com/index.php/apps/openconnector/api/user/me' \
  -H 'Content-Type: application/json' \
  -b 'cookies.txt'

# Update user information
curl -X PUT 'https://your-nextcloud.com/index.php/apps/openconnector/api/user/me' \
  -H 'Content-Type: application/json' \
  -b 'cookies.txt' \
  -d '{
    "displayName": "New Display Name",
    "email": "newemail@example.com"
  }'

# Switch active organization
curl -X PUT 'https://your-nextcloud.com/index.php/apps/openconnector/api/user/me' \
  -H 'Content-Type: application/json' \
  -b 'cookies.txt' \
  -d '{
    "activeOrganisation": "e6d272630b866cad2dee3aa3ac879281"
  }'

# Update user and switch organization in one request
curl -X PUT 'https://your-nextcloud.com/index.php/apps/openconnector/api/user/me' \
  -H 'Content-Type: application/json' \
  -b 'cookies.txt' \
  -d '{
    "firstName": "John",
    "lastName": "Doe",
    "activeOrganisation": "0a2083b5602d9ac663abae79a985d453"
  }'
```

### Local Development Testing

For local development, use the Docker container approach as described in the testing section:

```bash
# Login from within Docker container
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST 'http://localhost/index.php/apps/openconnector/api/user/login' -d '{\"username\": \"admin\", \"password\": \"admin\"}'"

# Get user info with organizations
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' 'http://localhost/index.php/apps/openconnector/api/user/me'"

# Switch organization
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT 'http://localhost/index.php/apps/openconnector/api/user/me' -d '{\"activeOrganisation\": \"e6d272630b866cad2dee3aa3ac879281\"}'"

### Organization Switching cURL Examples

```bash
# Example 1: Get current user with organization data
curl -u 'admin:admin' -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openconnector/api/user/me' | jq '.organisations'

# Example 2: Switch to specific organization
curl -u 'admin:admin' -H 'Content-Type: application/json' \
  -X PUT 'http://localhost/index.php/apps/openconnector/api/user/me' \
  -d '{"activeOrganisation": "e6d272630b866cad2dee3aa3ac879281"}'

# Example 3: Switch to default organization (first available)
curl -u 'admin:admin' -H 'Content-Type: application/json' \
  -X PUT 'http://localhost/index.php/apps/openconnector/api/user/me' \
  -d '{"activeOrganisation": "0a2083b5602d9ac663abae79a985d453"}'

# Example 4: Update user and switch organization in one request
curl -u 'admin:admin' -H 'Content-Type: application/json' \
  -X PUT 'http://localhost/index.php/apps/openconnector/api/user/me' \
  -d '{
    "firstName": "John",
    "lastName": "Doe",
    "activeOrganisation": "e6d272630b866cad2dee3aa3ac879281"
  }'

# Example 5: Verify organization switch was successful
curl -u 'admin:admin' -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openconnector/api/user/me' | jq '.organisations.active'

# Example 6: List all user organizations
curl -u 'admin:admin' -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openconnector/api/user/me' | jq '.organisations.results[] | {uuid: .uuid, name: .name, isDefault: .isDefault}'

# Example 7: Switch to invalid organization (error handling)
curl -u 'admin:admin' -H 'Content-Type: application/json' \
  -X PUT 'http://localhost/index.php/apps/openconnector/api/user/me' \
  -d '{"activeOrganisation": "invalid-uuid-format"}'

# Example 8: Batch organization operations script
#!/bin/bash
echo "=== Organization Management Script ==="

# Get current organization
echo "1. Current organization:"
curl -s -u 'admin:admin' -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openconnector/api/user/me' | jq -r '.organisations.active.name'

# List all organizations
echo -e "\n2. Available organizations:"
curl -s -u 'admin:admin' -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openconnector/api/user/me' | jq -r '.organisations.results[] | "  - \(.name) (\(.uuid))"'

# Switch to first non-default organization
echo -e "\n3. Switching to first non-default organization:"
ORG_UUID=$(curl -s -u 'admin:admin' -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openconnector/api/user/me' | jq -r '.organisations.results[] | select(.isDefault == false) | .uuid' | head -1)

if [ "$ORG_UUID" != "null" ] && [ -n "$ORG_UUID" ]; then
  curl -s -u 'admin:admin' -H 'Content-Type: application/json' \
    -X PUT 'http://localhost/index.php/apps/openconnector/api/user/me' \
    -d "{\"activeOrganisation\": \"$ORG_UUID\"}" | jq -r '.organisations.active.name'
else
  echo "No non-default organization found"
fi

echo -e "\n4. Final organization state:"
curl -s -u 'admin:admin' -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/openconnector/api/user/me' | jq -r '.organisations.active.name'
```

## AJAX Call Best Practices

### Required Headers and Configuration

**Essential Headers:**
- `Content-Type: application/json` - Required for JSON requests
- `credentials: 'include'` - Required for session management in JavaScript

**URL Structure:**
- Use full NextCloud URL: `/index.php/apps/openconnector/api/user/...`
- Don't use relative paths like `/api/user/...` as they won't work

### Session Management

**JavaScript (Fetch API):**
```javascript
// Always include credentials for session management
const response = await fetch('/index.php/apps/openconnector/api/user/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  credentials: 'include',  // ← Required for cookies/session
  body: JSON.stringify({ username, password })
});
```

**cURL:**
```bash
# Save cookies for session management
curl -c 'cookies.txt' -X POST '...'

# Use saved cookies for authenticated requests
curl -b 'cookies.txt' -X GET '...'
```

### Error Handling Patterns

**JavaScript Error Handling:**
```javascript
// Always check response.ok before processing
if (response.ok) {
  const data = await response.json();
  // Handle success
} else {
  const error = await response.json();
  // Handle specific error types
  switch (response.status) {
    case 401: // Authentication failed
    case 429: // Rate limited
    case 500: // Server error
  }
}
```

**Rate Limiting Handling:**
```javascript
// Check for rate limiting information
if (response.status === 429) {
  const error = await response.json();
  const retryAfter = error.retry_after; // Seconds to wait
  const lockoutUntil = error.lockout_until; // Unix timestamp
  
  // Implement exponential backoff
  setTimeout(() => retryLogin(), retryAfter * 1000);
}
```

### Organization Management in AJAX

**Storing Organization Data:**
```javascript
// Store organization data locally for quick access
if (userData.organisations) {
  localStorage.setItem('userOrganisations', JSON.stringify(userData.organisations));
}

// Retrieve organization data
const organisations = JSON.parse(localStorage.getItem('userOrganisations') || '{}');
```

**Organization Switching:**
```javascript
// Switch organization and update local storage
const result = await updateUser({ activeOrganisation: orgUuid });
if (result.success) {
  localStorage.setItem('userOrganisations', JSON.stringify(result.user.organisations));
  // Update UI to reflect new organization
}
```

### Security Considerations

- **HTTPS Required**: Always use HTTPS in production for secure data transmission
- **Session Management**: Let NextCloud handle session management automatically
- **Input Validation**: Validate all user inputs before sending to API
- **Error Messages**: Don't expose sensitive information in error messages
- **Rate Limiting**: Implement proper retry logic with exponential backoff
- **CSRF Protection**: NextCloud handles CSRF protection automatically

### Common Mistakes to Avoid

❌ **Don't use relative URLs:**
```javascript
// Wrong - won't work
fetch('/api/user/me')

// Correct - full NextCloud path
fetch('/index.php/apps/openconnector/api/user/me')
```

❌ **Don't forget Content-Type header:**
```javascript
// Wrong - may not work properly
fetch(url, { method: 'POST' })

// Correct - includes required Content-Type
fetch(url, { 
  method: 'POST',
  headers: { 'Content-Type': 'application/json' }
})
```

❌ **Don't ignore credentials:**
```javascript
// Wrong - no session management
fetch(url, { method: 'POST' })

// Correct - includes session management
fetch(url, { 
  method: 'POST',
  credentials: 'include'
})
```

❌ **Don't forget error handling:**
```javascript
// Wrong - no error handling
const data = await response.json();

// Correct - proper error handling
if (response.ok) {
  const data = await response.json();
} else {
  const error = await response.json();
  throw new Error(error.error);
}
```

### Testing AJAX Calls

**Browser Developer Tools:**
1. Open Network tab in DevTools
2. Make API call
3. Check request headers include `Content-Type: application/json`
4. Verify response status and data
5. Check for any CORS or authentication errors

**Local Development:**
- Use Docker container approach for testing
- Check browser console for JavaScript errors
- Verify cookies are being set and sent properly
- Test organization switching functionality

## Notes

- The API follows RESTful conventions
- All responses are in JSON format
- User capabilities depend on the NextCloud backend configuration
- Groups and subadmin information require additional services and are currently returned as empty arrays
- Quota calculations are based on available NextCloud user data 