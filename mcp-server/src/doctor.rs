use crate::ui;

pub fn print_report(api_base_url: &str) {
    ui::banner();
    ui::header("Diagnostics");
    eprintln!();
    ui::label_value("Artifact Engine", api_base_url);
    ui::label_value("MCP command", "artfct mcp serve");
    eprintln!();
}

#[cfg(test)]
mod tests {
    use super::print_report;

    #[test]
    fn runs_without_panic() {
        print_report("https://artfct.dev");
    }
}
