<style>
  :root {
    --base03: #002b36; --base02: #073642; --base01: #586e75; --base00: #657b83;
    --base0: #839496; --base1: #93a1a1; --base2: #eee8d5; --base3: #fdf6e3;
    --yellow: #b58900; --orange: #cb4b16; --red: #dc322f; --magenta: #d33682;
    --violet: #6c71c4; --blue: #268bd2; --cyan: #2aa198; --green: #859900;
    --sans: "Instrument Sans", ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
    --mono: ui-monospace, "SFMono-Regular", "JetBrains Mono", Menlo, Consolas, monospace;
    --rule: #e3dcc6;
  }
  * { box-sizing: border-box; }
  html { -webkit-text-size-adjust: 100%; }
  body {
    margin: 0; background: var(--base3); color: var(--base00);
    font-family: var(--sans); font-size: 17px; line-height: 1.7;
    font-synthesis-weight: none;
  }
  .wrap { max-width: 46rem; margin: 0 auto; padding: 3rem 1.5rem 5rem; }
  a { color: var(--blue); text-decoration-thickness: 1px; text-underline-offset: 2px; }
  a:hover { color: var(--cyan); }

  /* ── masthead ─────────────────────────────────────────────── */
  .mark { font-family: var(--mono); font-size: 13px; line-height: 1.05; letter-spacing: 0; margin: 0 0 2.5rem; white-space: pre; }
  .mark span { display: inline-block; }
  .m1 { color: var(--yellow); } .m2 { color: var(--orange); } .m3 { color: var(--red); }
  .m4 { color: var(--magenta); } .m5 { color: var(--violet); } .m6 { color: var(--blue); }
  .brand { font-family: var(--mono); font-size: 12px; color: var(--base1); letter-spacing: .08em; text-transform: uppercase; margin-left: .6rem; }

  /* ── title block ──────────────────────────────────────────── */
  .kicker { font-family: var(--mono); font-size: 12px; letter-spacing: .08em; text-transform: uppercase; color: var(--base1); margin: 0 0 .9rem; }
  h1 { font-size: clamp(2rem, 5vw, 2.9rem); line-height: 1.12; letter-spacing: -.02em; color: var(--base02); margin: 0 0 1rem; font-weight: 700; }
  .meta { font-family: var(--mono); font-size: 12.5px; color: var(--base1); border-bottom: 1px solid var(--rule); padding-bottom: 1.5rem; margin-bottom: 1.5rem; }
  .meta b { color: var(--base00); font-weight: 500; }
  .lead { font-size: 1.2rem; line-height: 1.6; color: var(--base01); margin: 0 0 2.5rem; }
  .lead::first-letter { color: var(--orange); }

  /* ── toc ──────────────────────────────────────────────────── */
  nav.toc { background: var(--base2); border-left: 3px solid var(--cyan); padding: 1.1rem 1.3rem; margin: 0 0 3rem; }
  nav.toc p { font-family: var(--mono); font-size: 11.5px; letter-spacing: .08em; text-transform: uppercase; color: var(--base1); margin: 0 0 .5rem; }
  nav.toc ol { margin: 0; padding-left: 1.2rem; font-size: .95rem; }
  nav.toc li { margin: .2rem 0; }

  /* ── body ─────────────────────────────────────────────────── */
  h2 { font-size: 1.42rem; line-height: 1.25; color: var(--base02); margin: 3rem 0 .9rem; padding-left: .7rem; border-left: 3px solid var(--yellow); font-weight: 650; }
  h3 { font-size: 1.08rem; color: var(--base01); margin: 2rem 0 .6rem; font-weight: 650; }
  p { margin: 0 0 1.15rem; }
  ul, ol { margin: 0 0 1.15rem; padding-left: 1.3rem; }
  li { margin: .35rem 0; }
  strong { color: var(--base01); font-weight: 650; }
  code { font-family: var(--mono); font-size: .875em; background: var(--base2); color: var(--base02); padding: .12em .38em; border-radius: 3px; }
  pre { background: var(--base02); color: var(--base2); padding: 1rem 1.15rem; overflow-x: auto; font-size: .85rem; line-height: 1.6; border-radius: 4px; }
  pre code { background: none; color: inherit; padding: 0; }
  blockquote { margin: 1.5rem 0; padding: .2rem 0 .2rem 1.1rem; border-left: 3px solid var(--violet); color: var(--base01); }
  hr { border: 0; border-top: 1px solid var(--rule); margin: 3rem 0; }
  .callout { background: var(--base2); border-left: 3px solid var(--orange); padding: 1rem 1.2rem; margin: 1.75rem 0; font-size: .97rem; }
  .callout p:last-child { margin-bottom: 0; }
  table.t { width: 100%; border-collapse: collapse; margin: 1.5rem 0; font-size: .93rem; }
  table.t th { text-align: left; font-family: var(--mono); font-size: 11.5px; letter-spacing: .06em; text-transform: uppercase; color: var(--base1); border-bottom: 2px solid var(--rule); padding: .5rem .7rem .5rem 0; }
  table.t td { border-bottom: 1px solid var(--rule); padding: .6rem .7rem .6rem 0; vertical-align: top; }

  /* ── limits ───────────────────────────────────────────────── */
  section.limits { background: var(--base2); border: 1px solid var(--rule); border-top: 3px solid var(--red); padding: 1.4rem 1.5rem 1.1rem; margin: 3rem 0; }
  section.limits h2 { border: 0; padding: 0; margin: 0 0 .8rem; font-size: 1.15rem; }
  section.limits ul { margin-bottom: 0; }

  /* ── faq ──────────────────────────────────────────────────── */
  section.faq { margin: 3.5rem 0 0; }
  section.faq > h2 { border-left-color: var(--green); }
  details.q { border-bottom: 1px solid var(--rule); padding: .85rem 0; }
  details.q summary { cursor: pointer; font-weight: 600; color: var(--base02); list-style: none; }
  details.q summary::-webkit-details-marker { display: none; }
  details.q summary::before { content: "? "; font-family: var(--mono); color: var(--green); }
  details.q[open] summary::before { content: "↓ "; }
  details.q p { margin: .7rem 0 0; color: var(--base00); }

  /* ── byline ───────────────────────────────────────────────── */
  .byline { display: flex; gap: 1rem; align-items: flex-start; border-top: 1px solid var(--rule); margin-top: 3.5rem; padding-top: 1.5rem; }
  .byline .av { flex: 0 0 44px; height: 44px; border-radius: 50%; background: var(--base2); border: 1px solid var(--rule); display: grid; place-items: center; font-family: var(--mono); font-size: 14px; color: var(--base1); }
  .byline .who { font-weight: 650; color: var(--base02); }
  .byline .bio { font-size: .93rem; color: var(--base1); margin: .15rem 0 0; }

  /* ── cta ──────────────────────────────────────────────────── */
  .cta { background: var(--base02); color: var(--base2); padding: 2rem 1.9rem; margin: 3rem 0 0; border-radius: 4px; }
  .cta h2 { color: var(--base3); border: 0; padding: 0; margin: 0 0 .6rem; font-size: 1.3rem; }
  .cta p { color: var(--base1); margin: 0 0 1.3rem; }
  .cta a.btn { display: inline-block; background: var(--yellow); color: var(--base03); font-weight: 650; text-decoration: none; padding: .7rem 1.3rem; border-radius: 3px; font-size: .97rem; }
  .cta a.btn:hover { background: var(--orange); color: var(--base3); }

  footer { margin-top: 3rem; font-family: var(--mono); font-size: 11.5px; color: var(--base1); border-top: 1px solid var(--rule); padding-top: 1.2rem; }

  @media (max-width: 620px) {
    .wrap { padding: 2rem 1.15rem 3.5rem; }
    .mark { font-size: 10px; }
    .lead { font-size: 1.1rem; }
  }
  @media print {
    body { background: #fff; }
    nav.toc, .cta a.btn { display: none; }
  }

  /* The review copy shows where a figure sits; the site mounts a component. */
  figure.fig { margin: 2rem 0; padding: 1rem 1.15rem; border: 1px dashed var(--rule); border-radius: 4px; font-family: var(--mono); font-size: 12px; color: var(--base1); }
</style>
