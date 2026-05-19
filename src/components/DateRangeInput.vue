<template>
	<div class="dateRangeInput">
		<div class="dateGroup">
			<div class="dateGroupLabel">
				{{ startLabel || t('openconnector', 'From') }}
			</div>
			<div class="row">
				<input
					v-model="startParts.day"
					class="numInput"
					type="text"
					inputmode="numeric"
					placeholder="DD"
					maxlength="2"
					:aria-label="t('openconnector', 'Start day')"
					@input="onNumericInput('start', 'day', 2)"
					@blur="finalize('start')"
					@keyup.enter="finalize('start')">
				<span class="sep">.</span>
				<input
					v-model="startParts.month"
					class="numInput"
					type="text"
					inputmode="numeric"
					placeholder="MM"
					maxlength="2"
					:aria-label="t('openconnector', 'Start month')"
					@input="onNumericInput('start', 'month', 2)"
					@blur="finalize('start')"
					@keyup.enter="finalize('start')">
				<span class="sep">.</span>
				<input
					v-model="startParts.year"
					class="numInput yearInput"
					type="text"
					inputmode="numeric"
					placeholder="YYYY"
					maxlength="4"
					:aria-label="t('openconnector', 'Start year')"
					@input="onNumericInput('start', 'year', 4)"
					@blur="finalize('start')"
					@keyup.enter="finalize('start')">
				<span class="timeSep">,</span>
				<input
					v-model="startParts.time"
					class="timeInput"
					type="time"
					step="60"
					:aria-label="t('openconnector', 'Start time')"
					@change="finalize('start')"
					@blur="finalize('start')"
					@keyup.enter="finalize('start')">
			</div>
		</div>
		<div class="dateGroup">
			<div class="dateGroupLabel">
				{{ endLabel || t('openconnector', 'To') }}
			</div>
			<div class="row">
				<input
					v-model="endParts.day"
					class="numInput"
					type="text"
					inputmode="numeric"
					placeholder="DD"
					maxlength="2"
					:aria-label="t('openconnector', 'End day')"
					@input="onNumericInput('end', 'day', 2)"
					@blur="finalize('end')"
					@keyup.enter="finalize('end')">
				<span class="sep">.</span>
				<input
					v-model="endParts.month"
					class="numInput"
					type="text"
					inputmode="numeric"
					placeholder="MM"
					maxlength="2"
					:aria-label="t('openconnector', 'End month')"
					@input="onNumericInput('end', 'month', 2)"
					@blur="finalize('end')"
					@keyup.enter="finalize('end')">
				<span class="sep">.</span>
				<input
					v-model="endParts.year"
					class="numInput yearInput"
					type="text"
					inputmode="numeric"
					placeholder="YYYY"
					maxlength="4"
					:aria-label="t('openconnector', 'End year')"
					@input="onNumericInput('end', 'year', 4)"
					@blur="finalize('end')"
					@keyup.enter="finalize('end')">
				<span class="timeSep">,</span>
				<input
					v-model="endParts.time"
					class="timeInput"
					type="time"
					step="60"
					:aria-label="t('openconnector', 'End time')"
					@change="finalize('end')"
					@blur="finalize('end')"
					@keyup.enter="finalize('end')">
			</div>
			<button class="calendarBtn" type="button" @click="toggleCalendar('end')">
				<CalendarIcon size="16" />
			</button>
		</div>
		<div v-if="openCalendar" class="popupAnchor" @mousedown.stop>
			<DateRangeCalendar
				:start="calendarStart"
				:end="calendarEnd"
				:max-start="normalizeMaxStart(maxStart)"
				@apply="onCalendarApply"
				@cancel="openCalendar = null" />
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import DateRangeCalendar from './DateRangeCalendar.vue'
import CalendarIcon from 'vue-material-design-icons/Calendar.vue'

export default {
	name: 'DateRangeInput',
	components: { DateRangeCalendar, CalendarIcon },
	props: {
		start: { type: String, default: null },
		end: { type: String, default: null },
		maxStart: { type: [Date, Number, String], default: () => new Date() },
		startLabel: { type: String, default: null },
		endLabel: { type: String, default: null },
	},
	emits: ['update:start', 'update:end', 'change'],
	data() {
		return {
			startParts: { day: '', month: '', year: '', time: '' },
			endParts: { day: '', month: '', year: '', time: '' },
			openCalendar: null, // 'start' | 'end' | null
		}
	},
	computed: {
		calendarStart() {
			return this.calendarDateFromParts('start')
		},
		calendarEnd() {
			return this.calendarDateFromParts('end')
		},
	},
	watch: {
		start() {
			this.syncPartsFromValues()
		},
		end() {
			this.syncPartsFromValues()
		},
	},
	created() {
		// initialize internal parts from incoming props
		this.syncPartsFromValues()
	},
	methods: {
		t,
		clamp(val, min, max) {
			const n = parseInt(val, 10)
			if (isNaN(n)) return String(min)
			return String(Math.min(max, Math.max(min, n)))
		},
		onNumericInput(which, field, maxLen) {
			// sanitize to digits and length only; defer full validation until finalize
			const parts = which === 'start' ? this.startParts : this.endParts
			const raw = (parts[field] || '').replace(/\D+/g, '').slice(0, maxLen)
			parts[field] = raw
		},
		onTimeInput(which) {
			const parts = which === 'start' ? this.startParts : this.endParts
			if (!parts.time) return
			const [hh = '00', mm = '00'] = String(parts.time).split(':')
			const hhC = this.pad2(Math.min(23, Math.max(0, parseInt(hh, 10) || 0)))
			const mmC = this.pad2(Math.min(59, Math.max(0, parseInt(mm, 10) || 0)))
			parts.time = `${hhC}:${mmC}`
		},
		correctField(which, field) {
			const parts = which === 'start' ? this.startParts : this.endParts
			if (!parts || typeof parts !== 'object') return
			if (field === 'month') {
				if (parts.month) parts.month = this.clamp(parts.month, 1, 12).padStart(2, '0')
				// adjust day if needed when month changes
				this.correctField(which, 'day')
			}
			if (field === 'year') {
				if (parts.year) parts.year = this.clamp(parts.year, 1, 9999).padStart(4, '0')
				// adjust day in case leap year/february
				this.correctField(which, 'day')
			}
			if (field === 'day') {
				if (!parts.day) return
				// if month/year not ready, cap to 31
				if (!parts.month || !parts.year) {
					parts.day = this.clamp(parts.day, 1, 31).padStart(2, '0')
					return
				}
				const year = parseInt(parts.year || '0', 10)
				const month = parseInt(parts.month || '0', 10)
				const maxDay = this.lastDayOfMonth(year, month)
				parts.day = this.clamp(parts.day, 1, maxDay).padStart(2, '0')
			}
		},
		syncPartsFromValues() {
			this.startParts = this.parseToParts(this.start)
			this.endParts = this.parseToParts(this.end)
		},
		parseToParts(value) {
			if (!value) return { day: '', month: '', year: '', time: '' }
			// Expect YYYY-MM-DDTHH:mm
			const [datePart, timePart] = String(value).split('T')
			if (!datePart) return { day: '', month: '', year: '', time: '' }
			const [y, m, d] = datePart.split('-')
			return {
				day: d ? d.padStart(2, '0').slice(0, 2) : '',
				month: m ? m.padStart(2, '0').slice(0, 2) : '',
				year: y ? y.slice(0, 4) : '',
				time: (timePart && timePart.slice(0, 5)) || '00:00',
			}
		},
		pad2(v) {
			return String(v).padStart(2, '0')
		},
		lastDayOfMonth(year, month) {
			// month: 1-12
			return new Date(Number(year), Number(month), 0).getDate()
		},
		isComplete(parts) {
			return !!(parts && parts.day && parts.month && parts.year && parts.time)
		},
		buildDate(parts) {
			if (!parts || !this.isComplete(parts)) return null
			let day = Number(parts.day)
			let month = Number(parts.month)
			const year = Number(parts.year)
			if (!year || !month || !day) return null
			// clamp again for safety
			month = Math.min(12, Math.max(1, month))
			const maxDay = this.lastDayOfMonth(year, month)
			day = Math.min(maxDay, Math.max(1, day))
			const [hh = '00', mm = '00'] = String(parts.time || '').split(':')
			const hour = Math.min(23, Math.max(0, parseInt(hh, 10) || 0))
			const minute = Math.min(59, Math.max(0, parseInt(mm, 10) || 0))
			// Use setFullYear to support years < 100 correctly
			const d = new Date(0)
			d.setHours(0, 0, 0, 0)
			d.setFullYear(year, month - 1, day)
			d.setHours(hour, minute, 0, 0)
			return d
		},
		formatLocalISO(d) {
			const y = d.getFullYear()
			const m = this.pad2(d.getMonth() + 1)
			const da = this.pad2(d.getDate())
			const hh = this.pad2(d.getHours())
			const mm = this.pad2(d.getMinutes())
			return `${y}-${m}-${da}T${hh}:${mm}`
		},
		setPartsFromDate(which, d) {
			const parts = which === 'start' ? this.startParts : this.endParts
			parts.year = String(d.getFullYear()).padStart(4, '0')
			parts.month = this.pad2(d.getMonth() + 1)
			parts.day = this.pad2(d.getDate())
			parts.time = `${this.pad2(d.getHours())}:${this.pad2(d.getMinutes())}`
		},
		calendarDateFromParts(which) {
			const parts = which === 'start' ? this.startParts : this.endParts
			if (!parts) return null
			const d = this.buildDate(parts)
			return d
		},
		toggleCalendar(which) {
			this.openCalendar = which
		},
		onCalendarApply({ start, end }) {
			// Always honor both dates if provided from calendar
			if (start) {
				// set start to 00:00
				const s = new Date(start)
				s.setHours(0, 0, 0, 0)
				this.setPartsFromDate('start', s)
			}
			if (end) {
				// set end to 23:59
				const e = new Date(end)
				e.setHours(23, 59, 0, 0)
				this.setPartsFromDate('end', e)
			}
			// Finalize in order: start first, then end
			this.finalize('start')
			this.finalize('end')
			this.openCalendar = null
		},
		finalize(which) {
			// Validate the side edited, then run cross-field constraints
			this.correctField(which, 'year')
			this.correctField(which, 'month')
			this.correctField(which, 'day')
			if (which === 'start' && !this.startParts.time) this.startParts.time = '00:00'
			if (which === 'end' && !this.endParts.time) this.endParts.time = '00:00'

			const maxStartDate = this.normalizeMaxStart(this.maxStart)
			let startDate = this.buildDate(this.startParts)
			let endDate = this.buildDate(this.endParts)

			// Start has higher priority: enforce maximum and then push end if needed
			if (startDate && maxStartDate && startDate > maxStartDate) {
				startDate = new Date(maxStartDate)
				this.setPartsFromDate('start', startDate)
			}

			if (startDate && endDate && endDate < startDate) {
				// end must be start + 1 day (24h)
				endDate = new Date(startDate.getTime() + 24 * 60 * 60 * 1000)
				this.setPartsFromDate('end', endDate)
			}

			// Emit updates after finalize
			startDate = this.buildDate(this.startParts)
			endDate = this.buildDate(this.endParts)
			if (startDate) {
				this.$emit('update:start', this.formatLocalISO(startDate))
			} else {
				this.$emit('update:start', null)
			}
			if (endDate) {
				this.$emit('update:end', this.formatLocalISO(endDate))
			} else {
				this.$emit('update:end', null)
			}
			this.$emit('change', { start: startDate, end: endDate })
		},
		normalizeMaxStart(input) {
			if (!input && input !== 0) return null
			if (input instanceof Date) return input
			if (typeof input === 'number') return new Date(input)
			if (typeof input === 'string') {
				const d = new Date(input)
				if (!isNaN(d.getTime())) return d
			}
			return new Date()
		},
	},
}
</script>

<style scoped>
.dateRangeInput {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.dateGroupLabel {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.row {
	display: flex;
	align-items: center;
	gap: 2px;
}

.numInput {
	width: 44px;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.yearInput { width: 64px; }

.timeInput {
	width: 110px;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.sep, .timeSep {
	color: var(--color-text-lighter);
}

.calendarBtn { border: 1px solid var(--color-border); background: var(--color-background-hover); border-radius: var(--border-radius); padding: 6px 8px; cursor: pointer; }

.popupAnchor { position: relative; margin-top: 8px; }
</style>
