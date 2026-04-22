import { setActivePinia, createPinia } from 'pinia'

import { useConsumerStore } from './consumer'
import { Consumer, mockConsumer } from '../../entities/index.js'

describe('Consumer Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useConsumerStore()

		store.setItem(mockConsumer()[0])

		expect(store.item).toBeInstanceOf(Consumer)
		expect(store.item).toEqual(mockConsumer()[0])

		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useConsumerStore()

		store.setList(mockConsumer())

		expect(store.list).toHaveLength(mockConsumer().length)

		store.list.forEach((item: Consumer, index: number) => {
			expect(item).toBeInstanceOf(Consumer)
			expect(item).toEqual(mockConsumer()[index])
			expect(item.validate().success).toBe(true)
		})
	})
})
