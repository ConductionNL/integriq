import { setActivePinia, createPinia } from 'pinia'
import { useRuleStore } from './rule'
import { Rule, mockRule } from '../../entities/index.js'

describe('Rule Store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sets item correctly', () => {
		const store = useRuleStore()
		store.setItem(mockRule()[0])

		expect(store.item).toBeInstanceOf(Rule)
		expect(store.item).toEqual(mockRule()[0])
		expect(store.item.validate().success).toBe(true)
	})

	it('sets list correctly', () => {
		const store = useRuleStore()
		store.setList(mockRule())

		expect(store.list).toHaveLength(mockRule().length)

		store.list.forEach((item: Rule, index: number) => {
			expect(item).toBeInstanceOf(Rule)
			expect(item).toEqual(mockRule()[index])
			expect(item.validate().success).toBe(true)
		})
	})
})
