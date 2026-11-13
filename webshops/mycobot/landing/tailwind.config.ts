import type { Config } from 'tailwindcss';

// The design system's tokens, verbatim (src/design-system/tokens/tailwind.preset.js).
// It mirrors tokens/*.css — change both together.
// eslint-disable-next-line @typescript-eslint/no-require-imports
const tfbPreset = require('./src/design-system/tokens/tailwind.preset.js');

const config: Config = {
  presets: [tfbPreset],
  content: ['./src/**/*.{ts,tsx}'],
  // The design system ships its own reset (tokens/base.css): body font, heading
  // weights, link colours, focus rings. Tailwind's preflight would load after it
  // and undo all of that, so it stays off — utilities still work.
  corePlugins: { preflight: false },
  // Arabic is a full mirror driven by dir="rtl" on <html>; author with logical
  // properties only. See src/design-system/tokens/base.css.
  theme: { extend: {} },
  plugins: [],
};

export default config;
