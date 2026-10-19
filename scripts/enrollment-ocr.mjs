// Local OCR only. Models ship in node_modules; no document or model network calls.
import {createWorker} from 'tesseract.js'
import {createRequire} from 'node:module'
import {dirname, join} from 'node:path'
import {readFile} from 'node:fs/promises'
const require = createRequire(import.meta.url)
const model = dirname(require.resolve('@tesseract.js-data/spa/package.json'))
let worker
try {
  worker = await createWorker('spa', 1, {langPath: join(model, '4.0.0_best_int'), cacheMethod: 'none', logger: () => {}})
  await worker.setParameters({tessedit_pageseg_mode: '3'})
  const texts = []
  for (const path of process.argv.slice(2)) {
    const result = await worker.recognize(await readFile(path))
    texts.push(result.data.text)
  }
  process.stdout.write(JSON.stringify({text: texts.join('\n')}))
} catch {
  process.stderr.write('Enrollment OCR failed')
  process.exitCode = 1
} finally {await worker?.terminate()}
