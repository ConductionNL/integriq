import { setActivePinia, createPinia } from 'pinia'

import { useEndpointStore } from './endpoints.js'
import { Endpoint, mockEndpoint } from '../../entities/index.js'

describe('Endpoint Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useEndpointStore()

		store.setItem(mockEndpoint()[0])

		expect(store.item).toBeInstanceOf(Endpoint)
		expect(store.item).toEqual(mockEndpoint()[0])

		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useEndpointStore()

		store.setList(mockEndpoint())

		expect(store.list).toHaveLength(mockEndpoint().length)

		store.list.forEach((item: Endpoint, index: number) => {
			expect(item).toBeInstanceOf(Endpoint)
			expect(item).toEqual(mockEndpoint()[index])
			expect(item.validate().success).toBe(true)
		})
	})
})
