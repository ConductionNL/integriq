# Source

## Configuration

Sources can be configured with various options to customize how they connect to external systems. All [Guzzle options](https://docs.guzzlephp.org/en/stable/request-options.html) are supported for HTTP-based sources.

### Common Configuration Options

Some commonly used configuration options include:

**Authentication:**
```json
headers:
  - name: Authorization
    value: Bearer YOUR_TOKEN_HERE
```

**Custom Headers:**
```json
headers:
  - name: Accept
    value: application/json 
```

**Query Parameters:**
```json
query:
  - name: limit
    value: 100
```

**Timeout Configuration:**
```json
timeout: 10
```

### Additional Configuration Parameters

You can set additional configuration parameters for sources:

- **logBody**: Controls response body logging behavior
  - `true` or `1`: Log all response bodies
  - `false` or `0`: Log only error response bodies (status codes 400-599)
  - Default: `false` (only error responses are logged)

Example:
```json
{
  "logBody": true,
  "timeout": 30
}
```

## Call Logs Management

The OpenConnector provides comprehensive call log management for monitoring API interactions with your sources. The **Source Logs** page offers professional-grade tools for analyzing, filtering, and managing your API call history.

### Accessing Source Logs

You can access source logs in two ways:

1. **From the main navigation**: Go to Sources → Logs
2. **From individual sources**: Click 'View Logs' on any source in the Sources list

### Call Logs Interface

The Source Logs page provides a streamlined, table-based interface optimized for log analysis:

#### Key Features

- **Table-only view**: Focused on readability and data density
- **Advanced filtering**: Comprehensive filtering system via sidebar
- **Export functionality**: Export filtered logs for external analysis
- **Bulk operations**: Select and delete multiple logs efficiently
- **Real-time updates**: Refresh logs with latest data

#### Log Information Displayed

Each log entry shows:

- **Status Code**: HTTP response status with color-coded indicators
  - 🟢 Green: Success (2xx)
  - 🟡 Yellow: Client errors (4xx)
  - 🔴 Red: Server errors (5xx)
- **Source**: Name of the source that made the call
- **HTTP Method**: GET, POST, PUT, PATCH, DELETE with color-coded badges
- **Endpoint**: Target URL or endpoint path
- **Response Time**: Request duration with performance indicators
  - 🟢 Fast: < 1 second
  - 🟡 Medium: 1-3 seconds
  - 🔴 Slow: > 3 seconds
- **Created**: Timestamp of the API call

### Advanced Filtering

The sidebar provides powerful filtering capabilities:

#### Source Filtering
- Filter logs by specific sources
- View logs from all sources or focus on individual sources

#### Status Code Filtering
- Filter by HTTP response codes:
  - Success codes: 200, 201
  - Client errors: 400, 401, 403, 404
  - Server errors: 500, 502, 503

#### HTTP Method Filtering
- Filter by request methods: GET, POST, PUT, PATCH, DELETE

#### Date Range Filtering
- **From Date**: Start of time range
- **To Date**: End of time range
- Custom date/time picker for precise filtering

#### Advanced Filters
- **Endpoint Filter**: Text-based filtering for specific endpoints
- **Show Only Errors**: Toggle to display only failed requests (4xx, 5xx)
- **Show Slow Requests**: Toggle to display only slow requests (>5 seconds)

#### Filter Statistics

The sidebar also provides real-time statistics:

- **Total Logs**: Overall count of call logs
- **Success Rate**: Percentage of successful calls (2xx status codes)
- **Average Response Time**: Mean response time across all calls
- **Status Distribution**: Breakdown of response codes
- **Most Active Sources**: Sources generating the most calls

### Log Actions

#### Individual Log Actions
- **View Details**: Open detailed log information in a modal
- **Copy Data**: Copy complete log data to clipboard (JSON format)
- **Delete**: Remove individual log entries

#### Bulk Operations
- **Select All**: Select all visible logs for bulk operations
- **Bulk Delete**: Remove multiple selected logs at once
- **Export**: Download filtered logs in CSV format

### Performance Monitoring

#### Response Time Analysis
The interface provides visual indicators for performance monitoring:

- **Fast responses** (< 1s): Green highlighting
- **Medium responses** (1-3s): Yellow highlighting  
- **Slow responses** (> 3s): Red highlighting

#### Error Analysis
- Color-coded status indicators help identify patterns
- Filter by error types to troubleshoot issues
- Export error logs for detailed analysis

### Export and Integration

#### Export Formats
- **CSV Export**: Download logs in comma-separated format
- **Filtered Export**: Export only logs matching current filters
- **Complete Data**: Include all log fields and metadata

#### Integration with Monitoring Tools
The log data can be integrated with external monitoring systems:

```php
// Example: Export log metrics
$metrics = [
    'total_calls' => $logCount,
    'success_rate' => $successRate,
    'average_response_time' => $avgResponseTime,
    'error_count' => $errorCount
];
```

### Best Practices

#### Log Management
1. **Regular Cleanup**: Periodically remove old logs to maintain performance
2. **Filter Strategy**: Use specific filters to focus on relevant data
3. **Export Important Data**: Download critical logs before cleanup
4. **Monitor Trends**: Watch for patterns in response times and errors

#### Performance Optimization
1. **Response Time Monitoring**: Track slow endpoints for optimization
2. **Error Pattern Analysis**: Identify recurring issues
3. **Source Performance**: Compare performance across different sources
4. **Rate Limit Awareness**: Monitor for rate limiting indicators

#### Troubleshooting
1. **Error Investigation**: Use status code filters to isolate issues
2. **Timeline Analysis**: Use date filters to correlate issues with events
3. **Endpoint Analysis**: Filter by specific endpoints to debug problems
4. **Source Comparison**: Compare behavior across different sources

**Note**: The log retention period and cleanup policies can be configured in the system settings.

## Testing Sources

Use the test functionality to verify your source configuration before deploying:

1. Navigate to your source configuration
2. Click the 'Test Source' button
3. Review the test results for connectivity and authentication
4. Adjust configuration as needed based on test feedback

Test results will appear in the source logs and can be analyzed using the same filtering and analysis tools. 