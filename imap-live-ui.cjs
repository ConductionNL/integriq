// imap-live UI proof driver. Own headless chromium. usage: node imap-live-ui.cjs <phase>
const { chromium } = require('@playwright/test')
const OUT = process.env.OUT
const BASE = 'http://127.0.0.1:8099'
const phase = process.argv[2]
const log = (...a) => console.log(...a)
;(async () => {
	const browser = await chromium.launch()
	const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } })
	page.on('response', async (r) => {
		const u = r.url()
		if (/dso_activity_mapping|dso-activities|dso_verzoek/.test(u) && r.request().method() !== 'OPTIONS') {
			let body = ''
			try { body = (await r.text()).slice(0, 600) } catch (e) {}
			log(`NET ${r.request().method()} ${u.replace(BASE, '')} -> ${r.status()}`)
			const pd = r.request().postData()
			if (pd) log(`    request: ${pd.slice(0, 600)}`)
			log(`    response: ${body}`)
		}
	})
	page.on('pageerror', (e) => log('PAGEERROR', e.message))
	await page.goto(BASE + '/index.php/login')
	await page.fill('#user', 'admin')
	await page.fill('#password', 'admin')
	await page.click('button[type=submit]')
	await page.waitForURL(/apps|dashboard/, { timeout: 30000 })
	await page.goto(BASE + '/index.php/settings/admin/integriq', { waitUntil: 'domcontentloaded' })
	const section = page.getByTestId('admin-dso-activities-section')
	await section.waitFor({ timeout: 30000 })
	await page.waitForTimeout(2500)
	log('SECTION VISIBLE:', await section.isVisible())
	const dialog = page.getByTestId('dso-activity-dialog')
	const save = async () => { await dialog.getByTestId('dso-activity-save').click(); await page.waitForTimeout(2500) }
	const dialogError = async () => (await dialog.getByTestId('dso-activity-error').count()) ? await dialog.getByTestId('dso-activity-error').innerText() : '(no error element)'
	if (phase === 'step1') {
		log('EMPTY STATE:', (await section.getByTestId('admin-dso-activities-empty').count()) ? await section.getByTestId('admin-dso-activities-empty').innerText() : '(none)')
		await section.scrollIntoViewIfNeeded()
		await page.screenshot({ path: `${OUT}/step1-section-empty.png`, fullPage: true })
		// a: valid row through the dialog
		await section.getByTestId('admin-dso-activities-add').click()
		await dialog.waitFor()
		await dialog.getByTestId('dso-activity-name').fill('Live UI: bouwen')
		await dialog.getByTestId('dso-activity-imow-id').fill('nl.imow-gm0000.activiteit.LiveUi')
		await dialog.getByTestId('dso-activity-case-type-reference').first().fill('ZT-LIVE-UI')
		await save()
		log('DIALOG OPEN AFTER SAVE:', await dialog.isVisible())
		const rows = section.getByTestId('admin-dso-activities-row')
		log('ROWS:', await rows.count()); for (const t of await rows.allInnerTexts()) log('  ROW:', t.replace(/\s+/g, ' '))
		await page.screenshot({ path: `${OUT}/step1-row-saved.png`, fullPage: true })
		// b: neither imowId nor activityId
		await section.getByTestId('admin-dso-activities-add').click()
		await dialog.waitFor()
		await dialog.getByTestId('dso-activity-name').fill('Live UI: geen id')
		await dialog.getByTestId('dso-activity-case-type-reference').first().fill('ZT-X')
		await save()
		log('NO-ID: dialog open:', await dialog.isVisible(), 'error:', await dialogError())
		await page.screenshot({ path: `${OUT}/step1-refused-no-id.png`, fullPage: true })
		// c: duplicate active imowId
		await dialog.getByTestId('dso-activity-imow-id').fill('nl.imow-gm0000.activiteit.LiveUi')
		await save()
		log('DUP: dialog open:', await dialog.isVisible(), 'error:', await dialogError())
		await page.screenshot({ path: `${OUT}/step1-refused-dup.png`, fullPage: true })
		log('ROWS AFTER:', await rows.count())
	}
	if (phase === 'unmapped' || phase === 'map') {
		const un = page.getByTestId('admin-dso-unmapped-row')
		log('UNMAPPED ROWS:', await un.count()); for (const t of await un.allInnerTexts()) log('  UNMAPPED:', t.replace(/\s+/g, ' '))
		await page.screenshot({ path: `${OUT}/${phase}-unmapped.png`, fullPage: true })
	}
	if (phase === 'map') {
		const row = page.getByTestId('admin-dso-unmapped-row').filter({ hasText: 'LiveMilieu' })
		await row.getByTestId('admin-dso-unmapped-map').click()
		await dialog.waitFor()
		for (const id of ['dso-activity-imow-id', 'dso-activity-activity-id', 'dso-activity-name']) log(`PREFILL ${id}:`, await dialog.getByTestId(id).inputValue())
		await dialog.getByTestId('dso-activity-case-type-reference').first().fill('ZT-LIVE-MILIEU')
		await dialog.getByTestId('dso-activity-case-type-department').first().fill('Afdeling Milieu')
		await dialog.getByTestId('dso-activity-add-case-type').click()
		await dialog.getByTestId('dso-activity-case-type-reference').nth(1).fill('ZT-LIVE-BOUWEN')
		await dialog.getByTestId('dso-activity-case-type-department').nth(1).fill('Afdeling Bouwen')
		await page.screenshot({ path: `${OUT}/map-dialog-filled.png`, fullPage: true })
		await save()
		log('DIALOG OPEN AFTER SAVE:', await dialog.isVisible(), (await dialog.isVisible()) ? await dialogError() : '')
		const rows = page.getByTestId('admin-dso-activities-row')
		for (const t of await rows.allInnerTexts()) log('  ROW:', t.replace(/\s+/g, ' '))
		const un = page.getByTestId('admin-dso-unmapped-row')
		for (const t of await un.allInnerTexts()) log('  UNMAPPED NOW:', t.replace(/\s+/g, ' '))
		await page.screenshot({ path: `${OUT}/map-saved.png`, fullPage: true })
	}
	await browser.close()
})().catch((e) => { console.error('FAILED', e); process.exit(1) })
