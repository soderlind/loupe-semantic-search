import { paletteFor } from "@coldtea/pr-lens-renderer";

// The renderer bakes its palette into each SVG and has no option to change it.
// These overrides raise the colours that fail WCAG 2.2 contrast in its defaults:
// muted text on faded (0.82 opacity) context cards (1.4.3, 4.5:1), and edge
// arrows and sequence lifelines (1.4.11, 3:1). Check with `node check-contrast.mjs`.
export const OVERRIDES = {
  light: { muted: "#4d5560", edge: "#7d8590", lifeline: "#848d97" },
  dark: { muted: "#a0a8b1", lifeline: "#5c646e" },
};

export const paletteWithOverrides = (theme) => ({ ...paletteFor(theme), ...OVERRIDES[theme] });

export const applyOverrides = (svg, theme) => {
  const base = paletteFor(theme);
  const overrides = OVERRIDES[theme] ?? {};
  let out = svg;
  for (const key of ["muted", "edge"]) {
    if (overrides[key]) {
      out = out.replaceAll(`"${base[key]}"`, `"${overrides[key]}"`);
    }
  }
  // The light lifeline shares its colour with card borders, so only lifeline elements change.
  if (overrides.lifeline) {
    out = out.replace(/(<line class="lifeline"[^>]*stroke=")([^"]+)(")/g, `$1${overrides.lifeline}$3`);
  }
  return out;
};
