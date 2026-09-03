use anyhow::{anyhow, Context, Result};
use serde::{Deserialize, Serialize, Serializer};

use crate::provenance::Provenance;

#[derive(Debug, Clone)]
pub struct CreateArtifactRequest {
    pub body_ciphertext_b64: String,
    pub body_iv_b64: String,
    pub tier: String,
    pub ttl_minutes: Option<u64>,
    pub title: String,
    pub description: String,
    pub thumbnail: String,
    pub preview_blurred: bool,
    pub provenance: Provenance,
}

#[derive(Serialize)]
struct EphemeralArtifactRequest<'a> {
    mode: &'static str,
    body_ciphertext_b64: &'a str,
    body_iv_b64: &'a str,
    tier: &'a str,
    #[serde(skip_serializing_if = "Option::is_none")]
    ttl_minutes: Option<u64>,
    title: &'a str,
    description: &'a str,
    thumbnail: &'a str,
    preview_blurred: bool,
    provenance: &'a Provenance,
}

impl CreateArtifactRequest {
    fn ephemeral_payload(&self) -> EphemeralArtifactRequest<'_> {
        EphemeralArtifactRequest {
            mode: "ephemeral",
            body_ciphertext_b64: &self.body_ciphertext_b64,
            body_iv_b64: &self.body_iv_b64,
            tier: &self.tier,
            ttl_minutes: self.ttl_minutes,
            title: &self.title,
            description: &self.description,
            thumbnail: &self.thumbnail,
            preview_blurred: self.preview_blurred,
            provenance: &self.provenance,
        }
    }
}

impl Serialize for CreateArtifactRequest {
    fn serialize<S>(&self, serializer: S) -> std::result::Result<S::Ok, S::Error>
    where
        S: Serializer,
    {
        self.ephemeral_payload().serialize(serializer)
    }
}

#[derive(Debug, Deserialize)]
pub struct CreateArtifactResponse {
    pub id: String,
    pub url: String,
    pub tier: String,
    pub expires_at: String,
    pub title: String,
    pub description: String,
    pub thumbnail: String,
    pub preview_blurred: bool,
}

pub fn artifact_endpoint(api_base_url: &str) -> String {
    format!("{}/v1/artifacts", api_base_url.trim_end_matches('/'))
}

pub async fn deploy_artifact(
    client: &reqwest::Client,
    api_base_url: &str,
    request: &CreateArtifactRequest,
) -> Result<CreateArtifactResponse> {
    deploy_artifact_payload(client, api_base_url, request).await
}

pub async fn deploy_artifact_payload<T: Serialize + ?Sized>(
    client: &reqwest::Client,
    api_base_url: &str,
    request: &T,
) -> Result<CreateArtifactResponse> {
    let response = client
        .post(artifact_endpoint(api_base_url))
        .json(request)
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();
    let body = response
        .text()
        .await
        .context("Failed to read Artifact Engine response")?;

    if !status.is_success() {
        return Err(anyhow!("Artifact Engine returned {status}: {body}"));
    }

    serde_json::from_str(&body).context("Artifact Engine returned an invalid response")
}

pub async fn delete_artifact(client: &reqwest::Client, api_base_url: &str, id: &str) -> Result<()> {
    let url = format!("{}/{}", artifact_endpoint(api_base_url), id);
    let response = client
        .delete(&url)
        .send()
        .await
        .context("Failed to reach Artifact Engine")?;
    let status = response.status();

    if status == reqwest::StatusCode::NO_CONTENT {
        return Ok(());
    }

    let body = response
        .text()
        .await
        .context("Failed to read Artifact Engine response")?;
    Err(anyhow!("Artifact Engine returned {status}: {body}"))
}

#[cfg(test)]
pub(crate) mod tests {
    use std::fs;
    use std::path::Path;

    use anyhow::{anyhow, Context, Result};
    use serde_json::{json, Map, Value};

    use super::{artifact_endpoint, CreateArtifactRequest};
    use crate::artifact_crypto;
    use crate::provenance::build_cli_provenance;

    #[test]
    fn builds_artifact_endpoint_without_double_slash() {
        assert_eq!(
            artifact_endpoint("https://artfct.dev/"),
            "https://artfct.dev/v1/artifacts"
        );
    }

    #[test]
    fn serializes_create_artifact_payload() {
        let provenance = build_cli_provenance(Path::new("."), None);
        let request = CreateArtifactRequest {
            body_ciphertext_b64: "ciphertext".to_string(),
            body_iv_b64: "nonce".to_string(),
            tier: "ephemeral".to_string(),
            ttl_minutes: Some(5),
            title: "Hello".to_string(),
            description: "World".to_string(),
            thumbnail: "https://example.com/thumb.png".to_string(),
            preview_blurred: true,
            provenance,
        };

        let serialized = serde_json::to_value(request).expect("serializes request");
        let mut expected = json!({
            "mode": "ephemeral",
            "body_ciphertext_b64": "ciphertext",
            "body_iv_b64": "nonce",
            "tier": "ephemeral",
            "ttl_minutes": 5,
            "title": "Hello",
            "description": "World",
            "thumbnail": "https://example.com/thumb.png",
            "preview_blurred": true,
        });
        expected["provenance"] = serde_json::to_value(build_cli_provenance(Path::new("."), None))
            .expect("serializes provenance");

        assert_eq!(serialized, expected);
    }

    #[test]
    fn cli_create_request_validates_against_contract() {
        let prepared = artifact_crypto::prepare_artifact_request(
            "<html><head><title>Hello</title></head><body><p>World</p></body></html>",
            artifact_crypto::ArtifactPreparationOptions {
                tier: "ephemeral".to_string(),
                ttl_minutes: None,
                preview_blurred: true,
                provenance: build_cli_provenance(Path::new("."), None),
            },
        )
        .expect("prepares CLI artifact request");
        let payload = serde_json::to_value(prepared.request).expect("serializes CLI request");

        assert!(!payload
            .as_object()
            .expect("CLI payload should be a JSON object")
            .contains_key("ttl_minutes"));

        validate_contract_schema(&payload, "EphemeralArtifactRequest")
            .expect("CLI create request matches EphemeralArtifactRequest");
    }

    pub(crate) fn validate_contract_schema(instance: &Value, schema_name: &str) -> Result<()> {
        let contract_path = format!("{}/../openapi/artfct.yaml", env!("CARGO_MANIFEST_DIR"));
        let contract_source = fs::read_to_string(&contract_path)
            .with_context(|| format!("failed to read {contract_path}"))?;
        let contract: Value = serde_json::from_str(&contract_source)
            .context("OpenAPI contract must be JSON-compatible YAML")?;
        let schema = contract
            .pointer(&format!("/components/schemas/{schema_name}"))
            .ok_or_else(|| anyhow!("schema {schema_name} is missing from the OpenAPI contract"))?;

        validate_schema(instance, schema, &contract, schema_name)
    }

    fn validate_schema(
        instance: &Value,
        schema: &Value,
        contract: &Value,
        location: &str,
    ) -> Result<()> {
        if let Some(reference) = schema.get("$ref").and_then(Value::as_str) {
            let pointer = reference
                .strip_prefix('#')
                .ok_or_else(|| anyhow!("unsupported non-local reference {reference}"))?;
            let resolved = contract
                .pointer(pointer)
                .ok_or_else(|| anyhow!("unresolved schema reference {reference}"))?;
            return validate_schema(instance, resolved, contract, location);
        }

        if let Some(all_of) = schema.get("allOf").and_then(Value::as_array) {
            for nested in all_of {
                validate_schema(instance, nested, contract, location)?;
            }
        }

        if let Some(one_of) = schema.get("oneOf").and_then(Value::as_array) {
            let matches = one_of
                .iter()
                .filter(|nested| validate_schema(instance, nested, contract, location).is_ok())
                .count();
            if matches != 1 {
                return Err(anyhow!(
                    "{location} matched {matches} oneOf branches instead of exactly one"
                ));
            }
        }

        if let Some(expected) = schema.get("const") {
            if instance != expected {
                return Err(anyhow!("{location} does not match const {expected}"));
            }
        }

        if let Some(allowed) = schema.get("enum").and_then(Value::as_array) {
            if !allowed.contains(instance) {
                return Err(anyhow!("{location} is not one of {allowed:?}"));
            }
        }

        if let Some(schema_type) = schema.get("type") {
            let type_matches = match schema_type {
                Value::String(expected) => instance_matches_type(instance, expected),
                Value::Array(expected) => expected.iter().any(|candidate| {
                    candidate
                        .as_str()
                        .is_some_and(|expected| instance_matches_type(instance, expected))
                }),
                _ => false,
            };
            if !type_matches {
                return Err(anyhow!(
                    "{location} has type {}, expected {schema_type}",
                    instance_type(instance)
                ));
            }
        }

        if instance.is_null() {
            return Ok(());
        }

        if let Some(value) = instance.as_str() {
            validate_string(value, schema, location)?;
        }

        if let Some(value) = instance.as_f64() {
            validate_number(value, schema, location)?;
        }

        if let Some(object) = instance.as_object() {
            validate_object(object, schema, contract, location)?;
        }

        if let (Some(items), Some(values)) = (schema.get("items"), instance.as_array()) {
            for (index, value) in values.iter().enumerate() {
                validate_schema(value, items, contract, &format!("{location}[{index}]"))?;
            }
        }

        Ok(())
    }

    fn validate_string(value: &str, schema: &Value, location: &str) -> Result<()> {
        let length = value.chars().count() as u64;
        if let Some(minimum) = schema.get("minLength").and_then(Value::as_u64) {
            if length < minimum {
                return Err(anyhow!("{location} is shorter than {minimum} characters"));
            }
        }
        if let Some(maximum) = schema.get("maxLength").and_then(Value::as_u64) {
            if length > maximum {
                return Err(anyhow!("{location} is longer than {maximum} characters"));
            }
        }
        if schema.get("format").and_then(Value::as_str) == Some("uri") {
            let valid_scheme = value
                .split_once(':')
                .map(|(scheme, _)| {
                    !scheme.is_empty()
                        && scheme.chars().all(|character| {
                            character.is_ascii_alphanumeric() || "+-.".contains(character)
                        })
                })
                .unwrap_or(false);
            if !valid_scheme {
                return Err(anyhow!("{location} is not a URI"));
            }
        }

        Ok(())
    }

    fn validate_number(value: f64, schema: &Value, location: &str) -> Result<()> {
        if let Some(minimum) = schema.get("minimum").and_then(Value::as_f64) {
            if value < minimum {
                return Err(anyhow!("{location} is less than {minimum}"));
            }
        }
        if let Some(maximum) = schema.get("maximum").and_then(Value::as_f64) {
            if value > maximum {
                return Err(anyhow!("{location} is greater than {maximum}"));
            }
        }

        Ok(())
    }

    fn validate_object(
        object: &Map<String, Value>,
        schema: &Value,
        contract: &Value,
        location: &str,
    ) -> Result<()> {
        if let Some(required) = schema.get("required").and_then(Value::as_array) {
            for field in required.iter().filter_map(Value::as_str) {
                if !object.contains_key(field) {
                    return Err(anyhow!("{location}.{field} is required"));
                }
            }
        }

        let properties = schema.get("properties").and_then(Value::as_object);
        if let Some(properties) = properties {
            for (field, value) in object {
                if let Some(field_schema) = properties.get(field) {
                    validate_schema(
                        value,
                        field_schema,
                        contract,
                        &format!("{location}.{field}"),
                    )?;
                } else if schema.get("additionalProperties") == Some(&Value::Bool(false)) {
                    return Err(anyhow!("{location}.{field} is not allowed"));
                }
            }
        }

        Ok(())
    }

    fn instance_matches_type(instance: &Value, expected: &str) -> bool {
        match expected {
            "null" => instance.is_null(),
            "object" => instance.is_object(),
            "array" => instance.is_array(),
            "string" => instance.is_string(),
            "boolean" => instance.is_boolean(),
            "integer" => instance.as_i64().is_some() || instance.as_u64().is_some(),
            "number" => instance.is_number(),
            _ => false,
        }
    }

    fn instance_type(instance: &Value) -> &'static str {
        match instance {
            Value::Null => "null",
            Value::Bool(_) => "boolean",
            Value::Number(number) if number.is_i64() || number.is_u64() => "integer",
            Value::Number(_) => "number",
            Value::String(_) => "string",
            Value::Array(_) => "array",
            Value::Object(_) => "object",
        }
    }
}
