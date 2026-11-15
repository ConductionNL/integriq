<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  JobScheduleFields — the schedule section of the Jobs form. A job runs every
  `interval` seconds, or on a calendar: weekdays, an optional day-of-month
  rule, times of day, a time zone and dates to skip. The preview lists the
  next five run times from POST /api/jobs/schedule-preview, computed by the
  same calculator the run loop uses (lib/Service/JobCalendarSchedule.php).

  `schedule` is a nested object, which `fieldsFromSchema` drops, so
  JobFormFields mounts this section itself and it writes the whole object back
  through `updateField('schedule', …)`.

  @spec openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-job-form-previews-the-next-five-run-times-req-jcal-003
-->
<template>
	<fieldset class="cn-job-schedule">
		<legend class="cn-job-schedule__legend">
			{{ t('integriq', 'Schedule') }}
		</legend>

		<div class="cn-job-schedule__types" role="radiogroup" :aria-label="t('integriq', 'Schedule')">
			<NcCheckboxRadioSwitch
				:modelValue="type"
				value="interval"
				name="cn-job-schedule-type"
				type="radio"
				@update:modelValue="setType">
				{{ t('integriq', 'Every interval') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				:modelValue="type"
				value="calendar"
				name="cn-job-schedule-type"
				type="radio"
				@update:modelValue="setType">
				{{ t('integriq', 'On a calendar') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template v-if="type === 'calendar'">
			<div class="cn-job-schedule__stack" role="group" :aria-label="t('integriq', 'Weekdays')">
				<span class="cn-job-schedule__label">{{ t('integriq', 'Weekdays') }}</span>
				<div class="cn-job-schedule__weekdays">
					<NcCheckboxRadioSwitch
						v-for="day in weekdayNumbers"
						:key="day"
						:modelValue="(schedule.weekdays || []).includes(day)"
						@update:modelValue="(on) => emit(withWeekday(schedule, day, on))">
						{{ weekdayLabel(day) }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>

			<div class="cn-job-schedule__stack">
				<label for="cn-job-schedule-day" class="cn-job-schedule__label">
					{{ t('integriq', 'Day of month') }}
				</label>
				<NcSelect
					inputId="cn-job-schedule-day"
					:inputLabel="t('integriq', 'Day of month')"
					:aria-label-combobox="t('integriq', 'Day of month')"
					:modelValue="selectedDayRule"
					:options="dayRuleOptions"
					:clearable="true"
					:placeholder="t('integriq', 'Any day')"
					@update:modelValue="(option) => emit(withDayOfMonth(schedule, option ? option.id : null))" />
			</div>

			<NcTextField
				:label="t('integriq', 'Times of day')"
				:modelValue="joinList(schedule.times)"
				:helperText="t('integriq', 'HH:MM, separated by commas. Jobs are picked up every five minutes, so a 07:00 run starts between 07:00 and 07:05.')"
				@update:modelValue="(text) => emit({ ...schedule, times: splitList(text) })" />

			<NcTextField
				:label="t('integriq', 'Time zone')"
				:modelValue="schedule.timeZone || ''"
				:helperText="t('integriq', 'An IANA time zone such as Europe/Amsterdam.')"
				@update:modelValue="(text) => emit({ ...schedule, timeZone: String(text).trim() })" />

			<NcTextField
				:label="t('integriq', 'Dates to skip')"
				:modelValue="joinList(schedule.skipDates)"
				:helperText="t('integriq', 'YYYY-MM-DD, separated by commas. Skipped dates are not working days either.')"
				@update:modelValue="(text) => emit({ ...schedule, skipDates: splitList(text) })" />

			<div class="cn-job-schedule__stack" aria-live="polite">
				<span class="cn-job-schedule__label">{{ t('integriq', 'Next five runs') }}</span>
				<p v-if="previewError" class="cn-job-schedule__error">
					{{ previewError }}
				</p>
				<ul v-else-if="previewRuns.length > 0" class="cn-job-schedule__preview">
					<li v-for="run in previewRuns" :key="run">
						{{ formatRun(run) }}
					</li>
				</ul>
				<p v-else class="cn-job-schedule__hint">
					{{ previewLoading ? t('integriq', 'Computing run times…') : t('integriq', 'This calendar has no run time.') }}
				</p>
			</div>
		</template>
	</fieldset>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	canPreview,
	DAY_RULES,
	joinList,
	scheduleType,
	splitList,
	WEEKDAY_NUMBERS,
	withDayOfMonth,
	withType,
	withWeekday,
} from './jobSchedule.js'

/**
 * The browser's time zone, for a new calendar.
 *
 * @return {string|undefined} The IANA name.
 */
function browserTimeZone() {
	try {
		return Intl.DateTimeFormat().resolvedOptions().timeZone
	} catch {
		return undefined
	}
}

export default {
	name: 'JobScheduleFields',

	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The job's stored `schedule`, absent for an interval job. */
		schedule: { type: Object, default: null },
		/** Mutator forwarded from CnFormDialog. Signature: (key, value). */
		updateField: { type: Function, required: true },
	},

	data() {
		return {
			weekdayNumbers: WEEKDAY_NUMBERS,
			previewRuns: [],
			previewError: '',
			previewLoading: false,
			previewTimer: null,
		}
	},

	computed: {
		type() {
			return scheduleType(this.schedule)
		},

		dayRuleOptions() {
			const labels = {
				first: t('integriq', 'First day of the month'),
				last: t('integriq', 'Last day of the month'),
				firstWorkingDay: t('integriq', 'First working day of the month'),
				lastWorkingDay: t('integriq', 'Last working day of the month'),
			}
			const named = DAY_RULES.map((rule) => ({ id: rule, label: labels[rule] }))
			const numbered = Array.from({ length: 31 }, (unused, index) => ({
				id: index + 1,
				label: t('integriq', 'Day {day}', { day: index + 1 }),
			}))
			return [...named, ...numbered]
		},

		selectedDayRule() {
			const rule = this.schedule ? this.schedule.dayOfMonth : undefined
			return this.dayRuleOptions.find((option) => option.id === rule) || null
		},
	},

	watch: {
		schedule: {
			handler() {
				this.schedulePreview()
			},

			deep: true,
			immediate: true,
		},
	},

	beforeUnmount() {
		clearTimeout(this.previewTimer)
	},

	methods: {
		t,
		joinList,
		splitList,
		withDayOfMonth,
		withWeekday,

		emit(schedule) {
			this.updateField('schedule', schedule)
		},

		setType(type) {
			this.emit(withType(this.schedule, type, browserTimeZone()))
		},

		weekdayLabel(day) {
			// 5 January 2026 is a Monday, so day N falls on the Nth of the week.
			return new Date(Date.UTC(2026, 0, 4 + day)).toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' })
		},

		formatRun(run) {
			return new Date(run).toLocaleString(undefined, {
				weekday: 'long',
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
				timeZone: this.schedule.timeZone,
			})
		},

		schedulePreview() {
			clearTimeout(this.previewTimer)
			if (!canPreview(this.schedule)) {
				this.previewRuns = []
				this.previewError = ''
				return
			}
			this.previewTimer = setTimeout(() => this.fetchPreview(), 300)
		},

		async fetchPreview() {
			this.previewLoading = true
			try {
				const response = await axios.post(
					generateUrl('/apps/integriq/api/jobs/schedule-preview'),
					{ schedule: this.schedule },
				)
				this.previewRuns = response.data.runs || []
				this.previewError = ''
			} catch (error) {
				this.previewRuns = []
				this.previewError = error?.response?.data?.error || t('integriq', 'The run times could not be computed.')
			} finally {
				this.previewLoading = false
			}
		},
	},
}
</script>

<style scoped>
.cn-job-schedule {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 0;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.cn-job-schedule__legend,
.cn-job-schedule__label {
	font-weight: bold;
}

.cn-job-schedule__types,
.cn-job-schedule__weekdays {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
}

.cn-job-schedule__stack {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.cn-job-schedule__preview {
	margin: 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.cn-job-schedule__hint {
	color: var(--color-text-maxcontrast);
}

.cn-job-schedule__error {
	color: var(--color-error-text);
}
</style>
