/**
 * Generate l10n/*.js from l10n/*.json for Nextcloud Util::addTranslations().
 * NC loads sharegate/l10n/{lang}.js only — JSON alone does not reach the browser.
 */
const fs = require('fs')
const path = require('path')

const root = path.join(__dirname, '..')
const l10nDir = path.join(root, 'l10n')

function jsString(value) {
	return JSON.stringify(String(value))
}

function createJs(appId, langCode, bundle) {
	const lines = ['OC.L10N.register(', `\t${jsString(appId)},`, '\t{']
	const entries = Object.entries(bundle.translations || {})
	entries.forEach(([key, value], index) => {
		const comma = index < entries.length - 1 ? ',' : ''
		lines.push(`\t\t${jsString(key)} : ${jsString(value)}${comma}`)
	})
	const plural = bundle.pluralForm || 'nplurals=2; plural=(n != 1);'
	lines.push('\t},', `\t${jsString(plural)});`, '')
	return lines.join('\n')
}

const jsonFiles = fs.readdirSync(l10nDir).filter((name) => name.endsWith('.json'))
if (jsonFiles.length === 0) {
	console.error('No l10n/*.json files found')
	process.exit(1)
}

const appId = 'sharegate'
for (const file of jsonFiles) {
	const langCode = file.replace(/\.json$/, '')
	const jsonPath = path.join(l10nDir, file)
	const bundle = JSON.parse(fs.readFileSync(jsonPath, 'utf8'))
	if (!bundle.translations || typeof bundle.translations !== 'object') {
		console.error(`Skip ${file}: missing translations object`)
		continue
	}
	const jsPath = path.join(l10nDir, `${langCode}.js`)
	fs.writeFileSync(jsPath, createJs(appId, langCode, bundle), 'utf8')
	console.log(`Wrote l10n/${langCode}.js (${Object.keys(bundle.translations).length} strings)`)
}
