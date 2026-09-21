# Optional file and brand icon packs

The existing `ui.icons` catalog and unknown-name error behavior remain compatible.
Three explicit ES module packs add 32 icons without putting brand artwork in the
default UI bundle. Copy the optional modules when refreshing a local vendor tree.

## Setup

```js
import { uiLoader } from "../js/ui/ui.loader.js";
import { FILE_ICONS, getFileIconName } from "../js/ui/ui.icons.files.js";
import { SOCIAL_ICONS } from "../js/ui/ui.icons.social.js";
import { AI_ICONS } from "../js/ui/ui.icons.ai.js";
await uiLoader.load("ui.icons");
const { registerIconPack, createIcon } = await uiLoader.get("ui.icons");
registerIconPack(FILE_ICONS);
registerIconPack(SOCIAL_ICONS);
registerIconPack(AI_ICONS);
const icon = createIcon(getFileIconName("report.PDF"), { title: "PDF attachment" });
document.querySelector("#attachment").prepend(icon);
```

Import only the packs you need, optionally with `await import(...)` on first use.
Register them on the same `ui.icons` module instance used to render your UI.
When using `preferBundles`, get that instance through the loader as above; do not
register on a separate direct source import and expect the bundle to share it.
Pack files are local assets: no runtime CDN, framework, or npm dependency is needed.

## Available names

| Pack export | Names (prefix each with the namespace) |
| --- | --- |
| `FILE_ICONS` / `files.` | unknown, pdf, word, excel, csv, presentation, archive, image, audio, video, code, json, text |
| `SOCIAL_ICONS` / `social.` | facebook, instagram, youtube, tiktok, github, whatsapp, discord, x, reddit, telegram |
| `AI_ICONS` / `ai.` | openai, anthropic, claude, gemini, deepseek, ollama, copilot, mistral, generic |

AI names represent the named provider/product, not every model version. `ai.openai`
is the OpenAI mark; there is no separate ChatGPT or model-version alias in this release.
File types use original format symbols, not office-product trademarks.

## Appearance and accessibility

```js
createIcon("social.github"); // currentColor, decorative by default
createIcon("ai.claude", { variant: "brand", title: "Claude" });
createIcon("ai.future-provider", { fallback: "ai.generic", title: "AI provider" });
```

Filled brand silhouettes preserve their shape and do not inherit the core outline
stroke. `variant: "brand"` uses a supplied single accent for social icons, Claude,
and DeepSeek. Other AI marks remain monochrome; multicolor gradients are not
recreated as a substitute. Original dark brand colors may need a light background:
the demo shows both surfaces so applications can choose a suitable variant.
Give meaningful icons `title` or `ariaLabel`; label icon-only buttons on the button.

`getFileIconName(filename, mimeType)` checks a case-insensitive extension first,
then supported MIME types/families, then returns `files.unknown`. It handles
paths and query strings. It selects an icon, not a security-validated file type.
The files pack must be registered before rendering the returned name.

`registerIconPack` defensively copies definitions and validates supported shapes
and attributes. Identical registration is idempotent; conflicting IDs throw.
Validation is atomic. Existing icons cannot be replaced accidentally. Definitions
are application code, not an entry point for arbitrary uploaded SVG.

## Artwork provenance

- Social paths: [Simple Icons](https://github.com/simple-icons/simple-icons), npm
  `simple-icons@16.31.0`. Collection license: CC0; individual trademarks, licenses,
  and brand guidelines remain applicable. See [upstream disclaimer](https://github.com/simple-icons/simple-icons/blob/develop/DISCLAIMER.md).
- AI paths: [Lobe Icons](https://github.com/lobehub/lobe-icons), npm
  `@lobehub/icons-static-svg@1.95.0`, MIT, copyright 2023 LobeHub.
- File symbols and `ai.generic`: original Helper artwork under the repository license.

See [per-icon source records](icon-packs-sources.json) for pinned package versions,
original sources/guidelines, and SHA-256 hashes. Preserve `licenses/` when vendoring.
Collection licenses do not grant trademark rights or imply endorsement.

To regenerate the selected brands, unpack those exact npm archives and run:

```sh
node scripts/import.icon.packs.mjs /path/simple-icons/package /path/lobe/package
```

The importer rejects non-flat SVGs for explicit review. Paths are preserved;
presentation attributes are normalized to monochrome fill with no outline.
Run `node tests/icons.regression.mjs`, bundle/registry contracts, and the browser
fixture `tests/icons.regression.html?bundled=1` after changes.
