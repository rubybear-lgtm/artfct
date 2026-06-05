use console::{style, Color};
use indicatif::{ProgressBar, ProgressStyle};
use std::time::Duration;

pub fn header(title: &str) {
    eprintln!("\n{}", style(title).bold());
}

pub fn success(msg: impl AsRef<str>) {
    eprintln!("{} {}", style("✓").green().bold(), msg.as_ref());
}

pub fn error(msg: impl AsRef<str>) {
    eprintln!("{} {}", style("✗").red().bold(), msg.as_ref());
}

pub fn warn(msg: impl AsRef<str>) {
    eprintln!("{} {}", style("!").yellow().bold(), msg.as_ref());
}

pub fn item_success(msg: impl AsRef<str>) {
    eprintln!("  {} {}", style("✓").green(), msg.as_ref());
}

pub fn item_skip(msg: impl AsRef<str>) {
    eprintln!("  {} {}", style("~").dim(), msg.as_ref());
}

pub fn item_error(msg: impl AsRef<str>) {
    eprintln!("  {} {}", style("✗").red(), msg.as_ref());
}

pub fn label_value(label: &str, value: &str) {
    eprintln!("  {:<20}{}", style(label).dim(), value);
}

pub fn spinner(msg: impl Into<String>) -> ProgressBar {
    let pb = ProgressBar::new_spinner();
    pb.set_style(
        ProgressStyle::default_spinner()
            .tick_strings(&["⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"])
            .template("{spinner:.cyan} {msg}")
            .unwrap(),
    );
    pb.enable_steady_tick(Duration::from_millis(80));
    pb.set_message(msg.into());
    pb
}

pub fn finish_success(pb: ProgressBar, msg: impl AsRef<str>) {
    pb.finish_and_clear();
    success(msg);
}

pub fn finish_error(pb: ProgressBar, msg: impl AsRef<str>) {
    pb.finish_and_clear();
    error(msg);
}

pub fn banner() {
    // "artfct" in figlet small font, colored with a Solarized warm→cool gradient
    let art = [
        r"  __ _ _ __| |_ / _| ___| |_ ",
        r" / _` | '__| __| |_ / __| __|",
        r"| (_| | |  | |_|  _| (__| |_ ",
        r" \__,_|_|   \__|_|  \___|\__|",
    ];

    // Solarized accent palette: yellow → orange → red → magenta → violet → blue → cyan
    let palette: &[u8] = &[136, 166, 160, 168, 61, 32, 37];
    let width = 31usize;

    eprintln!();
    for line in &art {
        let colored: String = line
            .chars()
            .enumerate()
            .map(|(col, ch)| {
                let idx = (col * palette.len() / width).min(palette.len() - 1);
                style(ch.to_string())
                    .bold()
                    .fg(Color::Color256(palette[idx]))
                    .to_string()
            })
            .collect();
        eprintln!("{colored}");
    }
    eprintln!();
}
