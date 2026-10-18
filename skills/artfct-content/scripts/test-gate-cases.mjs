/**
 * Shared publish-gate cases for test-gate.mjs (Pi) and test-omp-gate.mjs
 * (OMP). One definition so a lint-rule change updates both suites together.
 *
 * Each case is [name, input, expectBlock]. `input` may reference the `clean`
 * fixture HTML; callers pass it in.
 */
export function gateCases(clean) {
	return [
		["a clean content draft is allowed", { html: clean }, false],
		["an artifact without the marker passes untouched", { html: "<html><body>a dashboard</body></html>" }, false],
		["the explicit skip marker is honoured", { html: clean.replace("data-surface=", 'data-lint="skip" data-surface=') }, false],
		["a bundle entrypoint is linted", { files: [{ path: "index.html", content: clean }], entrypoint: "index.html" }, false],
		["a bundle without a matching entrypoint passes", { files: [{ path: "other.html", content: clean }], entrypoint: "index.html" }, false],
		["a non-goal claim is blocked", { html: clean.replace("It does not write", "Artfct is an artifact store. It does not write") }, true],
		["an unfilled token is blocked", { html: clean.replace("Every AI tool on your team starts from the same store", "{{TITLE}}") }, true],
		["a retired connection method is blocked", { html: clean.replace("Members install nothing.", "Members use the CLI.") }, true],
		["an unsupportable absolute is blocked", { html: clean.replace("Members install nothing.", "Artfct guarantees nothing is ever lost.") }, true],
		["a lab disparagement claim is blocked", { html: clean.replace("Members install nothing.", "Claude and Copilot give you no controls.") }, true],
	];
}
