import { setActivePinia, createPinia } from 'pinia'

import { useSourceStore } from './source.js'
import { Source, mockSource } from '../../entities/index.js'

describe('Source Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useSourceStore()

		store.setItem(mockSource()[0])

		expect(store.item).toBeInstanceOf(Source)
		expect(store.item).toEqual(mockSource()[0])

		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useSourceStore()

		store.setList(mockSource())

		expect(store.list).toHaveLength(mockSource().length)

		store.list.forEach((item: Source, index: number) => {
			expect(item).toBeInstanceOf(Source)
			expect(item).toEqual(mockSource()[index])
			expect(item.validate().success).toBe(true)
		})
	})
})
