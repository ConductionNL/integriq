import { setActivePinia, createPinia } from 'pinia'

import { useEventStore } from './event'
import { Event, mockEvent } from '../../entities/index.js'

describe('Event Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useEventStore()

		store.setItem(mockEvent()[0])

		expect(store.item).toBeInstanceOf(Event)
		expect(store.item).toEqual(mockEvent()[0])

		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useEventStore()

		store.setList(mockEvent())

		expect(store.list).toHaveLength(mockEvent().length)

		store.list.forEach((item: Event, index: number) => {
			expect(item).toBeInstanceOf(Event)
			expect(item).toEqual(mockEvent()[index])
			expect(item.validate().success).toBe(true)
		})
	})
})
