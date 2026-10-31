/* Publish the canonical pure engine. Browser files are generated from this manifest. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const root = path.resolve(__dirname, '../resources/bulletin-editor');
const version = process.argv[2];
if (!/^\d+\.\d+\.\d+$/.test(version || '')) throw new Error('Usage: node scripts/release-bulletin-engine.cjs <version>');
const names = ['font-catalog.js', 'model.js', 'proposal-model.js', 'layout-model.js', 'field-guide.js', 'page-layout.js', 'report-engine.js', 'canvas-engine.js'];
const files = Object.fromEntries(names.map(name => {
  const file = path.join(root, name);
  // Git checks out this repository with LF; hash the exact bytes shipped on every OS.
  const bytes = Buffer.from(fs.readFileSync(file, 'utf8').replace(/\r\n?/g, '\n'), 'utf8');
  fs.writeFileSync(file, bytes);
  return [name, createHash('sha256').update(bytes).digest('hex')];
}));
fs.writeFileSync(path.join(root, 'engine-manifest.json'), JSON.stringify({version, source: 'colegio-saas-backend/resources/bulletin-editor', files}, null, 2) + '\n');
console.log(`Bulletin engine ${version}: ${names.length} canonical files.`);
