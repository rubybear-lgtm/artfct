use anyhow::{anyhow, Result};
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use ring::aead::{self, Aad, LessSafeKey, UnboundKey};
use ring::digest;
use ring::pbkdf2;
use ring::rand::{SecureRandom, SystemRandom};
use serde::Serialize;
use std::num::NonZeroU32;

use crate::api::{self, PermanentArtifactRequest, PermanentManifest, PermanentManifestFile};
use crate::provenance::Provenance;

const DEFAULT_ARTIFACT_TITLE: &str = "Encrypted artifact";
const DEFAULT_ARTIFACT_DESCRIPTION: &str = "Encrypted HTML preview on artfct.";
const DEFAULT_ARTIFACT_THUMBNAIL: &str = "https://artfct.dev/og-image.svg";
const AES_IV_BYTES: usize = 12;
const SHARE_CODE_LENGTH: usize = 10;
const SHARE_CODE_ALPHABET: &[u8; 62] =
    b"0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
const MAX_HTML_BYTES: usize = 1024 * 1024;
const AES_KEY_BYTES: usize = 32;

/// Version tag for the salted KDF, carried in the share fragment. A fragment
/// without it predates the change and is opened with the legacy derivation.
pub const KDF_VERSION: u32 = 2;
const KDF_SALT_BYTES: usize = 16;

/// OWASP's floor for PBKDF2-HMAC-SHA256. The share code is ten base62
/// characters, so this iteration count is the whole of the attacker's per-guess
/// cost; the old derivation cost exactly one SHA-256.
const KDF_ITERATIONS: u32 = 210_000;

#[derive(Debug, Clone, Serialize)]
pub struct PreparedArtifactRequest {
    pub request: api::CreateArtifactRequest,
    pub fragment: String,
}

pub struct ArtifactPreparationOptions {
    pub tier: String,
    pub ttl_minutes: Option<u64>,
    pub preview_blurred: bool,
    pub provenance: Provenance,
}

pub fn prepare_artifact_request(
    html: &str,
    options: ArtifactPreparationOptions,
) -> Result<PreparedArtifactRequest> {
    let html = html.trim();

    if html.is_empty() {
        return Err(anyhow!("html is required"));
    }

    if html.len() > MAX_HTML_BYTES {
        return Err(anyhow!(
            "The html payload exceeds the configured size limit."
        ));
    }

    let title = normalize_metadata_value(extract_title(html), DEFAULT_ARTIFACT_TITLE);
    let description = normalize_metadata_value(
        extract_meta_content(html, &["name=\"description\"", "name='description'"])
            .or_else(|| extract_first_paragraph(html))
            .or_else(|| Some(title.clone())),
        DEFAULT_ARTIFACT_DESCRIPTION,
    );
    let thumbnail = normalize_metadata_value(
        extract_meta_content(html, &["property=\"og:image\"", "property='og:image'"])
            .or_else(|| extract_img_src(html)),
        DEFAULT_ARTIFACT_THUMBNAIL,
    );

    let share_code = random_share_code(SHARE_CODE_LENGTH);

    let rng = SystemRandom::new();

    let mut iv_bytes = [0u8; AES_IV_BYTES];
    rng.fill(&mut iv_bytes)
        .map_err(|_| anyhow!("Failed to generate encryption nonce"))?;

    let mut salt_bytes = [0u8; KDF_SALT_BYTES];
    rng.fill(&mut salt_bytes)
        .map_err(|_| anyhow!("Failed to generate key derivation salt"))?;

    let key_bytes = derive_aes_key_bytes(&share_code, &salt_bytes);
    let unbound_key = UnboundKey::new(&aead::AES_256_GCM, &key_bytes)
        .map_err(|_| anyhow!("Invalid encryption key"))?;
    let cipher = LessSafeKey::new(unbound_key);
    let nonce = aead::Nonce::assume_unique_for_key(iv_bytes);
    let mut ciphertext = html.as_bytes().to_vec();
    cipher
        .seal_in_place_append_tag(nonce, Aad::empty(), &mut ciphertext)
        .map_err(|_| anyhow!("Failed to encrypt HTML payload"))?;

    let request = api::CreateArtifactRequest {
        body_ciphertext_b64: URL_SAFE_NO_PAD.encode(ciphertext),
        body_iv_b64: URL_SAFE_NO_PAD.encode(iv_bytes),
        tier: options.tier,
        ttl_minutes: options.ttl_minutes,
        title,
        description,
        thumbnail,
        preview_blurred: options.preview_blurred,
        provenance: options.provenance,
    };

    Ok(PreparedArtifactRequest {
        request,
        fragment: format!(
            "#p={}&s={}&v={}",
            share_code,
            URL_SAFE_NO_PAD.encode(salt_bytes),
            KDF_VERSION
        ),
    })
}

pub fn prepare_permanent_artifact_request(
    html: &str,
    tier: String,
    provenance: Provenance,
) -> Result<PermanentArtifactRequest> {
    let html = html.trim();
    if html.is_empty() {
        return Err(anyhow!("html is required"));
    }
    let hash = digest::digest(&digest::SHA256, html.as_bytes());
    let sha256 = hash
        .as_ref()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect::<String>();
    let title = normalize_metadata_value(extract_title(html), DEFAULT_ARTIFACT_TITLE);
    let description = normalize_metadata_value(
        extract_meta_content(html, &["name=\"description\"", "name='description'"])
            .or_else(|| extract_first_paragraph(html))
            .or_else(|| Some(title.clone())),
        DEFAULT_ARTIFACT_DESCRIPTION,
    );
    let thumbnail = normalize_metadata_value(
        extract_meta_content(html, &["property=\"og:image\"", "property='og:image'"])
            .or_else(|| extract_img_src(html)),
        DEFAULT_ARTIFACT_THUMBNAIL,
    );

    Ok(PermanentArtifactRequest {
        mode: "permanent",
        tier,
        title,
        description,
        thumbnail,
        preview_blurred: false,
        manifest: PermanentManifest {
            entrypoint: "index.html".to_string(),
            files: vec![PermanentManifestFile {
                path: "index.html".to_string(),
                content_type: "text/html; charset=utf-8".to_string(),
                size_bytes: html.len(),
                sha256,
            }],
            external_origins: Vec::new(),
        },
        provenance,
    })
}

pub fn sha256_hex(bytes: &[u8]) -> String {
    digest::digest(&digest::SHA256, bytes)
        .as_ref()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// Derives the artifact key with PBKDF2-HMAC-SHA256 over a per-artifact salt.
///
/// The previous derivation was a single unsalted SHA-256 of the share code, so
/// the same code produced the same key for every artifact in every deployment,
/// and a guess cost one hash. Salting makes a precomputation against one
/// artifact useless against another; the iteration count makes each guess cost
/// `KDF_ITERATIONS`. The browser encryptor in `resources/js/lib/artifactCrypto.ts`
/// and the viewer the Worker serves must derive the same bytes -- the
/// `salted_derivation_matches_the_shared_test_vector` test pins that.
fn derive_aes_key_bytes(share_code: &str, salt: &[u8]) -> [u8; AES_KEY_BYTES] {
    let mut key = [0u8; AES_KEY_BYTES];

    pbkdf2::derive(
        pbkdf2::PBKDF2_HMAC_SHA256,
        NonZeroU32::new(KDF_ITERATIONS).expect("iteration count is non-zero"),
        salt,
        share_code.as_bytes(),
        &mut key,
    );

    key
}

fn random_share_code(length: usize) -> String {
    let mut bytes = vec![0u8; length];
    let rng = SystemRandom::new();
    rng.fill(&mut bytes)
        .expect("system random should be available");

    bytes
        .into_iter()
        .map(|byte| SHARE_CODE_ALPHABET[(byte as usize) % SHARE_CODE_ALPHABET.len()] as char)
        .collect()
}

fn normalize_metadata_value(value: Option<String>, default: &str) -> String {
    let normalized = value
        .map(|value| value.trim().to_string())
        .unwrap_or_default();

    if normalized.is_empty() {
        default.to_string()
    } else {
        normalized
    }
}

fn extract_title(html: &str) -> Option<String> {
    let head = &html[..html.len().min(8192)];
    let lower = head.to_lowercase();

    let tag_start = lower.find("<title")?;
    let content_start = lower[tag_start..].find('>')? + tag_start + 1;
    let content_end = lower[content_start..].find("</title>")? + content_start;

    let title = head[content_start..content_end].trim();
    if title.is_empty() {
        return None;
    }

    Some(title.to_string())
}

fn extract_meta_content(html: &str, needles: &[&str]) -> Option<String> {
    let head = &html[..html.len().min(8192)];
    let lower = head.to_lowercase();

    for needle in needles {
        let Some(index) = lower.find(needle) else {
            continue;
        };

        let tag_start = lower[..index].rfind("<meta")?;
        let tag_end = lower[index..].find('>')? + index;
        let tag = &head[tag_start..tag_end];

        if let Some(content) = extract_attribute_value(tag, "content") {
            return Some(content);
        }
    }

    None
}

fn extract_first_paragraph(html: &str) -> Option<String> {
    let head = &html[..html.len().min(8192)];
    let lower = head.to_lowercase();

    let start = lower.find("<p")?;
    let content_start = lower[start..].find('>')? + start + 1;
    let content_end = lower[content_start..].find("</p>")? + content_start;
    let content = strip_tags(&head[content_start..content_end])
        .trim()
        .to_string();

    if content.is_empty() {
        None
    } else {
        Some(content)
    }
}

fn extract_img_src(html: &str) -> Option<String> {
    let head = &html[..html.len().min(8192)];
    let lower = head.to_lowercase();
    let start = lower.find("<img")?;
    let end = lower[start..].find('>')? + start;
    let tag = &head[start..end];

    extract_attribute_value(tag, "src")
}

fn extract_attribute_value(tag: &str, attribute: &str) -> Option<String> {
    let lower = tag.to_lowercase();
    let needle = format!("{attribute}=");
    let index = lower.find(&needle)?;
    let value_start = index + needle.len();
    let bytes = tag.as_bytes();

    let quote = bytes.get(value_start).copied()?;
    if quote == b'"' || quote == b'\'' {
        let closing = tag[value_start + 1..].find(quote as char)? + value_start + 1;
        return Some(tag[value_start + 1..closing].trim().to_string());
    }

    let rest = &tag[value_start..];
    let end = rest.find(|c: char| c.is_whitespace()).unwrap_or(rest.len());
    Some(rest[..end].trim().trim_end_matches('>').to_string())
}

fn strip_tags(value: &str) -> String {
    let mut output = String::with_capacity(value.len());
    let mut inside_tag = false;

    for ch in value.chars() {
        match ch {
            '<' => inside_tag = true,
            '>' => inside_tag = false,
            _ if !inside_tag => output.push(ch),
            _ => {}
        }
    }

    output
}

#[cfg(test)]
mod tests {
    use std::path::Path;

    use base64::engine::general_purpose::URL_SAFE_NO_PAD;
    use base64::Engine;
    use ring::digest;

    use crate::provenance::build_cli_provenance;

    use super::{
        derive_aes_key_bytes, prepare_artifact_request, ArtifactPreparationOptions, KDF_SALT_BYTES,
        KDF_VERSION,
    };

    fn hex(bytes: &[u8]) -> String {
        bytes.iter().map(|byte| format!("{byte:02x}")).collect()
    }

    #[test]
    fn prepares_salted_share_fragment() {
        let prepared = prepare_artifact_request(
            "<html><head><title>Hello</title></head><body><p>World</p></body></html>",
            ArtifactPreparationOptions {
                tier: "ephemeral".to_string(),
                ttl_minutes: Some(5),
                preview_blurred: true,
                provenance: build_cli_provenance(Path::new("."), None),
            },
        )
        .expect("prepares artifact");

        assert!(
            prepared.fragment.starts_with("#p="),
            "fragment: {}",
            prepared.fragment
        );
        assert!(prepared.fragment.ends_with(&format!("&v={KDF_VERSION}")));
        assert!(!prepared.request.body_ciphertext_b64.is_empty());
        assert!(!prepared.request.body_iv_b64.is_empty());
        assert!(prepared.request.preview_blurred);

        let salt = prepared
            .fragment
            .split("&s=")
            .nth(1)
            .and_then(|rest| rest.split('&').next())
            .expect("fragment carries a salt");
        let decoded = URL_SAFE_NO_PAD.decode(salt).expect("salt is base64url");

        assert_eq!(decoded.len(), KDF_SALT_BYTES);
    }

    #[test]
    fn two_artifacts_sharing_a_code_do_not_share_a_key() {
        // The property the old derivation failed: the key was a pure function of
        // the share code, so one precomputation was reusable across every
        // artifact that ever used that code.
        let first = derive_aes_key_bytes("0123456789", &[0u8; KDF_SALT_BYTES]);
        let second = derive_aes_key_bytes("0123456789", &[1u8; KDF_SALT_BYTES]);

        assert_ne!(first, second, "the salt must change the derived key");
    }

    #[test]
    fn salted_derivation_matches_the_shared_test_vector() {
        // The vector was computed independently with Node's crypto.pbkdf2Sync
        // and is asserted again in the browser encryptor's test. The risk this
        // exists for is not a wrong KDF but Rust and JavaScript disagreeing on a
        // byte, which would make every new secure artifact unopenable and which
        // no end-to-end test here would catch.
        let key = derive_aes_key_bytes("0123456789", &[0u8; KDF_SALT_BYTES]);

        assert_eq!(
            hex(&key),
            "0b51d5dc36329bb22150ebeda005e4d2129a3f9d10a8408f34d8ab00cd61bb21"
        );
    }

    #[test]
    fn the_salted_key_is_not_the_legacy_hash() {
        // A fragment with no version tag is opened with the old derivation -- a
        // bare SHA-256 of the code -- which lives in the JavaScript decryptors,
        // not here: the CLI only ever encrypts, so it has no reason to carry a
        // legacy derivation of its own. What this asserts is the value those
        // decryptors must still produce, and that the new path cannot
        // accidentally reproduce it and make the version tag decoration.
        let legacy = digest::digest(&digest::SHA256, b"0123456789");
        let salted = derive_aes_key_bytes("0123456789", &[0u8; KDF_SALT_BYTES]);

        assert_eq!(
            hex(legacy.as_ref()),
            "84d89877f0d4041efb6bf91a16f0248f2fd573e6af05c19f96bedb9f882f7882",
            "links in the wild were minted with this value; it must not change"
        );
        assert_ne!(salted[..], legacy.as_ref()[..]);
    }
}
