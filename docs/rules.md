# Rules

Rules are components that can be associated with endpoints to add additional functionality. Based on the current codebase, rules are stored as string IDs and can be attached to multiple endpoints.

## Adding Rules to Endpoints

Rules can be added to endpoints through the AddEndpointRule modal component. The process works as follows:

1. Select a rule from the available rule options dropdown
2. The rule ID is added to the endpoint's `rules` array property
3. The endpoint is updated with the new rule association
4. On success, the modal closes automatically after 2 seconds

## Rule Properties

Rules have several key properties that define their behavior:

- uuid: A unique identifier string for the rule
- name: The display name of the rule
- description: A detailed description of the rule's purpose
- action: Specifies when the rule triggers (create, read, update, delete)
- timing: Controls if rule runs 'before' or 'after' the action (defaults to 'before')
- conditions: JSON Logic format conditions that determine when rule applies
- type: The rule type (mapping, error, script, synchronization, authentication, download, upload, locking)
- configuration: Type-specific configuration stored as JSON
- order: Integer determining execution order when multiple rules exist

The properties work together to define:

1. When the rule executes (action + timing)
2. Under what conditions it runs (conditions)
3. What functionality it provides (type + configuration)
4. The sequence of execution (order)

Rules are stored in a structured format but referenced by endpoints using their UUID strings in an array format.

## Rule Implementation

The current implementation shows:

- Rules are managed through a dedicated API endpoint at `/api/rules`
- Rules can be retrieved and managed through the Rules resource controller
- Rule IDs are stored and validated as strings within endpoints
- The endpoint entity ensures rules are always stored as an array

## Rule Conditions

The conditions will determine if functionality of the rule will be exexcuted or applied. 
These conditions are validated against data with with [json logic](https://jsonlogic.com).
You have access to data from:
- The request or response data in `body`.
- The request parameters in `parameters`.
- If the `type` of the Rule is `before` the headers will be in `headers` else if the `type` is `after` the headers can be found in `requestHeaders`.
- The current path of the endpoint in `path`.
- The method of the request in `method`.
- The current date in `utility.currentDate`

All other utility values should be through code to `utility`.

In your json conditions you can test against all of the above mentioned values. 

For example you only want to execute a Rule if `name` is set in your request body and is not empty and you POST a object to an endpoint with {"name": "John"} your condition would look like:
`{
  "if": [
    { "var": "body.name" },
    true,
    false
  ]
}`

What will result in `true` and so the Rule is valid and will be executed/applied.

## Rule Types

### Authentication Rules

Authentication rules control access to endpoints by validating user credentials and permissions. Configuration options include:

- type: The authentication method to use
  - basic: Basic HTTP authentication
  - jwt: JSON Web Token authentication
  - jwt-zgw: ZGW-specific JWT authentication
  - oauth: OAuth 2.0 authentication
  - apikey: Authentication using Api keys
- users: Array of specific users allowed to access the endpoint
- groups: Array of user groups allowed to access the endpoint
- keys (when type is apikey): A key-value list with allowed api keys as keys, and the user which is authenticated using the api key as value.

When the authentication rule is not validated, a response is given with 401 (unauthorized) status code with error and reason. The Api Key version will return Invalid API key if the api key is not know, and also when the user that is connected to the api key is not known in Nextcloud.

### Synchronization Rules

Synchronization rules can be used to run a synchronization in case an endpoint is requested. This synchronization has to be present in the database and defined in the rule.

The synchronization rules take the following configuration options:

- synchronization (required): The synchronization to run.
- isTest (optional): if set to true, always treat the synchronization as if it is in test mode.
- force (optional): if set to true, always force objects to be overwritten from the synchronization.
- mergeResultToKey (optional): if set , merges the synchronization result to a key in the synchronized object. IF `#` is configured, will the result with the synchronizaiton object. Not compatible with `overwriteObjectWithResult` config option
- overwriteObjectWithResult (optional): if set to true, overwrites result with synchronization result instead of synchronization object. Not compatible with `mergeResultToKey` config option
- retainResponse (optional): Retain the original response of the request before running any rule. Overrides mergeResultToKey and overwriteObjectWithResult.

The isTest and force options can also be overridden from the endpoint by setting the corresponding fields on the request body.

### Download Rules

Download rules handle file access and retrieval. Configuration includes:

- filenamePosition: Specifies the position of the filename in the URL path or object body
- Automatic validation of user access rights to requested files

### Upload Rules

Upload rules manage file upload functionality and restrictions. Configuration includes:

- path: The target directory path for uploaded files
- allowedTypes: Comma-separated list of allowed file extensions (e.g., jpg,png,pdf)
- maxSize: Maximum allowed file size in megabytes

#### Partial upload rules

Partial upload rules manage file uploads in multiple parts, allowing for uploads of larger files.
Partial uploads require the created object to contain the file size of the file to be created.

The functionality is split into two rule types:

- `fileparts_create`
- `filepart_upload`

The `fileparts_create` type creates the partial file upload from the endpoint. These partial files will be nested into
the response object, and will be stored with their ids in the saved object. The fileparts will be stored in objects according to a separate schema.

The default schema of these fileparts is:

```json
{
    "id": "baed4312-c6b8-48bc-a1b3-c3536d9653be", // The id of the file part object.
    "order": 1, // The order number of the file part, starting at 1
    "size": 297809, // The size in bytes of the file part that is/will be uploaded
    "data": "..." // The data uploaded. This will only exist after uploading, until the data is written into a partial file.
}
```

The `fileparts_create` rule takes the following configuration:

- `sizeLocation` (required): The location in the created object containing the size of the complete file.
- `schemaId` (required): The schema to store the file part in.
- `filenameLocation` (optional): The location in the created object that contains the filename of the file to be created. This defaults to `filename` if it is not set.
- `filePartLocation` (optional): The location in the created object the created fileparts will be written to. Defaults to `fileParts`
- `mappingId` (optional): If the resulting filePart objects have to be mapped to a specific format, the id of the mapping that will map the file parts to the desired format.

The `filepart_upload` type will upload the data in the file part that is uploaded into a temporary file, and
once all fileparts have been uploaded, it will reconcile the partial uploads into one file (deleting the temporary files,
and if no additional data has been put into the folder where the parts are stored, the folder).

The `filepart_upload` rule takes the following configuration:

- `mappingId` (optional): If the file parts are in a specific format, the mapping to map the fileparts to the default format. (Usually this means that this is the inverse mapping of `mappingId` in the corresponding `fileparts_create` rule).

### Save object Rules

Save object rules allow saving a object on the moment of configuration of the specified Rule

Will only work if `register` and `schema` are configured and if a object can be passed.

Optionally a `mapping` can be configured to execute

### Locking Rules

Locking rules provide exclusive access control for resources. Configuration includes:

- action: The locking operation to perform
  - lock: Lock a resource for exclusive access
  - unlock: Release a previously locked resource
- timeout: Duration in minutes before the lock automatically expires

### Audit trail rules

The type `audit_trail` gives the possibility to access the audit trails of an object. If the endpoint contains the path parameter `audittrail-id`, individual audit trail lines can be accessed. These rules do not take extra configuration at this time.


### Extend inputs

The type `extend_input` can be used to access pre-existing objects in the database when business logic has to check whether or not to create a new object. The rule takes the configuration `properties` containing a dot-array containing the properties in the input that will be extended if possible.
The extended parameters will be included in the field extendedParameters in the data array for use by other actions.

If an extended field also has fields that should be extended, then the property `extends` should be used with in it also a dot array of which properties should be extended in extended properties.

#### Extend external inputs
In addition to the `extend_input` rule type, there is also an `extend_external_input` rule type, which does virtually the same, but fetches the input that should be extended from an external source rather than from an internal source. This rule takes the following configuration parameters:

- `properties`: The list of properties that should be extended. This contains a number of values
  - `schema`: The schema that the object that is found should adhere to
  - `property`: The name of the property that should be extended.

## Tags in Rules

For the fetch file and write file rule you can add tags to these files. This can be useful when wanting to make a difference between files for further use.

When using this in combination with OpenRegister, fetching objects that have these files will result in these tags also being readable.

![Rule tags](rule-tags.png)

If 'add' or 'test' are found in the label of the mapped file, it will use that to tag that file. For example you have a object like
`{"test": {"filename": "afile.pdf", "endpoint": "/file-endpoint", "label": "test"} } ` and test is configured as a tag, it will use that as a tag for that file.

By default files are tagged with a object:id tag.


## Rule Validation

When adding rules to an endpoint:

- The rules array is initialized if it doesn't exist
- Existing rule IDs are converted to strings
- New rule IDs are validated before being added
- The endpoint is revalidated after rule changes

## Error Handling

The rule addition process includes:

- Validation that a rule is selected before saving
- Error catching and display if the save fails
- Loading state management during the save process
- Success/error message display to the user
