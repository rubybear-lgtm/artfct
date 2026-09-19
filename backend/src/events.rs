//! Worker -> Laravel fire-and-forget event channel. Signed with
//! `HMAC-SHA256(secret, timestamp + "." + rawBody)`; the receiver is
//! `POST /internal/worker-events`. Best effort: a failed send is logged and
//! dropped, never surfaced to the caller.

use crate::store::hmac_sha256;
use serde_json::{json, Value};
use uuid::Uuid;

pub const EVENT_SECRET_ENV: &str = "ARTFCT_WORKER_EVENT_SECRET";
pub const EVENT_URL_ENV: &str = "ARTFCT_WORKER_EVENT_URL";

pub struct SignedEvent {
    pub body: String,
    pub timestamp: i64,
    pub signature: String,
}

pub fn sign(secret: &str, timestamp: i64, body: &str) -> String {
    hmac_sha256(secret.as_bytes(), format!("{timestamp}.{body}").as_bytes())
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// `org_id` must be the verified credential's org, never a request field.
pub fn build_event(
    secret: &str,
    event_type: &str,
    org_id: &str,
    occurred_at: &str,
    timestamp: i64,
    data: Value,
) -> SignedEvent {
    let body = json!({
        "id": Uuid::new_v4().to_string(),
        "type": event_type,
        "org_id": org_id,
        "occurred_at": occurred_at,
        "data": data,
    })
    .to_string();
    let signature = sign(secret, timestamp, &body);
    SignedEvent {
        body,
        timestamp,
        signature,
    }
}

/// Sends the event; any failure is logged and dropped.
pub async fn send(url: &str, event: SignedEvent) {
    let result: worker::Result<()> = async {
        let headers = worker::Headers::new();
        headers.set("Content-Type", "application/json")?;
        headers.set("X-Artfct-Timestamp", &event.timestamp.to_string())?;
        headers.set("X-Artfct-Signature", &event.signature)?;
        let mut init = worker::RequestInit::new();
        init.with_method(worker::Method::Post)
            .with_headers(headers)
            .with_body(Some(worker::wasm_bindgen::JsValue::from_str(&event.body)));
        let request = worker::Request::new_with_init(url, &init)?;
        worker::Fetch::Request(request).send().await?;
        Ok(())
    }
    .await;
    if let Err(error) = result {
        worker::console_error!("worker event send failed: {error}");
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn signature_matches_reference_vector() {
        // Same vector the Laravel side computes with hash_hmac('sha256', ...).
        assert_eq!(
            sign("test-secret", 1_700_000_000, "{\"a\":1}"),
            "8cb2c3355fca388e9ac2caec004f4d5d7045d74937ab5faad61dc11682247a9f"
        );
    }

    #[test]
    fn event_org_comes_from_credential_not_body() {
        let event = build_event("s", "artifact.created", "credential-org", "t", 1, json!({}));
        let parsed: Value = serde_json::from_str(&event.body).unwrap();
        assert_eq!(parsed["org_id"], "credential-org");
    }
}
