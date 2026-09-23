pub(crate) fn normalize_metadata_value(value: Option<String>, default: &str) -> String {
    let normalized = value
        .map(|value| value.trim().to_string())
        .unwrap_or_default();

    if normalized.is_empty() {
        default.to_string()
    } else {
        normalized
    }
}

pub(crate) fn default_preview_blurred() -> bool {
    true
}

pub(crate) fn escape_json_script(value: &str) -> String {
    value
        .replace('&', "\\u0026")
        .replace('<', "\\u003c")
        .replace('>', "\\u003e")
}

pub(crate) fn escape_attr(value: &str) -> String {
    escape_text(value).replace('"', "&quot;")
}

pub(crate) fn escape_text(value: &str) -> String {
    value
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
}

pub(crate) fn error_html_page(title: &str, message: &str) -> String {
    let escaped_title = escape_text(title);
    let escaped_message = escape_text(message);
    let escaped_description =
        escape_text("This link is expired or invalid — create a new artifact at artfct.dev.");
    let og_image = "https://artfct.dev/og-image.svg";

    format!(
        r#"<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{escaped_title}</title>
<meta name="description" content="{escaped_description}">
<meta property="og:title" content="{escaped_title}">
<meta property="og:description" content="{escaped_description}">
<meta property="og:type" content="website">
<meta property="og:image" content="{og_image}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="artfct">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{og_image}">
<link rel="canonical" href="https://artfct.dev">
</head>
<body>{escaped_message}</body>
</html>"#
    )
}

pub(crate) fn build_preview_response(body: String) -> crate::HtmlResponseDefinition {
    crate::HtmlResponseDefinition::preview(body, 200)
}

pub(crate) fn html_error(message: &str, status: u16) -> crate::Result<worker::Response> {
    let html = error_html_page("Artifact unavailable — artfct", message);
    crate::html_response(&html, status)
}

pub(crate) fn expired_response() -> crate::Result<worker::Response> {
    html_error("This artifact has expired or does not exist.", 404)
}

pub(crate) fn not_found_response() -> crate::Result<worker::Response> {
    html_error("Not found.", 404)
}
