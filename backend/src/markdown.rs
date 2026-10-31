//! Markdown → HTML for permanent artifact entrypoints.
//!
//! A `.md` / `.markdown` entrypoint (or `text/markdown` content type) must not
//! be served as `application/octet-stream`: the sandboxed viewer iframe has no
//! `allow-downloads`, so the browser refuses the download and shows a blank
//! page (RUB-515). Convert to a self-contained HTML document instead.

use pulldown_cmark::{html, Options, Parser};

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
pub(crate) fn should_render_markdown(
    content_type: &str,
    entrypoint: &str,
    requested_path: Option<&str>,
) -> bool {
    let path = requested_path.unwrap_or(entrypoint);
    if !path_is_markdown(path) && !content_type_is_markdown(content_type) {
        return false;
    }
    content_type_is_markdown(content_type)
        || content_type
            .split(';')
            .next()
            .unwrap_or(content_type)
            .trim()
            .eq_ignore_ascii_case("application/octet-stream")
        || path_is_markdown(path)
}

/// Convert markdown source to a self-contained HTML document.
pub(crate) fn render_markdown_document(markdown: &str) -> String {
    let mut options = Options::empty();
    options.insert(Options::ENABLE_TABLES);
    options.insert(Options::ENABLE_STRIKETHROUGH);
    options.insert(Options::ENABLE_TASKLISTS);
    let parser = Parser::new_ext(markdown, options);
    let mut body = String::new();
    html::push_html(&mut body, parser);
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
}
