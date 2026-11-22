// Generates resources/css/tokens.css from docs/design-system/tokens.json.
// Run: npm run tokens          (write)
//      npm run tokens -- --check  (fail if the CSS is out of date)
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = resolve(root, 'docs/design-system/tokens.json');
const target = resolve(root, 'resources/css/tokens.css');

const tokens = JSON.parse(readFileSync(source, 'utf8'));
const colors = tokens.color.tokens;
const lines = [];
const out = (line = '') => lines.push(line);

const themeBlock = (selector, theme) => {
    out(`${selector} {`);
    for (const token of colors) {
        out(`    --ff-${token.name}: ${token.value[theme].toLowerCase()};`);
    }
    out('}');
};

out('/* Generated from docs/design-system/tokens.json by scripts/build-tokens.mjs. Do not edit. */');
out();
themeBlock(':root,\n[data-theme="dark"]', 'dark');
out();
themeBlock('[data-theme="light"]', 'light');
out();
out('@theme inline {');
for (const token of colors) {
    out(`    --color-${token.name}: var(--ff-${token.name});`);
}
out('}');
out();
out('@theme {');
for (const [name, stack] of Object.entries(tokens.type.families)) {
    out(`    --font-${name}: ${stack};`);
}
for (const group of tokens.type.groups) {
    for (const style of group.styles) {
        out(`    --text-${style.name}: ${style.fontSize};`);
        out(`    --text-${style.name}--line-height: ${style.lineHeight};`);
        out(`    --text-${style.name}--font-weight: ${style.fontWeight};`);
    }
}
// Tailwind's default spacing step is 4px, so p-1, p-2, p-4, p-6 and p-8 already equal
// space-1 ... space-8. Radius tokens replace the matching Tailwind names.
for (const token of tokens.radius.tokens) {
    out(`    --${token.name}: ${token.value};`);
}
out('}');

const css = lines.join('\n') + '\n';

if (process.argv.includes('--check')) {
    const current = readFileSync(target, 'utf8').replace(/\r\n/g, '\n');
    if (current !== css) {
        console.error('resources/css/tokens.css is out of date. Run: npm run tokens');
        process.exit(1);
    }
    console.log('tokens.css is up to date.');
} else {
    writeFileSync(target, css);
    console.log('Wrote resources/css/tokens.css');
}
