# Synchronization Actions

## Concept

Just as with Endpoints, synchronizations can trigger additional actions (at this moment called rules).
These can change the content of an object, but also trigger additional synchronizations.

These actions can be created by creating a rule (an action) and adding it by id to the property
actions in the Synchronization.

## Synchronizing files
In order to fetch a file from an external source and store it in the Nextcloud Filesystem in a way that OpenRegister can 
connect it to an object, there are two predefined actions:

- `fetch_file`: This action downloads the file and substitutes the base encoded content into the variable that contained the file url
- `write_file`: This action takes a file's content in base encoding and the filename (otherwise it will use a default filename), and writes it to the filesystem.

### Fetch file

This action should be run on timing `after` (when the object has been stored).
The action takes the following parameters in the `configuration` property:

- `source` (required): The id of the source where the file can be downloaded
- `filePath` (required): The dot path of the location in the input object that contains the file url or file path.
- `method` (optional): The HTTP method that should be used to fetch the file. Defaults to GET
- `sourceConfiguration` (optional): Additional configuration for the source that only holds for fetching files.

When properly configured this action will download the file from the given source and substitute the base64 encoded content in the returned object.
It is preferred to run this action in combination with `write_file` immediately after, so the file contents are properly stored in the Nextcloud file system instead of written to a database.

### Write file

This action should be run on timing `after`, and if combined with `fetch_file` it should be run in order after `fetch_file`.
The action takes the followin parameters in the `configuration` property:

- `filePath` (required): The dot path of the location in the input object that contains the base64 encoded content of the file.
- `fileNamePath` (required): The dot path of the location in the input object that contains the filename

This will write the file to the nextcloud filesystem in the folder that belongs to the written object, and substitutes the file content in the returned data with the path of the object in the Nextcloud File System.
