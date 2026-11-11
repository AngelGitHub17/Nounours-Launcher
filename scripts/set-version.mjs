// Change la version partout d'un coup :  npm run version:set 0.2.0
import { readFileSync, writeFileSync } from 'node:fs';

const v = process.argv[2];
if (!/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/.test(v || '')) {
  console.error('Usage : npm run version:set 1.2.3   (ou 1.3.0-beta.1)');
  process.exit(1);
}
const edit = (file, fn) => { writeFileSync(file, fn(readFileSync(file, 'utf8'))); console.log('✓', file); };

edit('package.json', s => s.replace(/"version":\s*"[^"]+"/, `"version": "${v}"`));
edit('src-tauri/tauri.conf.json', s => s.replace(/"version":\s*"[^"]+"/, `"version": "${v}"`));
edit('src-tauri/Cargo.toml', s => s.replace(/^version\s*=\s*"[^"]+"/m, `version = "${v}"`));
console.log(`\nVersion ${v}. Ensuite :\n  git commit -am "v${v}"\n  git tag -a v${v} -m "- ce qui change"\n  git push && git push --tags`);
