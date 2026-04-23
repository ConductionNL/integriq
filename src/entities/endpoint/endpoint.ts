import { SafeParseReturnType, z } from 'zod'
import { TEndpoint } from './endpoint.types'
import getValidISOstring from '../../services/getValidISOstring'
import ReadonlyBaseClass from '../ReadonlyBaseClass.js'
import _ from 'lodash'

/**
 * Endpoint entity class that represents a system endpoint
 * Implements TEndpoint interface and extends ReadonlyBaseClass
 *
 * @class Endpoint
 * @augments {ReadonlyBaseClass}
 * @implements {TEndpoint}
 */
export class Endpoint extends ReadonlyBaseClass implements TEndpoint {

	public readonly id: number
	public readonly uuid: string
	public readonly name: string
	public readonly description: string
	public readonly reference: string
	public readonly version: string
	public readonly endpoint: string
	public readonly endpointArray: string[]
	public readonly endpointRegex: string
	public readonly method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH'
	public readonly targetType: string
	public readonly targetId: string
	public readonly slug: string
	public readonly configurations: string[]
	public readonly created: string
	public readonly updated: string
	public readonly rules: string[] // Array of rule IDs associated with this endpoint

	constructor(endpoint: TEndpoint) {
		const processedEndpoint: TEndpoint = {
			id: endpoint.id || null,
			uuid: endpoint.uuid || '',
			name: endpoint.name || '',
			description: endpoint.description || '',
			reference: endpoint.reference || '',
			version: endpoint.version || '0.0.0',
			endpoint: endpoint.endpoint || '',
			endpointArray: endpoint.endpointArray ?? [],
			endpointRegex: endpoint.endpointRegex || '',
			method: endpoint.method || 'GET',
			targetType: endpoint.targetType || '',
			targetId: endpoint.targetId || '',
			slug: endpoint.slug || '',
			configurations: endpoint.configurations ?? [],
			created: getValidISOstring(endpoint.created) ?? '',
			updated: getValidISOstring(endpoint.updated) ?? '',
			rules: endpoint.rules ?? [], // Initialize rules array with empty array if not provided
		}

		super(processedEndpoint)
	}

	public cloneRaw(): TEndpoint {
		return _.cloneDeep(this)
	}

	/**
	 * Validates the endpoint data before posting
	 * IDs are optional, meaning the id property is not required to exist on the posted content
	 * NOT that it can be empty or '0'
	 *
	 * @return {SafeParseReturnType<TEndpoint, unknown>} Result of validation
	 */
	public validate(): SafeParseReturnType<TEndpoint, unknown> {
		const schema = z.object({
			id: z.number().or(z.null()),
			uuid: z.string().uuid().or(z.literal('')).optional(),
			name: z.string().max(255),
			description: z.string(),
			reference: z.string(),
			version: z.string(),
			endpoint: z.string(),
			endpointArray: z.string().array(),
			endpointRegex: z.string(),
			method: z.enum(['GET', 'POST', 'PUT', 'DELETE', 'PATCH']),
			targetType: z.string(),
			targetId: z.string(),
			slug: z.string().optional(),
			configurations: z.string().array(),
			created: z.string().datetime().or(z.literal('')).optional(),
			updated: z.string().datetime().or(z.literal('')).optional(),
			rules: z.string().array(), // Validate rules as array of strings (rule IDs)
		})

		return schema.safeParse({ ...this })
	}

}
