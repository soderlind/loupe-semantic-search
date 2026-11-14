#!/usr/bin/env node
// Checks WCAG 2.2 contrast for the colour pairs the diagrams paint, using the
// renderer palette plus the palette.mjs theme colours. Exits 1 if a required pair fails.
import { paletteFor } from "@coldtea/pr-lens-renderer";
import { THEME_COLOURS } from "./palette.mjs";

const parse = (c) => {
  if (c.startsWith("#")) return [1, 3, 5].map((i) => parseInt(c.slice(i, i + 2), 16)).concat(1);
  const [r, g, b, a = 1] = c.match(/[\d.]+/g).map(Number);
  return [r, g, b, a];
};
const over = (fg, bg) => {
  const [r, g, b, a] = typeof fg === "string" ? parse(fg) : fg;
  return [r * a + bg[0] * (1 - a), g * a + bg[1] * (1 - a), b * a + bg[2] * (1 - a), 1];
};
const fade = (c, bg, opacity) => over([c[0], c[1], c[2], opacity], bg);
const luminance = (c) => {
  const [r, g, b] = c.slice(0, 3).map((x) => {
    x /= 255;
    return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

// Sequence views draw context participants at this opacity.
const CONTEXT_OPACITY = 0.82;
let failed = false;

for (const theme of ["light", "dark"]) {
  const p = paletteFor(theme);
  const c = THEME_COLOURS[theme];
  const bg = parse(p.background);
  const card = over(p.card, bg);
  const chip = over(p.chip, card);
  const lane = over(p.lane, bg);
  const pill = over(p.pill, bg);
  const faded = (colour) => fade(parse(colour), bg, CONTEXT_OPACITY);
  const fadedCard = fade(card, bg, CONTEXT_OPACITY);
  const fadedChip = fade(chip, bg, CONTEXT_OPACITY);

  const pairs = [
    ["text", "node title on card", parse(c.title), card, 4.5],
    ["text", "node title on faded context card", faded(c.title), fadedCard, 4.5],
    ["text", "node subtitle on card", parse(c.subtitle), card, 4.5],
    ["text", "node subtitle on faded context card", faded(c.subtitle), fadedCard, 4.5],
    ["text", "edge label on its pill", parse(c.label), pill, 4.5],
    ["text", "lane label on lane", parse(c.lane), lane, 4.5],
    ["text", "lane label on background", parse(c.lane), bg, 4.5],
    ["text", "neutral badge text on its chip", parse(c.badge), chip, 4.5],
    ["text", "neutral badge text on faded context chip", faded(c.badge), fadedChip, 4.5],
    ["text", "added badge text on its fill", parse(p.addedText), over(p.addedFill, card), 4.5],
    ["graphic", "node icon on chip", parse(c.icon), chip, 3],
    ["graphic", "node icon on faded context chip", faded(c.icon), fadedChip, 3],
    ["graphic", "edge on background", parse(c.edge), bg, 3],
    ["graphic", "edge on lane", parse(c.edge), lane, 3],
    ["graphic", "lifeline on background", parse(c.lifeline), bg, 3],
    ["decorative", "card border on background", over(p.cardBorder, bg), bg, 0],
  ];

  console.log(`\n${theme}`);
  for (const [kind, name, a, b, min] of pairs) {
    const r = ratio(a, b);
    const status = min === 0 ? "info" : r >= min ? "ok  " : "FAIL";
    if (status === "FAIL") failed = true;
    console.log(`${status} ${r.toFixed(2).padStart(5)}:1  ${min ? `(min ${min})` : "        "}  ${kind}: ${name}`);
  }
}

process.exit(failed ? 1 : 0);
