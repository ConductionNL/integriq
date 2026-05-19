# Flow Tokens

Flow tokens are a special data structure within OpenConnector to retain access to data throughout flows.
A flow token keeps track of original and amended data that is passed through a flow. This means that at any point in a flow
both the original and the amended data is available.

## Data structure
A flow in OpenConnector can pass through multiple steps:

- A request to an endpoint
- The request being amended by actions
- A response being given
- The response being amended by actions
- A synchronization being called
- The input of a synchronization being amended by actions
- The output of a synchronization being amended by actions

All these steps have corresponding parameters in a flow token:

- `requestOriginal`: the original data of the request
- `requestAmended`: the amended data of the request
- `responseOriginal`: the original data of the response
- `responseAmended`: the amended data of the response
- `syncInputOriginal`: the original input of the synchronization
- `syncInputAmended`: the amended input of the synchronization
- `syncOutputOriginal`: the original output of the synchronization
- `syncOutputAmended`: the amended output of the synchronization

The default steps in a flow will always resort to use the amended versions of the data, as these amendments are designed to help
the flow reaching its goal. However, it is possible to access the original versions from the json logic engines for rules (see [json logic](#json-logic), and
because the data is retained, it is possible to access the original versions from action processors if needed (see [future development]()) for more information).

All parameters are array parameters. They however do conform to 

### Request data
The parameters for request data are arrays of which the format is inspired by the Request class of PSR-7. That means these properties contain the following fields:

- `parameters`: the incoming parameters including path parameters from a request.
- `headers`: The incoming headers from the request
- `path`: The path on which the request is made
- `method`: The request method

### Response data
The parameters for response data are arrays of which the format is inspired by the Response class of PSR-7

- `data`: The response body of the object, given as array, can be transformed into json, yaml, xml at return time
- `headers`: The response headers to return
- `status`: The response status to return
- `cookies`: The cookies to return

### Synchronization data
The synchronization parameters at this point contain data arrays that can have two purposes:

- It can be the request body/parameters for running a request to the source
- It can be the object to write to an external source

In both cases this object is an array without predefined structure.

## JSON Logic
To use the parameters from the flow token object in your json logic conditions on rules or synchronizations, use the flowToken object that is passed to the JSON logic engine.

So, in order to use the method of the original request use `flowToken.requestOriginal.method`.

## Future development
At this moment all coded uses of the flow token will always use the amended version of data except for when it is specifically stated in the documentation that the original versions are used.

In future development it might become possible to use the original version of data when needed through the use of configuration. The default behaviour will always be to use amended data.
