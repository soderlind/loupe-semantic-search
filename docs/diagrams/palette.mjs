import { paletteFor } from "@coldtea/pr-lens-renderer";

// The renderer bakes its grey palette into each SVG and has no option to change it.
// These overrides recolour the diagrams with GitHub Light Default and GitHub Dark Default
// syntax colours (primer/github-vscode-theme, @primer/primitives 7.10.0), mapped by role:
//
//   node title      entity.name.function   purple
//   node subtitle   constant / support     blue
//   edge label      string                 dark blue (light) / pale blue (dark)
//   lane label      keyword                red
//   neutral badge   keyword                deeper red (light) / paler red (dark)
//   node icon       variable               orange
//   edges, arrows   accent (textLink)      blue
//   lifelines       comment                grey
//
// Light titles use purple[6] instead of purple[5]: purple[5] falls to 3.7:1 on the faded
// (0.82 opacity) sequence participants, and badges use a deeper red than lane labels so they
// pass on faded chips. Check every pair with `node check-contrast.mjs`.
export const THEME_COLOURS = {
  light: {
    title: "#6639ba",
    subtitle: "#0550ae",
    label: "#0a3069",
    lane: "#cf222e",
    badge: "#a40e26",
    icon: "#953800",
    edge: "#0969da",
    lifeline: "#6e7781",
  },
  dark: {
    title: "#d2a8ff",
    subtitle: "#79c0ff",
    label: "#a5d6ff",
    lane: "#ff7b72",
    badge: "#ffa198",
    icon: "#ffa657",
    edge: "#58a6ff",
    lifeline: "#8b949e",
  },
};

// SVG class -> [attribute, role]. Classes are matched as whole tokens.
const CLASS_ROLES = [
  ["ntitle", "fill", "title"],
  ["nsub", "fill", "subtitle"],
  ["ltext", "fill", "label"],
  ["lanelabel", "fill", "lane"],
  ["glyph", "fill", "icon"],
  ["glyph-stroke", "stroke", "icon"],
  ["lifeline", "stroke", "lifeline"],
];

const recolourClass = (svg, className, attribute, colour) =>
  svg.replace(
    new RegExp(`(<[a-z]+ [^>]*?class="(?:[^"]* )?${className}(?: [^"]*)?"[^>]*?\\b${attribute}=")#[0-9a-fA-F]{6}(")`, "g"),
    `$1${colour}$2`,
  );

export const applyOverrides = (svg, theme) => {
  const colours = THEME_COLOURS[theme];
  if (!colours) return svg;
  // Edges, arrowhead markers and animated flow dots share the renderer's edge colour.
  // Replace it first: a class colour may equal it (dark lifeline vs. renderer edge).
  let out = svg.replaceAll(`"${paletteFor(theme).edge}"`, `"${colours.edge}"`);
  for (const [className, attribute, role] of CLASS_ROLES) {
    out = recolourClass(out, className, attribute, colours[role]);
  }
  return out.replace(
    /(<g class="bdg bdg-neutral"><rect [^>]*\/><text [^>]*?\bfill=")#[0-9a-fA-F]{6}(")/g,
    `$1${colours.badge}$2`,
  );
};
