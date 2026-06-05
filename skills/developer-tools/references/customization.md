# Developer Tools Customization Reference

## Solarized CSS Variables

All tools use CSS custom properties. Override in the `<style>` block to retheme:

```css
:root {
  --base3:   #FDF6E3;  /* page background */
  --base2:   #EEE8D5;  /* input backgrounds, table headers, alternates */
  --base1:   #93A1A1;  /* secondary text, placeholders, borders */
  --base0:   #657B83;  /* body text */
  --base00:  #586E75;  /* primary text, headings */
  --base01:  #073642;  /* darkest — used sparingly */
  --yellow:  #B58900;
  --orange:  #CB4B16;
  --red:     #DC322F;
  --blue:    #268BD2;
  --cyan:    #2AA198;
  --green:   #859900;
}
```

To switch to Solarized Dark, swap the base values:
```css
:root {
  --base3:  #002B36;
  --base2:  #073642;
  --base1:  #586E75;
  --base0:  #657B83;
  --base00: #839496;
  --base01: #93A1A1;
}
```

---

## json-table.html Customization

### Adding a frozen first column

```css
td:first-child, th:first-child {
  position: sticky; left: 0;
  background: var(--base2); z-index: 1;
}
```

### Changing numeric precision

In the `render()` function, modify the `fmt` step for number cells:
```js
const display = typeof v === 'number'
  ? v.toLocaleString(undefined, { maximumFractionDigits: 2 })
  : String(v ?? '');
```

### Pre-populating data

Set `{{DATA}}` to a JSON array string. The script calls `parse()` on load if the textarea is non-empty:
```html
<textarea id="input">{{DATA}}</textarea>
<!-- script calls parse() on load automatically -->
```

---

## api-diff.html Customization

### Showing unchanged keys

The diff only outputs changed paths. To also show matching keys, add a `same` type in `deepDiff()`:
```js
} else {
  out.push({ type: 'same', key: k, value: lv });
}
```
And add a `.same` row style (dim, no border highlight).

### Sorting diff output

To group adds before removes before changes, sort the `changes` array before rendering:
```js
const order = { added: 0, removed: 1, changed: 2, path: -1 };
changes.sort((a, b) => (order[a.type] ?? 99) - (order[b.type] ?? 99));
```

### Handling arrays

The current `deepDiff()` treats arrays as atomic values. To diff array elements by index, add:
```js
if (Array.isArray(lv) && Array.isArray(rv)) {
  const len = Math.max(lv.length, rv.length);
  for (let j = 0; j < len; j++) {
    deepDiff({ [j]: lv[j] }, { [j]: rv[j] }, `${childPath}[${j}]`, out);
  }
  continue;
}
```

---

## env-diff.html Customization

### Supporting multiline values

The default parser splits on newlines. For `KEY="line1\nline2"`, extend `parseEnv()`:
```js
// replace the simple split with a stateful parser that tracks open quotes
```

### Exporting the diff as text

Add an export button that formats the diff as a shell-paste-friendly list:
```js
function exportDiff() {
  const lines = data
    .filter(d => d.type !== 'same')
    .map(d => `# ${d.type}: ${d.key}`);
  navigator.clipboard.writeText(lines.join('\n'));
}
```

### Hiding specific key patterns

To filter out noisy keys (e.g., all `*_SECRET` vars from the visible diff):
```js
const HIDE = /SECRET|PASSWORD|TOKEN/i;
const visible = data.filter(d => !HIDE.test(d.key));
```

---

## regex-tester.html Customization

### Highlighting capture groups in different colors

Add per-group CSS classes and assign them in the highlight loop:
```js
const GROUP_COLORS = ['#d4eef9','#fde8d4','#e8f4d4','#f4d4ee'];
// wrap each group with <mark class="g1">, <mark class="g2">, etc.
```

### Adding named groups display

If the regex uses named groups (`(?<year>\d{4})`), show them in the match list:
```js
const named = Object.entries(m.groups ?? {})
  .map(([name, val]) => `<span class="group-tag">${name}</span> ${esc(val)}`)
  .join(' ');
```

### Multi-line test cases

To support multiple independent test strings (one per line), split on `\n---\n` and run the regex on each section separately, displaying results grouped by section.
