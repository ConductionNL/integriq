export type TEndpoint = {
    id?: number
    uuid: string
    name: string
    description: string
    reference: string
    version: string
    endpoint: string
    endpointArray: string[]
    endpointRegex: string
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH'
    targetType: string
    targetId: string
    slug: string
    configurations: string[]
    created: string
    updated: string
    rules: string[] // Array of rule IDs associated with this endpoint
}
