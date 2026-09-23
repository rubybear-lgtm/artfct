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

pub(crate) fn render_preview_shell(artifact: &super::StoredArtifact, url: &str) -> String {
    let escaped_title = escape_text(&artifact.title);
    let escaped_description = escape_text(&artifact.description);
    let escaped_thumbnail = escape_attr(&artifact.thumbnail);
    let escaped_url = escape_attr(url);
    let escaped_expires_at = escape_text(&artifact.expires_at);
    let escaped_preview_status = escape_text(if artifact.preview_blurred {
        "Link preview will start blurred."
    } else {
        "Link preview will start unblurred."
    });
    let preview_class = if artifact.preview_blurred {
        " is-blurred"
    } else {
        ""
    };
    let payload = serde_json::json!({
        "bodyCiphertextB64": artifact.body_ciphertext_b64,
        "bodyIvB64": artifact.body_iv_b64,
        "previewBlurred": artifact.preview_blurred,
    });
    let payload_json = escape_json_script(&serde_json::to_string(&payload).unwrap());

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
<meta property="og:image" content="{escaped_thumbnail}">
<meta property="og:url" content="{escaped_url}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="artfct">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{escaped_title}">
<meta name="twitter:description" content="{escaped_description}">
<meta name="twitter:image" content="{escaped_thumbnail}">
<link rel="canonical" href="{escaped_url}">
<style>
:root{{color-scheme:dark;}}
html,body{{margin:0;min-height:100%;background:#0b0d10;color:#e2e8f0;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;}}
*{{box-sizing:border-box;}}
.page{{min-height:100vh;display:flex;flex-direction:column;gap:1rem;padding:1rem;}}
.meta{{display:grid;grid-template-columns:120px 1fr;gap:1rem;align-items:start;padding:1rem;border:1px solid rgb(148 163 184 / .22);border-radius:16px;background:rgb(15 23 42 / .72);backdrop-filter:blur(14px);box-shadow:0 20px 50px rgb(0 0 0 / .22);}}
.meta img{{width:120px;height:68px;object-fit:cover;border-radius:10px;background:#111827;}}
.meta h1{{margin:0 0 .35rem;font-size:1.1rem;line-height:1.35;color:#f8fafc;}}
.meta p{{margin:0 0 .75rem;line-height:1.6;color:#cbd5e1;}}
.meta .status{{font-size:.8rem;color:#94a3b8;}}
.preview-shell{{padding:1rem;border:1px solid rgb(148 163 184 / .16);border-radius:18px;background:#020617;box-shadow:0 20px 60px rgb(0 0 0 / .3);}}
.preview-shell.is-blurred .preview-card{{filter:blur(18px) saturate(.92);transform:scale(1.015);}}
.preview-card{{display:grid;grid-template-columns:120px 1fr;gap:1rem;align-items:start;padding:1rem;border:1px solid rgb(148 163 184 / .14);border-radius:16px;background:rgb(15 23 42 / .72);backdrop-filter:blur(12px);transition:filter .18s ease,transform .18s ease;}}
.preview-card img{{width:120px;height:68px;object-fit:cover;border-radius:10px;background:#111827;}}
.preview-card h2{{margin:0 0 .35rem;font-size:1rem;line-height:1.35;color:#f8fafc;}}
.preview-card p{{margin:0 0 .75rem;line-height:1.6;color:#cbd5e1;}}
.preview-card .status{{font-size:.8rem;color:#94a3b8;}}
.stage{{position:relative;min-height:72vh;margin-top:1rem;border-radius:18px;overflow:hidden;border:1px solid rgb(148 163 184 / .16);background:#020617;box-shadow:0 20px 60px rgb(0 0 0 / .3);}}
.frame{{position:absolute;inset:0;width:100%;height:100%;border:0;background:white;}}
body.artfct-decrypted{{overflow:hidden;background:white;}}
body.artfct-decrypted .page{{padding:0;gap:0;}}
body.artfct-decrypted .meta{{display:none !important;}}
body.artfct-decrypted .preview-shell{{display:none !important;}}
body.artfct-decrypted .stage{{position:fixed;inset:0;min-height:100vh;margin:0;border:none;border-radius:0;box-shadow:none;}}
body.artfct-decrypted .frame{{position:fixed;inset:0;width:100%;height:100%;}}
.overlay{{position:absolute;inset:0;display:grid;place-items:center;padding:1.5rem;background:linear-gradient(180deg, rgb(2 6 23 / .1), rgb(2 6 23 / .45));}}
[hidden]{{display:none !important;}}
.message{{padding:.85rem 1rem;border-radius:999px;border:1px solid rgb(148 163 184 / .26);background:rgb(15 23 42 / .82);backdrop-filter:blur(12px);color:#e2e8f0;font-size:.9rem;line-height:1.4;max-width:min(90vw, 36rem);text-align:center;}}
</style>
</head>
<body>
<div class="page">
  <section class="meta" aria-label="artifact metadata">
    <img src="{escaped_thumbnail}" alt="">
    <div>
      <h1>{escaped_title}</h1>
      <p>{escaped_description}</p>
      <div class="status">{escaped_preview_status}</div>
      <div class="status">expires <time datetime="{escaped_expires_at}">{escaped_expires_at}</time></div>
    </div>
  </section>
  <section id="artfct-preview" class="preview-shell{preview_class}">
    <div class="preview-card">
      <img src="{escaped_thumbnail}" alt="">
      <div>
        <h2>{escaped_title}</h2>
        <p>{escaped_description}</p>
        <div class="status">{escaped_preview_status}</div>
      </div>
    </div>
  </section>
  <section class="stage">
    <iframe id="artfct-frame" class="frame" hidden sandbox="allow-scripts allow-popups allow-top-navigation-by-user-activation" referrerpolicy="no-referrer"></iframe>
    <div id="artfct-overlay" class="overlay">
      <div id="artfct-message" class="message">Waiting for the decryption key in the URL fragment.</div>
    </div>
  </section>
</div>
<script id="artfct-payload" type="application/json">{payload_json}</script>
<script>
(function() {{
  const payload = JSON.parse(document.getElementById('artfct-payload').textContent || '{{}}');
  const frame = document.getElementById('artfct-frame');
  const preview = document.getElementById('artfct-preview');
  const overlay = document.getElementById('artfct-overlay');
  const message = document.getElementById('artfct-message');

  const textEncoder = new TextEncoder();
  const textDecoder = new TextDecoder();

  function setOverlay(text) {{
    message.textContent = text;
    overlay.hidden = false;
  }}

  function hideOverlay() {{
    overlay.hidden = true;
  }}

  function base64UrlToBytes(value) {{
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {{
      bytes[i] = binary.charCodeAt(i);
    }}

    return bytes;
  }}

  // Must match `KDF_ITERATIONS` in the CLI and the browser encryptor. All three
  // derive the same key or nothing opens.
  const KDF_ITERATIONS = 210000;

  async function deriveKey(passcode, salt, version) {{
    if (version === 2 && salt) {{
      const keyMaterial = await crypto.subtle.importKey(
        'raw',
        textEncoder.encode(passcode),
        'PBKDF2',
        false,
        ['deriveBits']
      );
      const bits = await crypto.subtle.deriveBits(
        {{ name: 'PBKDF2', salt, iterations: KDF_ITERATIONS, hash: 'SHA-256' }},
        keyMaterial,
        256,
      );

      return crypto.subtle.importKey(
        'raw',
        bits,
        {{ name: 'AES-GCM' }},
        false,
        ['decrypt']
      );
    }}

    // Links minted before the salted KDF carry neither a salt nor a version, so
    // they keep the original single-SHA-256 derivation. Removable once no such
    // link can still be live.
    const digest = await crypto.subtle.digest(
      'SHA-256',
      textEncoder.encode(passcode),
    );

    return crypto.subtle.importKey(
      'raw',
      digest,
      {{ name: 'AES-GCM' }},
      false,
      ['decrypt']
    );
  }}

  async function decrypt() {{
    const hash = new URLSearchParams(window.location.hash.slice(1));
    const keyEncoded = hash.get('p') ?? window.location.hash.slice(1);
    const saltEncoded = hash.get('s');
    const version = Number(hash.get('v'));

    if (!keyEncoded) {{
      preview.hidden = false;
      frame.hidden = true;
      setOverlay('Waiting for the decryption key in the URL fragment.');
      return;
    }}

    preview.hidden = true;
    frame.hidden = true;
    setOverlay('Decrypting artifact...');

    try {{
      const cryptoKey = await deriveKey(
        keyEncoded,
        saltEncoded ? base64UrlToBytes(saltEncoded) : null,
        version,
      );
      const iv = base64UrlToBytes(payload.bodyIvB64);
      const ciphertext = base64UrlToBytes(payload.bodyCiphertextB64);
      const plaintext = await crypto.subtle.decrypt(
        {{ name: 'AES-GCM', iv }},
        cryptoKey,
        ciphertext,
      );
      const html = textDecoder.decode(plaintext);

      document.body.classList.add('artfct-decrypted');
      frame.hidden = false;
      frame.srcdoc = html;
      hideOverlay();
    }} catch (error) {{
      frame.hidden = true;
      setOverlay('Unable to decrypt this artifact. Open the full link, including the fragment key.');
    }}
  }}

  window.addEventListener('hashchange', decrypt);
  decrypt();
}})();
</script>
</body>
</html>"#
    )
}
