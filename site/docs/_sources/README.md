# Documentation sources

The markdown files in this folder are the **source of truth** for the user-facing
documentation rendered under `site/docs/*.html`.

When you edit one of these files, the corresponding HTML page does **not** update
automatically. You have to regenerate.

## Regenerating the HTML pages

Today the conversion is manual. The original generator lived at `/tmp/build_docs.py`
and uses only the Python 3 standard library. The rules it followed:

- The H1 of each markdown file becomes the page `<h1>` and the `<title>`.
- Subsequent `##`/`###` headings become `<h2>`/`<h3>`.
- Bullet lists become `<ul>`, numbered lists become `<ol>`.
- Fenced code blocks (```` ``` ````) become `<pre><code>`.
- Inline backticks become `<code>`.
- `**bold**` becomes `<strong>`, `*italic*` becomes `<em>`.
- Markdown links to sibling `*.md` files are rewritten to `*.html`. External
  `http(s)://` links are left untouched.
- `<!-- TODO ... -->` and `<!-- source: ... -->` HTML comments are preserved
  verbatim so editors still see what needs filling in.
- Every page is wrapped in the same shell as `site/docs/index.html`: the
  landing's `site-header`, a `docs-sidebar` listing all sibling pages with the
  current one marked `aria-current="page"`, and the landing's `site-footer`
  with the three-column layout.

In practice that means: if you want to refresh the rendered docs after editing
the markdown, the lightest-weight option today is to ask Claude to re-run the
mechanical convert-and-template pass. A future Claude skill (or a small Python
script committed to the repo) can automate it.

## Adding a new page

1. Create `site/docs/_sources/your-new-page.md` with a single `#` H1.
2. Add it to the sidebar list in **every** page under `site/docs/*.html`
   (including `index.html`).
3. Add a `<a class="docs-card">` for it on `site/docs/index.html`.
4. Add a meta description and a (slug, label, title) entry to the generator.
5. Regenerate (see above).

## Why keep markdown sources at all?

Plain markdown is far easier to write, diff, and edit than HTML. Keeping the
sources next to the rendered output means the docs stay editable without anyone
needing to wrangle HTML by hand.
