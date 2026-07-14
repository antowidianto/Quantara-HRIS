import crypto from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'

const root = process.cwd()
const sourceRoot = path.join(root, 'src')
const baselinePath = path.join(sourceRoot, 'locales', 'hardcoded-visible-baseline.json')
const update = process.argv.includes('--update-baseline')

function vueFiles(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(directory, entry.name)
    if (entry.isDirectory()) return vueFiles(target)
    return entry.isFile() && entry.name.endsWith('.vue') ? [target] : []
  })
}

const normalize = (value) => value.replace(/\s+/g, ' ').trim()
function meaningful(value) {
  const text = normalize(value)
  return text.length > 1 && /[A-Za-zÀ-ÿ]/.test(text) && !text.includes('{{')
    && !text.startsWith('http') && !/^[A-Z0-9_./:-]+$/.test(text)
}
const lineAt = (source, offset) => source.slice(0, offset).split('\n').length

const findings = []
for (const file of vueFiles(sourceRoot)) {
  const source = fs.readFileSync(file, 'utf8')
  const templateMatch = source.match(/<template(?:\s[^>]*)?>([\s\S]*?)<\/template>/i)
  if (!templateMatch) continue
  const template = templateMatch[1]
  const templateOffset = source.indexOf(template)
  const relativeFile = path.relative(root, file).replaceAll('\\', '/')
  const rules = [
    ['attribute', /\b(?:placeholder|title|aria-label|alt)\s*=\s*["']([^"']+)["']/gi],
    ['text', />\s*([^<>{}\n]*[A-Za-zÀ-ÿ][^<>{}\n]*)\s*</g],
  ]
  for (const [kind, pattern] of rules) {
    for (const match of template.matchAll(pattern)) {
      const text = normalize(match[1])
      if (!meaningful(text)) continue
      findings.push({ file: relativeFile, line: lineAt(source, templateOffset + (match.index ?? 0)), kind, text })
    }
  }
}

const fingerprint = (finding) => crypto.createHash('sha256')
  .update([finding.file, finding.kind, finding.text].join('|')).digest('hex')
const counts = {}
for (const finding of findings) {
  const key = fingerprint(finding)
  counts[key] = (counts[key] ?? 0) + 1
}
if (update) {
  fs.writeFileSync(baselinePath, `${JSON.stringify({
    generated_at: new Date().toISOString(),
    purpose: 'Reviewed legacy visible strings; CI rejects net-new untranslated UI text.',
    fingerprints: Object.fromEntries(Object.entries(counts).sort()),
  }, null, 2)}\n`)
}
const baseline = fs.existsSync(baselinePath)
  ? JSON.parse(fs.readFileSync(baselinePath, 'utf8')).fingerprints ?? {}
  : {}
const remaining = { ...baseline }
const additions = findings.filter((finding) => {
  const key = fingerprint(finding)
  if ((remaining[key] ?? 0) > 0) {
    remaining[key] -= 1
    return false
  }
  return true
})
console.log(`Hardcoded visible-string gate: ${findings.length} reviewed legacy occurrences, ${additions.length} additions.`)
for (const finding of additions.slice(0, 20)) {
  console.error(`  ${finding.file}:${finding.line} [${finding.kind}] ${finding.text}`)
}
if (additions.length > 0) process.exit(1)
