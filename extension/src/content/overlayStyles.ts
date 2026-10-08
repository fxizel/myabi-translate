/** Feuille de style de l'overlay, isolée dans un Shadow DOM : aucun style de MyAB ne s'y applique. */
export const OVERLAY_CSS = `
:host {
    all: initial;
    position: fixed;
    top: 0;
    left: 0;
    width: 0;
    height: 0;
    z-index: 2147483647;
    --ti-bg: #ffffff;
    --ti-fg: #1f2328;
    --ti-muted: #59636e;
    --ti-border: #d1d9e0;
    --ti-accent: #0969da;
    --ti-ok: #1a7f37;
    --ti-ok-bg: #dafbe1;
    --ti-warn: #9a6700;
    --ti-warn-bg: #fff8c5;
    --ti-danger: #cf222e;
    --ti-danger-bg: #ffebe9;
    --ti-info-bg: #ddf4ff;
    font: 13px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: var(--ti-fg);
}
*, *::before, *::after { box-sizing: border-box; }
.highlight {
    position: fixed;
    pointer-events: none;
    border: 2px solid var(--ti-accent);
    background: rgba(9, 105, 218, 0.12);
    border-radius: 3px;
    box-shadow: 0 0 0 1px #fff;
}
.panel {
    position: fixed;
    top: 16px;
    right: 16px;
    width: min(380px, calc(100vw - 32px));
    max-height: calc(100vh - 32px);
    display: flex;
    flex-direction: column;
    background: var(--ti-bg);
    color: var(--ti-fg);
    border: 1px solid var(--ti-border);
    border-radius: 10px;
    box-shadow: 0 8px 28px rgba(31, 35, 40, 0.28);
    overflow: hidden;
}
.header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 8px 8px 12px;
    border-bottom: 1px solid var(--ti-border);
    background: #f6f8fa;
}
.title { font-weight: 600; font-size: 12px; letter-spacing: .01em; }
.body { padding: 12px; overflow: auto; display: flex; flex-direction: column; gap: 10px; }
.label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--ti-muted); margin-bottom: 2px; }
.detected {
    font-size: 15px;
    font-weight: 600;
    overflow-wrap: anywhere;
    padding: 6px 8px;
    border-left: 3px solid var(--ti-accent);
    background: #f6f8fa;
}
.banner { padding: 6px 10px; border-radius: 6px; font-weight: 600; }
.banner.strong { background: var(--ti-ok-bg); color: var(--ti-ok); }
.banner.multiple { background: var(--ti-warn-bg); color: var(--ti-warn); }
.banner.none, .banner.noExact { background: #eaeef2; color: var(--ti-fg); }
.banner.error { background: var(--ti-danger-bg); color: var(--ti-danger); font-weight: 500; }
.hint { color: var(--ti-muted); font-size: 12px; }
.results { display: flex; flex-direction: column; gap: 8px; margin: 0; padding: 0; list-style: none; }
.card { border: 1px solid var(--ti-border); border-radius: 8px; padding: 8px 10px; }
.card.top { border-color: var(--ti-accent); }
.card-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.score { font-weight: 700; padding: 1px 6px; border-radius: 999px; font-size: 12px; }
.score.high { background: var(--ti-ok-bg); color: var(--ti-ok); }
.score.mid { background: var(--ti-warn-bg); color: var(--ti-warn); }
.score.low { background: #eaeef2; color: var(--ti-muted); }
.key {
    font: 600 13px ui-monospace, SFMono-Regular, Consolas, monospace;
    overflow-wrap: anywhere;
    user-select: all;
}
.chip { margin-left: auto; font-size: 11px; padding: 1px 7px; border-radius: 999px; background: #eaeef2; }
.chip.approved { background: var(--ti-ok-bg); color: var(--ti-ok); }
.chip.review, .chip.pending { background: var(--ti-warn-bg); color: var(--ti-warn); }
.chip.proposed { background: var(--ti-info-bg); color: var(--ti-accent); }
.chip.rejected { background: var(--ti-danger-bg); color: var(--ti-danger); }
dl { margin: 6px 0; display: grid; grid-template-columns: auto 1fr; gap: 2px 8px; }
dt { color: var(--ti-muted); font-weight: 600; }
dd { margin: 0; overflow-wrap: anywhere; }
.actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
button, a.button {
    font: inherit;
    font-size: 12px;
    color: var(--ti-fg);
    background: #f6f8fa;
    border: 1px solid var(--ti-border);
    border-radius: 6px;
    padding: 3px 8px;
    cursor: pointer;
    text-decoration: none;
    line-height: 1.4;
}
button:hover, a.button:hover { background: #eaeef2; }
button:focus-visible, a.button:focus-visible, a.link:focus-visible { outline: 2px solid var(--ti-accent); outline-offset: 1px; }
button:disabled { opacity: .6; cursor: default; }
button.primary, a.button.primary { background: var(--ti-accent); border-color: var(--ti-accent); color: #fff; }
button.primary:hover, a.button.primary:hover { background: #0550ae; }
button.icon { border: 0; background: transparent; font-size: 18px; line-height: 1; padding: 2px 8px; }
a.link { color: var(--ti-accent); }
.footer { padding-top: 4px; border-top: 1px solid var(--ti-border); }
.spinner {
    width: 14px; height: 14px; border-radius: 50%;
    border: 2px solid var(--ti-border); border-top-color: var(--ti-accent);
    animation: spin .8s linear infinite; display: inline-block; vertical-align: -2px; margin-right: 6px;
}
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spinner { animation: none; } }
`;
