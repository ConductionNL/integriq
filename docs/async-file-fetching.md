# Asynchronous File Fetching

The OpenConnector SynchronizationService now supports asynchronous file fetching using ReactPHP for improved performance during synchronization operations. This feature allows file downloads to happen in the background without blocking the main synchronization process.

## Overview

When a synchronization rule includes file fetching operations, these can be time-consuming and may slow down the overall synchronization process. The asynchronous file fetching feature addresses this by:

- **Fire-and-forget execution**: File fetching operations are initiated but don't block the synchronization
- **Immediate continuation**: Synchronization continues with placeholder values while files are fetched in the background
- **Error isolation**: File fetching errors don't affect the main synchronization process
- **Flexible tag/label support**: Supports multiple property names for tagging files with smart configuration options
- **ReactPHP integration**: Uses ReactPHP promises and event loop for efficient async operations

## How It Works

### 1. Rule Processing

When the `processFetchFileRule` method is called during synchronization:

1. **Validation**: Checks if OpenRegister app is available and configuration is valid
2. **Endpoint extraction**: Extracts file endpoints from the data using the configured file path
3. **Tag/label processing**: Intelligently extracts tags from multiple property variations
4. **Async initiation**: Starts asynchronous file fetching operations
5. **Placeholder return**: Returns immediately with placeholder values

### 2. Asynchronous Execution

The async file fetching process involves several steps:

```php
// 1. Schedule async operation
$loop->futureTick(function() use ($source, $config, $endpoint, $objectId, $ruleId) {
    $this->executeAsyncFileFetching($source, $config, $endpoint, $objectId, $ruleId);
});

// 2. Execute file fetching based on endpoint type
switch ($this->getArrayType($endpoint)) {
    case 'Not array':
        $this->fetchFileAsync($source, $endpoint, $config, $objectId);
        break;
    // ... other cases
}

// 3. Wrap in ReactPHP promise
$deferred = new Deferred();
$result = $this->fetchFile(/* parameters */);
$deferred->resolve($result);
```

### 3. Endpoint Types Supported

The system handles different types of file endpoints:

- **Single endpoint**: Direct file URL
- **Associative array**: Object containing file metadata and endpoint
- **Multidimensional array**: Array of objects with file information
- **Indexed array**: Simple array of file endpoints

## Flexible Tag and Label Support

### Supported Property Names

The file fetching system now supports multiple property names for tags and labels:

- **`label`**: Single label value
- **`labels`**: Array of labels or single label value
- **`tag`**: Single tag value  
- **`tags`**: Array of tags or single tag value

### Example Endpoint Formats

#### Single Label
```json
{
  'filename': 'document.pdf',
  'endpoint': '/api/file/123',
  'label': 'bijlage'
}
```

#### Multiple Labels
```json
{
  'filename': 'document.pdf',
  'endpoint': '/api/file/123',
  'labels': ['bijlage', 'important', 'correspondence']
}
```

#### Single Tag
```json
{
  'filename': 'document.pdf',
  'endpoint': '/api/file/123',
  'tag': 'document'
}
```

#### Multiple Tags
```json
{
  'filename': 'document.pdf',
  'endpoint': '/api/file/123',
  'tags': ['document', 'confidential', 'archived']
}
```

#### Mixed Properties
```json
{
  'filename': 'document.pdf',
  'endpoint': '/api/file/123',
  'label': 'bijlage',
  'tags': ['important', 'signed'],
  'labels': ['correspondence']
}
```

### Tag Processing Logic

The system intelligently processes tags from all supported properties:

1. **Property scanning**: Checks for 'label', 'labels', 'tag', and 'tags' properties
2. **Value extraction**: Handles both single values and arrays
3. **Filtering**: Removes empty values and non-string entries
4. **Deduplication**: Automatically removes duplicate tags
5. **Configuration filtering**: Applies configured tag filtering rules

## Configuration

### Rule Configuration

File fetching rules are configured in the synchronization rule configuration:

```json
{
  'fetch_file': {
    'source': 'source-id',
    'filePath': 'path.to.file.endpoint',
    'write': true,
    'autoShare': false,
    'useLabelsAsTags': true,
    'allowedLabels': ['bijlage', 'document', 'important'],
    'tags': ['system-tag'],
    'sourceConfiguration': {
      'headers': {
        'Authorization': 'Bearer token'
      }
    }
  }
}
```

### Configuration Options

#### Basic Options
- **source**: ID of the source to fetch files from
- **filePath**: Dot notation path to the file endpoint in the data
- **write**: Whether to write files to storage (default: true)
- **autoShare**: Whether to automatically share files (default: false)
- **sourceConfiguration**: Additional configuration for the HTTP request

#### Tag Configuration Options

You have several options for controlling how labels/tags are processed:

##### Option 1: Auto-accept All Labels/Tags
```json
{
  'fetch_file': {
    'source': 'source-id',
    'filePath': 'attachments',
    'useLabelsAsTags': true
  }
}
```
**Behavior**: All labels and tags from endpoint data are automatically used as file tags.

##### Option 2: Allowed Labels Whitelist
```json
{
  'fetch_file': {
    'source': 'source-id',
    'filePath': 'attachments',
    'allowedLabels': ['bijlage', 'document', 'correspondence', 'signed']
  }
}
```
**Behavior**: Only labels/tags that are in the allowedLabels array will be applied to files.

##### Option 3: Legacy Tag Configuration
```json
{
  'fetch_file': {
    'source': 'source-id',
    'filePath': 'attachments',
    'tags': ['bijlage', 'important']
  }
}
```
**Behavior**: Traditional behavior - only labels/tags that match entries in the tags array are used.

##### Option 4: Default Behavior (No Configuration)
```json
{
  'fetch_file': {
    'source': 'source-id',
    'filePath': 'attachments'
  }
}
```
**Behavior**: If no tag configuration is provided, all labels/tags from endpoint data are automatically used.

### Priority Order

When multiple tag configuration options are present, they are processed in this priority order:

1. **useLabelsAsTags**: If set to true, overrides all other options
2. **allowedLabels**: If present, filters tags using this whitelist
3. **tags**: Legacy behavior for backwards compatibility
4. **Default**: Auto-accept all labels/tags if no configuration is present

### Combining with System Tags

File tags from endpoint data are combined with system-generated tags:

- **Object tag**: Automatically added as `object:{objectId}`
- **Custom tags**: Any additional tags specified in configuration
- **Endpoint tags**: Tags extracted from label/tag properties

Example result: `['object:uuid-1234', 'bijlage', 'important', 'correspondence']`

## Placeholder Values

While files are being fetched asynchronously, the synchronization continues with placeholder values:

- **Single file**: `'file://fetching-async'`
- **Multiple files**: Array filled with `'file://fetching-async'` placeholders

These placeholders:
- Maintain the expected data structure
- Allow synchronization to continue without errors
- Can be identified and handled by downstream processes

## Error Handling

The asynchronous file fetching includes comprehensive error handling:

### Non-blocking Errors

File fetching errors are logged but don't affect the main synchronization:

```php
try {
    $result = $this->fetchFile(/* parameters */);
    $deferred->resolve($result);
} catch (Exception $e) {
    error_log('File fetch failed for endpoint {$endpoint}: ' . $e->getMessage());
    $deferred->reject($e);
}
```

### Error Scenarios

- **Source not found**: Logs error and continues with original data
- **Network failures**: Logged but don't block synchronization
- **File processing errors**: Isolated to individual file operations
- **Configuration errors**: Throw exceptions only for critical misconfigurations
- **Tag processing errors**: Invalid tags are filtered out, valid ones are preserved

## Performance Benefits

### Before Async Implementation

```
Synchronization Process:
├── Process Object 1
│   ├── Fetch File 1 (2s)
│   ├── Fetch File 2 (3s)
│   └── Continue processing (1s)
├── Process Object 2
│   ├── Fetch File 3 (2s)
│   └── Continue processing (1s)
└── Total: ~9 seconds
```

### After Async Implementation

```
Synchronization Process:
├── Process Object 1
│   ├── Start async fetch (File 1, File 2)
│   └── Continue processing (1s)
├── Process Object 2
│   ├── Start async fetch (File 3)
│   └── Continue processing (1s)
└── Total: ~2 seconds (files fetch in background)
```

## ReactPHP Integration

### Dependencies

The implementation uses several ReactPHP components:

```php
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use function React\Promise\resolve;
```

### Event Loop Usage

The ReactPHP event loop is used to schedule asynchronous operations:

```php
$loop = Loop::get();
$loop->futureTick(function() {
    // Async file fetching logic
});
```

### Promise Pattern

Each file fetch operation is wrapped in a ReactPHP promise:

```php
private function fetchFileAsync(/* parameters */): PromiseInterface
{
    $deferred = new Deferred();
    
    try {
        $result = $this->fetchFile(/* parameters */);
        $deferred->resolve($result);
    } catch (Exception $e) {
        $deferred->reject($e);
    }
    
    return $deferred->promise();
}
```

## Best Practices

### 1. Configuration

- Always specify appropriate tag configuration for your use case
- Use meaningful source configurations
- Set reasonable timeouts for file operations
- Consider using `allowedLabels` for security and data consistency

### 2. Tag Management

- Use consistent labeling conventions in your source data
- Consider the security implications of automatic tag acceptance
- Use descriptive labels that will help with file organization
- Combine endpoint tags with system tags for comprehensive categorization

### 3. Error Handling

- Monitor error logs for file fetching issues
- Implement retry mechanisms for critical files
- Use placeholder detection in downstream processes
- Handle cases where tag extraction fails gracefully

### 4. Performance

- Consider file sizes when designing synchronization rules
- Use appropriate batch sizes for large file sets
- Monitor system resources during heavy file operations
- Optimize tag processing for large numbers of files

### 5. Testing

- Test with various endpoint types and tag configurations
- Verify placeholder handling in target systems
- Test error scenarios and recovery
- Validate tag extraction with different data formats

## Migration Guide

### From Previous Tag System

If migrating from the previous tag system:

1. **Review existing configurations**: Check current `tags` configurations
2. **Choose new approach**: Decide between `useLabelsAsTags`, `allowedLabels`, or default behavior
3. **Update configurations**: Modify rule configurations accordingly
4. **Test thoroughly**: Verify tag extraction works with your data format
5. **Monitor results**: Check that files receive expected tags

### Configuration Migration Examples

#### Before (Legacy)
```json
{
  'fetch_file': {
    'source': 'source-id',
    'filePath': 'attachments',
    'tags': ['bijlage']
  }
}
```

#### After (Recommended)
```json
{
  'fetch_file': {
    'source': 'source-id',
    'filePath': 'attachments',
    'useLabelsAsTags': true
  }
}
```

## Monitoring and Debugging

### Log Messages

The system logs various events for monitoring:

```
Failed to find source for fetch file rule: [error message]
Async file fetching failed for rule [rule-id]: [error message]
File fetch failed for endpoint [endpoint]: [error message]
File fetch starting - Endpoint: [endpoint], ObjectId: [id], Filename: [filename]
File fetch completed successfully - Endpoint: [endpoint], ObjectId: [id]
```

### Debugging Tips

1. **Check source configuration**: Ensure the source ID exists and is accessible
2. **Verify file paths**: Confirm the filePath configuration points to valid data
3. **Validate tag structure**: Ensure endpoint data contains expected label/tag properties
4. **Monitor network**: Check for connectivity issues to external sources
5. **Review logs**: Look for patterns in error messages and tag processing
6. **Test endpoints**: Manually verify file endpoints are accessible
7. **Check tag configuration**: Verify tag filtering rules work as expected

## Future Enhancements

Potential improvements to the async file fetching system:

- **Progress tracking**: Monitor file fetch progress
- **Retry mechanisms**: Automatic retry for failed downloads
- **Batch optimization**: Intelligent batching based on file sizes
- **Caching**: Cache frequently accessed files
- **Compression**: Compress files during transfer
- **Parallel limits**: Configurable limits on concurrent downloads
- **Advanced tag processing**: Support for tag transformations and mappings
- **Tag validation**: Schema-based validation of extracted tags 