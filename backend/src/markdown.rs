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

use pulldown_cmark::{html, CowStr, Event, Options, Parser, Tag, TagEnd};

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

/// Convert markdown source to a self-contained HTML document.
pub(crate) fn render_markdown_document(markdown: &str) -> String {
    let mut options = Options::empty();
    options.insert(Options::ENABLE_TABLES);
    options.insert(Options::ENABLE_STRIKETHROUGH);
    options.insert(Options::ENABLE_TASKLISTS);
    let parser = Parser::new_ext(markdown, options);
    let mut body = String::new();
    html::push_html(&mut body, sanitize_markdown_events(parser));
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
        assert!(html.contains("<h1>Hello</h1>"));
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
        assert!(html.contains("<h1>Title</h1>"));
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
        assert!(html.contains("<h1>Quarterly</h1>"));
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
        assert!(html.contains("<h1>Safe</h1>"));
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
        assert!(html.contains("<h1>Links</h1>"), "{html}");
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
        assert!(rendered.contains("<h1>Hello</h1>"));
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
}
