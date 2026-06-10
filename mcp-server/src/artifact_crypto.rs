use anyhow::{anyhow, Result};
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use ring::aead::{self, Aad, LessSafeKey, UnboundKey};
use ring::digest;
use ring::rand::{SecureRandom, SystemRandom};
use serde::{Deserialize, Serialize};

use crate::api;

const DEFAULT_ARTIFACT_TITLE: &str = "Encrypted artifact";
const DEFAULT_ARTIFACT_DESCRIPTION: &str = "Encrypted HTML preview on artfct.";
const DEFAULT_ARTIFACT_THUMBNAIL: &str = "https://artfct.dev/og-image.svg";
const AES_IV_BYTES: usize = 12;
const SHARE_CODE_LENGTH: usize = 10;
const SHARE_CODE_ALPHABET: &[u8; 62] =
    b"0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
const MAX_HTML_BYTES: usize = 1024 * 1024;
const AES_KEY_BYTES: usize = 32;

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct PreparedArtifactRequest {
    pub request: api::CreateArtifactRequest,
    pub fragment: String,
}

pub fn prepare_artifact_request(
    html: &str,
    tier: String,
    ttl_minutes: Option<u64>,
    preview_blurred: bool,
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

    let key_bytes = derive_aes_key_bytes(&share_code);
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
        tier,
        ttl_minutes,
        title,
        description,
        thumbnail,
        preview_blurred,
    };

    Ok(PreparedArtifactRequest {
        request,
        fragment: format!("#{share_code}"),
    })
}

fn derive_aes_key_bytes(share_code: &str) -> [u8; 32] {
    let digest = digest::digest(&digest::SHA256, share_code.as_bytes());
    let mut key = [0u8; AES_KEY_BYTES];
    key.copy_from_slice(digest.as_ref());
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
    use super::prepare_artifact_request;

    #[test]
    fn prepares_compact_share_fragment() {
        let prepared = prepare_artifact_request(
            "<html><head><title>Hello</title></head><body><p>World</p></body></html>",
            "ephemeral".to_string(),
            Some(5),
            true,
        )
        .expect("prepares artifact");

        assert!(prepared.fragment.starts_with('#'));
        assert_eq!(prepared.fragment.len(), 11);
        assert!(!prepared.request.body_ciphertext_b64.is_empty());
        assert!(!prepared.request.body_iv_b64.is_empty());
        assert!(prepared.request.preview_blurred);
    }
}
