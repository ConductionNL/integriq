import { setActivePinia, createPinia } from 'pinia'

import { useJobStore } from './job'
import { Job, mockJob } from '../../entities/index.js'

describe('Job Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useJobStore()

		store.setItem(mockJob()[0])

		expect(store.item).toBeInstanceOf(Job)
		expect(store.item).toEqual(mockJob()[0])

		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useJobStore()

		store.setList(mockJob())

		expect(store.list).toHaveLength(mockJob().length)

		store.list.forEach((item: Job, index: number) => {
			expect(item).toBeInstanceOf(Job)
			expect(item).toEqual(mockJob()[index])
			expect(item.validate().success).toBe(true)
		})
	})
})
