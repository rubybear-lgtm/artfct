//! Markdown → HTML for permanent artifact entrypoints.
//!
//! A `.md` / `.markdown` entrypoint (or `text/markdown` content type) must not
//! be served as `application/octet-stream`: the sandboxed viewer iframe has no
//! `allow-downloads`, so the browser refuses the download and shows a blank
//! page (RUB-515). Convert to a self-contained HTML document instead.
//!
//! MCP `deploy_artifact` stores raw markdown as `text/markdown` under a `.md`
//! path. This Worker is the sole HTML renderer for that content — matching
//! Laravel's CommonMark `html_input => strip` and `allow_unsafe_links => false`
//! so shared-origin preview CSP cannot execute injected script or follow
//! `javascript:` / `data:` destinations.

use pulldown_cmark::{html, CowStr, Event, HeadingLevel, Options, Parser, Tag, TagEnd};
use std::collections::{HashMap, HashSet, VecDeque};

const MARKDOWN_CSS: &str = r#"
:root { color-scheme: light dark; }
body {
  margin: 0 auto;
  max-width: 48rem;
  padding: 1.5rem 1.25rem 3rem;
  font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
  color: #1a1a1a;
  background: #fff;
}
@media (prefers-color-scheme: dark) {
  body { color: #e8e6e3; background: #121212; }
  a { color: #8cb4ff; }
  code, pre { background: #1e1e1e; }
  table th, table td { border-color: #333; }
  table th { background: #1e1e1e; }
}
h1, h2, h3, h4 { line-height: 1.25; margin: 1.4em 0 0.5em; }
h1 { font-size: 1.75rem; }
h2 { font-size: 1.35rem; }
h3 { font-size: 1.15rem; }
p, ul, ol { margin: 0.75em 0; }
a { color: #0b57d0; }
code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.9em;
  padding: 0.1em 0.35em;
  border-radius: 4px;
  background: #f3f3f3;
}
pre {
  overflow: auto;
  padding: 0.85rem 1rem;
  border-radius: 6px;
  background: #f3f3f3;
}
pre code { padding: 0; background: transparent; }
table { border-collapse: collapse; margin: 1em 0; width: 100%; }
th, td { border: 1px solid #ddd; padding: 0.45em 0.7em; text-align: left; }
th { background: #f7f7f7; }
blockquote {
  margin: 1em 0;
  padding: 0.1em 0 0.1em 1em;
  border-left: 3px solid #ccc;
  color: #555;
}
img { max-width: 100%; height: auto; }
"#;

/// True when the served path (or entrypoint) is a markdown file.
pub(crate) fn path_is_markdown(path: &str) -> bool {
    let lower = path.to_ascii_lowercase();
    lower.ends_with(".md") || lower.ends_with(".markdown")
}

/// True when the stored content type is markdown (any charset).
pub(crate) fn content_type_is_markdown(content_type: &str) -> bool {
    let base = content_type
        .split(';')
        .next()
        .unwrap_or(content_type)
        .trim()
        .to_ascii_lowercase();
    base == "text/markdown"
}

/// Whether this response body should be rendered from markdown to HTML.
///
/// Entrypoint navigations (`requested_path` is `None`) and explicit requests
/// for a markdown path both qualify when the content type or path says markdown,
/// including the legacy `application/octet-stream` case for `.md` files.
///
/// Already-stored `text/html` must not be re-wrapped: older MCP publishes kept
/// a `.md` entrypoint while storing rendered HTML bytes. Re-running
/// pulldown-cmark on that HTML nested a second document shell around the page.
pub(crate) fn should_render_markdown(
    content_type: &str,
    entrypoint: &str,
    requested_path: Option<&str>,
) -> bool {
    let base = content_type
        .split(';')
        .next()
        .unwrap_or(content_type)
        .trim();
    if base.eq_ignore_ascii_case("text/html") {
        return false;
    }
    let path = requested_path.unwrap_or(entrypoint);
    if !path_is_markdown(path) && !content_type_is_markdown(content_type) {
        return false;
    }
    content_type_is_markdown(content_type)
        || base.eq_ignore_ascii_case("application/octet-stream")
        || path_is_markdown(path)
}

/// Match League CommonMark `allow_unsafe_links => false`: empty the destination
/// for javascript / vbscript / data / file schemes (any casing / leading space).
pub(crate) fn is_unsafe_url(url: &str) -> bool {
    let trimmed = url.trim();
    if trimmed.is_empty() {
        return false;
    }
    let lower = trimmed.to_ascii_lowercase();
    let Some((scheme, _rest)) = lower.split_once(':') else {
        return false;
    };
    // Relative paths like `./foo:bar` or `C:\...` are not schemes we strip;
    // only a leading alpha scheme without a slash before the colon.
    if scheme.is_empty() || scheme.contains('/') || scheme.contains('\\') {
        return false;
    }
    matches!(scheme, "javascript" | "vbscript" | "data" | "file")
}

fn sanitize_destination(url: CowStr<'_>) -> CowStr<'_> {
    if is_unsafe_url(&url) {
        CowStr::Borrowed("")
    } else {
        url
    }
}

/// Drop raw HTML and neutralize unsafe link/image destinations (Laravel
/// CommonMark `html_input => strip` + `allow_unsafe_links => false`).
fn sanitize_markdown_events<'a, I>(events: I) -> impl Iterator<Item = Event<'a>>
where
    I: Iterator<Item = Event<'a>>,
{
    events.filter_map(|event| match event {
        Event::Html(_)
        | Event::InlineHtml(_)
        | Event::Start(Tag::HtmlBlock)
        | Event::End(TagEnd::HtmlBlock) => None,
        Event::Start(Tag::Link {
            link_type,
            dest_url,
            title,
            id,
        }) => Some(Event::Start(Tag::Link {
            link_type,
            dest_url: sanitize_destination(dest_url),
            title,
            id,
        })),
        Event::Start(Tag::Image {
            link_type,
            dest_url,
            title,
            id,
        }) => Some(Event::Start(Tag::Image {
            link_type,
            dest_url: sanitize_destination(dest_url),
            title,
            id,
        })),
        other => Some(other),
    })
}

/// Longest base slug kept. The viewer (`FRAME_FRAGMENT` in
/// `resources/js/pages/artifacts/show.tsx`) forwards at most 128 characters
/// after `#`, so this leaves room for a `-N` suffix (at most 7 digits: the
/// 25 MB artifact cap holds ~6.5M `# a` headings). `HeadingSlug::MAX_LENGTH`
/// in PHP is the same.
const MAX_SLUG_LEN: usize = 120;

/// Heading slug shared with Laravel (`App\Services\HeadingSlug`): lowercase
/// ASCII alphanumerics kept, every other byte run becomes one `-`, truncated
/// to `MAX_SLUG_LEN`, trimmed; empty falls back to `section`. Byte-wise so
/// `é` (two UTF-8 bytes) collapses to one `-`, exactly like PHP's byte-level
/// `preg_replace`.
fn slugify_heading(text: &str) -> String {
    let mut slug = String::with_capacity(text.len());
    let mut separator = true;
    for byte in text.bytes() {
        if byte.is_ascii_alphanumeric() {
            slug.push(byte.to_ascii_lowercase() as char);
            separator = false;
        } else if !separator {
            slug.push('-');
            separator = true;
        }
    }
    if slug.len() > MAX_SLUG_LEN {
        slug.truncate(MAX_SLUG_LEN);
    }
    while slug.ends_with('-') {
        slug.pop();
    }
    if slug.is_empty() {
        slug.push_str("section");
    }
    slug
}

/// Per-document heading ids: first use keeps the base, repeats take
/// `base-2`, `base-3`, … The same state as PHP `HeadingSlug`.
///
/// `next` remembers the suffix the last repeat of each base landed on, so N
/// repeats of one heading cost N steps instead of N²; `used` is still checked
/// because a literal heading can spell a candidate (`a-2` before a third `a`).
#[derive(Default)]
struct HeadingIds {
    used: HashSet<String>,
    next: HashMap<String, u32>,
}

impl HeadingIds {
    fn id(&mut self, base: &str) -> String {
        if self.used.insert(base.to_string()) {
            return base.to_string();
        }
        let mut suffix = *self.next.get(base).unwrap_or(&2);
        loop {
            let candidate = format!("{base}-{suffix}");
            suffix += 1;
            if self.used.insert(candidate.clone()) {
                self.next.insert(base.to_string(), suffix);
                return candidate;
            }
        }
    }
}

struct HeadingCapture<'a> {
    level: HeadingLevel,
    classes: Vec<CowStr<'a>>,
    attrs: Vec<(CowStr<'a>, Option<CowStr<'a>>)>,
    text: String,
    inner: Vec<Event<'a>>,
    image_depth: u32,
}

/// Gives every heading a stable `id` so `/a/{id}#{slug}` links scroll to the
/// section. Slug text is the heading's visible text: emphasis, links and code
/// contribute their text; images contribute nothing (DOM `textContent` on the
/// Laravel side has no image alt text either). Soft and hard breaks read as a
/// separator because PHP's `textContent` sees the newline CommonMark writes
/// after `<br />`; a hard break that added nothing made the two renderers
/// disagree (`firstsecond` vs `first-second`).
///
/// Only the heading being read is buffered, so memory stays O(1) in the
/// document size instead of collecting every event of a 25 MB file.
struct WithHeadingIds<'a, I> {
    events: I,
    ids: HeadingIds,
    heading: Option<HeadingCapture<'a>>,
    /// `Start` + inner + `End` of the heading just closed, drained before the
    /// next source event.
    ready: VecDeque<Event<'a>>,
}

impl<'a, I: Iterator<Item = Event<'a>>> WithHeadingIds<'a, I> {
    fn new(events: I) -> Self {
        Self {
            events,
            ids: HeadingIds::default(),
            heading: None,
            ready: VecDeque::new(),
        }
    }

    fn finish_heading(&mut self, capture: HeadingCapture<'a>) {
        let id = self.ids.id(&slugify_heading(&capture.text));
        self.ready.push_back(Event::Start(Tag::Heading {
            level: capture.level,
            id: Some(CowStr::Boxed(id.into_boxed_str())),
            classes: capture.classes,
            attrs: capture.attrs,
        }));
        self.ready.extend(capture.inner);
        self.ready
            .push_back(Event::End(TagEnd::Heading(capture.level)));
    }
}

impl<'a, I: Iterator<Item = Event<'a>>> Iterator for WithHeadingIds<'a, I> {
    type Item = Event<'a>;

    fn next(&mut self) -> Option<Event<'a>> {
        loop {
            if let Some(event) = self.ready.pop_front() {
                return Some(event);
            }
            let event = match self.events.next() {
                Some(event) => event,
                None => {
                    // Unclosed heading (cannot happen from the parser): flush
                    // it without an id.
                    if let Some(capture) = self.heading.take() {
                        self.ready.push_back(Event::Start(Tag::Heading {
                            level: capture.level,
                            id: None,
                            classes: capture.classes,
                            attrs: capture.attrs,
                        }));
                        self.ready.extend(capture.inner);
                        // Close it, or the rest of the document nests inside.
                        self.ready
                            .push_back(Event::End(TagEnd::Heading(capture.level)));
                        continue;
                    }
                    return None;
                }
            };
            if self.heading.is_none() {
                match event {
                    Event::Start(Tag::Heading {
                        level,
                        id: _,
                        classes,
                        attrs,
                    }) => {
                        self.heading = Some(HeadingCapture {
                            level,
                            classes,
                            attrs,
                            text: String::new(),
                            inner: Vec::new(),
                            image_depth: 0,
                        });
                    }
                    other => return Some(other),
                }
                continue;
            }
            if matches!(event, Event::End(TagEnd::Heading(_))) {
                let capture = self.heading.take().expect("heading capture");
                self.finish_heading(capture);
                continue;
            }
            let capture = self.heading.as_mut().expect("heading capture");
            match event {
                Event::Start(Tag::Image { .. }) => {
                    capture.image_depth += 1;
                    capture.inner.push(event);
                }
                Event::End(TagEnd::Image) => {
                    capture.image_depth = capture.image_depth.saturating_sub(1);
                    capture.inner.push(event);
                }
                Event::Text(text) => {
                    if capture.image_depth == 0 {
                        capture.text.push_str(&text);
                    }
                    capture.inner.push(Event::Text(text));
                }
                Event::Code(code) => {
                    if capture.image_depth == 0 {
                        capture.text.push_str(&code);
                    }
                    capture.inner.push(Event::Code(code));
                }
                Event::SoftBreak => {
                    if capture.image_depth == 0 {
                        capture.text.push('\n');
                    }
                    capture.inner.push(Event::SoftBreak);
                }
                Event::HardBreak => {
                    if capture.image_depth == 0 {
                        capture.text.push('\n');
                    }
                    capture.inner.push(Event::HardBreak);
                }
                other => capture.inner.push(other),
            }
        }
    }
}

/// Convert markdown source to a self-contained HTML document.
///
/// Every heading carries a stable `id` (see `WithHeadingIds`).
pub(crate) fn render_markdown_document(markdown: &str) -> String {
    let mut options = Options::empty();
    options.insert(Options::ENABLE_TABLES);
    options.insert(Options::ENABLE_STRIKETHROUGH);
    options.insert(Options::ENABLE_TASKLISTS);
    let parser = Parser::new_ext(markdown, options);
    let mut body = String::new();
    html::push_html(
        &mut body,
        WithHeadingIds::new(sanitize_markdown_events(parser)),
    );
    format!(
        "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>Markdown</title>\n<style>{MARKDOWN_CSS}</style>\n</head>\n<body>\n{body}\n</body>\n</html>"
    )
}

/// If the body should be served as rendered markdown, return `(text/html, html_bytes)`.
/// Otherwise return the original content type and bytes unchanged.
pub(crate) fn maybe_render_markdown_response(
    content_type: &str,
    entrypoint: &str,
    requested_path: Option<&str>,
    bytes: Vec<u8>,
) -> (String, Vec<u8>) {
    if !should_render_markdown(content_type, entrypoint, requested_path) {
        return (content_type.to_string(), bytes);
    }
    let Ok(markdown) = std::str::from_utf8(&bytes) else {
        return (content_type.to_string(), bytes);
    };
    let html = render_markdown_document(markdown);
    ("text/html; charset=utf-8".to_string(), html.into_bytes())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn path_detection() {
        assert!(path_is_markdown("README.md"));
        assert!(path_is_markdown("docs/Note.MarkDown"));
        assert!(!path_is_markdown("index.html"));
    }

    #[test]
    fn renders_heading_and_table() {
        let html = render_markdown_document("# Hello\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n");
        assert!(html.contains("<h1 id=\"hello\">Hello</h1>"));
        assert!(html.contains("<table>"));
        assert!(html.contains("<td>1</td>"));
        assert!(html.starts_with("<!doctype html>"));
    }

    #[test]
    fn octet_stream_markdown_entrypoint_is_rendered() {
        let (ctype, body) = maybe_render_markdown_response(
            "application/octet-stream",
            "README.md",
            None,
            b"# Title\n\nBody text here.".to_vec(),
        );
        assert_eq!(ctype, "text/html; charset=utf-8");
        let html = String::from_utf8(body).unwrap();
        assert!(html.contains("<h1 id=\"title\">Title</h1>"));
        assert!(!html.contains("application/octet-stream"));
    }

    #[test]
    fn text_markdown_content_type_is_rendered_as_html() {
        let (ctype, body) = maybe_render_markdown_response(
            "text/markdown; charset=utf-8",
            "README.md",
            None,
            b"# Quarterly\n\n| Region | Revenue |\n| --- | --- |\n| West | 10 |\n".to_vec(),
        );
        assert_eq!(ctype, "text/html; charset=utf-8");
        assert!(!ctype.contains("octet-stream"));
        let html = String::from_utf8(body).unwrap();
        assert!(html.contains("<h1 id=\"quarterly\">Quarterly</h1>"));
        assert!(html.contains("<table>"));
        assert!(html.contains("<td>West</td>"));
    }

    #[test]
    fn html_entrypoint_unchanged() {
        let original = b"<!doctype html><h1>Hi</h1>".to_vec();
        let (ctype, body) = maybe_render_markdown_response(
            "text/html; charset=utf-8",
            "index.html",
            None,
            original.clone(),
        );
        assert_eq!(ctype, "text/html; charset=utf-8");
        assert_eq!(body, original);
    }

    #[test]
    fn pre_rendered_html_with_markdown_path_is_not_double_wrapped() {
        // Older MCP publishes stored rendered HTML under a .md path. Serving
        // must not run markdown→HTML again.
        let original = b"<!doctype html><html><body><h1>Quarterly</h1><table><tr><td>1</td></tr></table></body></html>".to_vec();
        let (ctype, body) = maybe_render_markdown_response(
            "text/html; charset=utf-8",
            "README.md",
            None,
            original.clone(),
        );
        assert_eq!(ctype, "text/html; charset=utf-8");
        assert_eq!(body, original);
        assert!(!should_render_markdown(
            "text/html; charset=utf-8",
            "README.md",
            None
        ));
    }

    #[test]
    fn raw_html_and_inline_script_are_stripped() {
        let html = render_markdown_document(
            "# Safe\n\n<script>alert(1)</script>\n\nHello <img src=x onerror=alert(1)>\n\n<div onclick=\"evil()\">x</div>\n",
        );
        assert!(html.contains("<h1 id=\"safe\">Safe</h1>"));
        assert!(html.contains("Hello"));
        assert!(
            !html.contains("<script>"),
            "raw script tags must be stripped: {html}"
        );
        assert!(
            !html.contains("onerror="),
            "inline event handlers must be stripped: {html}"
        );
        assert!(
            !html.contains("<img"),
            "raw img tags must be stripped: {html}"
        );
        assert!(
            !html.contains("<div"),
            "raw HTML blocks must be stripped: {html}"
        );
        assert!(!html.contains("alert(1)"));
        assert!(!html.contains("evil()"));
    }

    #[test]
    fn unsafe_link_and_image_destinations_are_emptied() {
        assert!(is_unsafe_url("javascript:alert(1)"));
        assert!(is_unsafe_url(" JavaScript:alert(1)"));
        assert!(is_unsafe_url("data:text/html,<script>alert(1)</script>"));
        assert!(is_unsafe_url("data:image/svg+xml,<svg>"));
        assert!(is_unsafe_url("vbscript:msgbox(1)"));
        assert!(is_unsafe_url("file:///etc/passwd"));
        assert!(!is_unsafe_url("https://artfct.dev"));
        assert!(!is_unsafe_url("/relative/path"));
        assert!(!is_unsafe_url("#heading"));
        assert!(!is_unsafe_url("mailto:hello@example.com"));

        let html = render_markdown_document(
            "# Links\n\n[bad](javascript:alert(1))\n\n[ok](https://artfct.dev)\n\n![x](data:image/png;base64,AAAA)\n\n![y](https://artfct.dev/og-image.svg)\n\n[html](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)\n",
        );
        assert!(html.contains("<h1 id=\"links\">Links</h1>"), "{html}");
        assert!(
            !html.contains("javascript:"),
            "javascript destinations must be emptied: {html}"
        );
        assert!(
            !html.contains("data:image"),
            "data: image destinations must be emptied: {html}"
        );
        assert!(
            !html.contains("data:text/html"),
            "data: text/html destinations must be emptied: {html}"
        );
        assert!(html.contains("href=\"https://artfct.dev\""), "{html}");
        assert!(
            html.contains("src=\"https://artfct.dev/og-image.svg\""),
            "{html}"
        );
        assert!(html.contains("<a href=\"\">bad</a>"), "{html}");
        assert!(html.contains("<img src=\"\" alt=\"x\""), "{html}");
        assert!(html.contains("<a href=\"\">html</a>"), "{html}");
    }

    #[test]
    fn heading_ids_match_shared_fixture() {
        // tests/Fixtures/markdown-heading-ids.md is the cross-language
        // contract: tests/Unit/MarkdownHeadingIdsTest.php asserts the same ids
        // from Laravel. If the slug shape changes, update both sides together.
        let source = include_str!("../../tests/Fixtures/markdown-heading-ids.md");
        let html = render_markdown_document(source);
        for expected in [
            "<h1 id=\"hello-world\">Hello World</h1>",
            "<h2 id=\"7-dated-cut-marketplace-launch\">7. Dated cut: marketplace launch</h2>",
            "<h2 id=\"hello-world-2\">Hello World</h2>",
            "<h3 id=\"fix-the-bold-bit\">Fix the <strong>bold</strong> bit</h3>",
            "<h2 id=\"see-the-docs\">",
            "<h2 id=\"use-artfct-deploy-today\">",
            "<h2 id=\"caf-cr-me\">",
            "<h2 id=\"section\">!!!</h2>",
            "<h1 id=\"hello-world-3\">Hello World</h1>",
            "<h2 id=\"see\">",
            "<h1 id=\"first-second\">First<br />\nsecond</h1>",
            "<h1 id=\"a\">A</h1>",
            "<h2 id=\"a-2\">A</h2>",
            "<h2 id=\"a-2-2\">A-2</h2>",
            "<h2 id=\"a-3\">A</h2>",
            "<h2 id=\"long-headings-are-truncated-so-the-viewer-still-forwards-the-fragment-and-scrolling-lands-on-the-intended-section-of-the\">",
        ] {
            assert!(html.contains(expected), "missing {expected} in:\n{html}");
        }
        // Leading punctuation must not shift the cap: PHP used to trim it
        // after truncating and mint a 119-character id for this heading. The
        // literal, not `MAX_SLUG_LEN`, so the two languages' caps cannot drift
        // apart while each side keeps checking itself.
        let capped = "x".repeat(120);
        assert!(
            html.contains(&format!("<h2 id=\"{capped}\">(")),
            "capped id missing in:\n{html}"
        );
    }

    #[test]
    fn heading_ids_dedupe_and_fall_back() {
        let html = render_markdown_document("# Dup\n\n## Dup\n\n## !!!\n");
        assert!(html.contains("<h1 id=\"dup\">Dup</h1>"), "{html}");
        assert!(html.contains("<h2 id=\"dup-2\">Dup</h2>"), "{html}");
        assert!(html.contains("<h2 id=\"section\">!!!</h2>"), "{html}");
    }

    #[test]
    fn heading_ids_treat_hard_breaks_as_separators() {
        // PHP's DOM `textContent` of `<h1>First<br />\nsecond</h1>` is
        // "First\nsecond", so a hard break separates words on both sides.
        for source in ["First  \nsecond\n===\n", "First\\\nsecond\n===\n"] {
            let html = render_markdown_document(source);
            assert!(html.contains("id=\"first-second\""), "{html}");
        }
    }

    #[test]
    fn long_headings_fit_the_viewer_fragment_limit() {
        // FRAME_FRAGMENT drops anything over 128 characters after `#`, so an
        // uncapped slug would give an id the viewer never forwards. The
        // deduped id adds a suffix on top of the cap.
        let heading = "word ".repeat(100);
        let html = render_markdown_document(&format!("# {heading}\n\n# {heading}\n"));
        let ids: Vec<&str> = html
            .split("id=\"")
            .skip(1)
            .filter_map(|rest| rest.split_once('"').map(|(id, _)| id))
            .collect();
        assert_eq!(ids.len(), 2, "{html}");
        assert!(ids[0].len() >= 100, "{}", ids[0]);
        assert!(ids[0].len() <= 128, "{}", ids[0]);
        assert!(ids[1].ends_with("-2"), "{}", ids[1]);
        assert!(ids[1].len() <= 128, "{}", ids[1]);
    }

    #[test]
    fn repeated_headings_do_not_make_rendering_quadratic() {
        // The suffix search restarted at `-2` for every repeat, so N repeats
        // cost N²: 20k took minutes there. It takes milliseconds here, so a
        // generous bound still catches a return of the rescans.
        let markdown = "# a\n\n".repeat(20_000);
        let started = std::time::Instant::now();
        let html = render_markdown_document(&markdown);
        let elapsed = started.elapsed();
        assert!(html.contains("<h1 id=\"a\">a</h1>"), "{html}");
        assert!(html.contains("<h1 id=\"a-20000\">a</h1>"), "{html}");
        assert!(
            elapsed < std::time::Duration::from_secs(5),
            "20k duplicate headings took {elapsed:?}"
        );
    }

    #[test]
    fn browser_fixture_matches_worker_renderer() {
        // tests/Browser/Rub515BrowserTest.php serves this fixture. If the
        // Worker renderer changes, regenerate with UPDATE_RUB515_FIXTURE=1 —
        // do not hand-edit the browser HTML independently.
        let source = include_str!("../../tests/Fixtures/rub515-markdown-source.md");
        let fixture_path = concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../tests/Fixtures/rub515-markdown-rendered.html"
        );
        let rendered = render_markdown_document(source);
        if std::env::var_os("UPDATE_RUB515_FIXTURE").is_some() {
            std::fs::write(fixture_path, &rendered).expect("write markdown fixture");
        }
        let expected = std::fs::read_to_string(fixture_path).expect("read markdown fixture");
        assert_eq!(
            rendered, expected,
            "regenerate tests/Fixtures/rub515-markdown-rendered.html with UPDATE_RUB515_FIXTURE=1"
        );
        assert!(rendered.contains("<h1 id=\"hello\">Hello</h1>"));
        assert!(rendered.contains("<table>"));
        assert!(!rendered.contains("application/octet-stream"));

        let (ctype, body) = maybe_render_markdown_response(
            "text/markdown; charset=utf-8",
            "README.md",
            None,
            source.as_bytes().to_vec(),
        );
        assert_eq!(ctype, "text/html; charset=utf-8");
        assert_eq!(String::from_utf8(body).unwrap(), expected);
    }

    /// What `tests/Browser/Rub603HeadingIdsTest.php` serves: the Worker's own
    /// render of the shared heading fixture with enough paragraphs before the
    /// anchor to put it below the frame's first viewport, and enough after it
    /// that the browser can align it to the top instead of clamping at the
    /// document end. The filler has no headings, so every id still comes from
    /// `tests/Fixtures/markdown-heading-ids.md`.
    fn rub603_browser_document() -> String {
        let mut source = String::new();
        for _ in 0..24 {
            source.push_str("Depth paragraph for the browser scroll proof.\n\n");
        }
        source.push_str(include_str!("../../tests/Fixtures/markdown-heading-ids.md"));
        source.push('\n');
        for _ in 0..24 {
            source.push_str("Trailing paragraph so the anchor can reach the top.\n\n");
        }
        render_markdown_document(&source)
    }

    #[test]
    fn rub603_browser_fixture_matches_worker_renderer() {
        // The browser proof must run the production renderer, not the PHP
        // test helper (it has no non-test callers). Regenerate with
        // UPDATE_RUB603_FIXTURE=1; never hand-edit the fixture.
        let fixture_path = concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../tests/Fixtures/rub603-markdown-rendered.html"
        );
        let rendered = rub603_browser_document();
        if std::env::var_os("UPDATE_RUB603_FIXTURE").is_some() {
            std::fs::write(fixture_path, &rendered).expect("write rub603 browser fixture");
        }
        let expected = std::fs::read_to_string(fixture_path).expect("read rub603 browser fixture");
        assert_eq!(
            rendered, expected,
            "regenerate tests/Fixtures/rub603-markdown-rendered.html with UPDATE_RUB603_FIXTURE=1"
        );
        assert!(
            rendered.contains("<h2 id=\"use-artfct-deploy-today\">"),
            "browser anchor heading missing"
        );
    }
}
