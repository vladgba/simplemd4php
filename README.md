# SimpleMd4PHP

A Markdown site engine for PHP in one file. Put `.md` files next to `index.php` and they are served as HTML pages.

It supports:

- **Traditional Markdown**: headings, emphasis, lists, blockquotes, code, links, images, reference links, inline HTML and HTML blocks.
- **GitHub Flavored Markdown**: fenced code blocks, tables, ~~strikethrough~~, bare URL autolinks and task lists. You can also turn on newline-as-`<br>`.
- **Markdown Extra**: definition lists, footnotes, abbreviations, `{#id .class}` attributes, and fenced code with attributes.
- **Heading anchors**: every heading gets a GitHub-style id (`## Getting Started` → `#getting-started`, repeated headings get `-1`, `-2` added), so links like `[Install](#install)` or `[API](docs/#auth)` work. Hovering a heading shows a `#` permalink. A `{#custom-id}` on the heading replaces the generated id.

## Install

1. Copy `index.php` and `.htaccess` to your web root (or to a subfolder). It needs Apache with `mod_rewrite` and PHP 7.4 or newer.
2. Add your pages:

| URL         | File                                  |
|-------------|---------------------------------------|
| `/`         | `index.md` (or `README.md`)           |
| `/about`    | `about.md`                            |
| `/docs/`    | `docs/index.md` (or `docs/README.md`) |
| not found   | `404.md` (optional)                   |

Images and other static files are served directly. Write relative links like `[About](about)` or `![photo](img/photo.jpg)`.

## Speed

- Each page is parsed once. The HTML goes into `.cache/` and is rebuilt only when the `.md` file (or `index.php`) changes.
- Responses send an `ETag` and `Last-Modified`, so browsers get a `304 Not Modified` for pages they already have.
- There are no dependencies, no external CSS or JS, and only one file to load. OPcache keeps it compiled.

## Settings

The settings are at the top of `index.php`:

```php
const SITE_NAME = 'SimpleMd4PHP';       // appended to page titles
const SITE_LANG = 'en';
const CACHE_DIR = __DIR__ . '/.cache';  // '' disables the HTML cache
const MD_BREAKS = false;                // true: every newline is a <br>
const MD_ANCHORS = true;                // heading ids + "#" permalinks
```

To change the look, edit the `<style>` block in `render_page()`. The page `<title>` comes from the first `# Heading`.

## License

MIT
