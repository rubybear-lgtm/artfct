pub fn doctor_report(api_base_url: &str) -> String {
    format!("Artifact Engine: {api_base_url}\nMCP server: artfct mcp serve\n")
}

#[cfg(test)]
mod tests {
    use super::doctor_report;

    #[test]
    fn includes_endpoint_and_mcp_command() {
        let report = doctor_report("https://artfct.dev");

        assert!(report.contains("Artifact Engine: https://artfct.dev"));
        assert!(report.contains("MCP server: artfct mcp serve"));
    }
}
