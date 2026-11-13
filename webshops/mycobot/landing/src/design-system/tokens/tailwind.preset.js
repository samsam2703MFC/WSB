/* TFB design tokens as a Tailwind preset.
   Not part of the CSS bundle — import it in tailwind.config.js of the Next.js app:
     const tfb = require('./tokens/tailwind.preset.js');
     module.exports = { presets: [tfb], content: [...] };
   Values mirror tokens/*.css exactly; change both together. */
const tfbPreset = {
  theme: {
    extend: {
      colors: {
        plum: { 50: '#f6eff8', 100: '#ebdcef', 200: '#d6bade', 300: '#bc94c8', 400: '#9c66ae', 500: '#7b4488', 600: '#683972', 700: '#532d5b', 800: '#3e2245', 900: '#29162e' },
        navy: { 50: '#f1f4fb', 100: '#e1e7f5', 200: '#c2cde9', 300: '#97a8d3', 400: '#6478b4', 500: '#3b4e92', 600: '#2a3a72', 700: '#1e2b57', 800: '#141d3e', 900: '#0c1329' },
        slate: { 0: '#ffffff', 25: '#fafbfd', 50: '#f5f6f9', 100: '#edeef3', 200: '#dddfe8', 300: '#c3c7d5', 400: '#9aa0b4', 500: '#737a92', 600: '#565d73', 700: '#3f4558', 800: '#2a2f3d', 900: '#171a24' },
        ember: { 50: '#fef4e8', 100: '#fde7cb', 300: '#f7be79', 500: '#f0912a', 600: '#de7f18', 700: '#b4540a', 900: '#6e3204' },
        teal: { 100: '#d7f2f0', 300: '#74d2cc', 500: '#159e96', 700: '#0c6a66' },
        green: { 100: '#dbf1e6', 500: '#157f5a', 700: '#0e5c41' },
        amber: { 100: '#fceed6', 500: '#b77500', 700: '#8a5900' },
        red: { 100: '#fbe0e4', 500: '#c0304a', 700: '#931f35' },
        blue: { 100: '#dee8fd', 500: '#2a5fd0', 700: '#1c419a' },
      },
      fontFamily: {
        display: ['Manrope', 'IBM Plex Sans', 'system-ui', 'sans-serif'],
        sans: ['IBM Plex Sans', 'Segoe UI', 'system-ui', 'sans-serif'],
        arabic: ['IBM Plex Sans Arabic', 'Noto Naskh Arabic', 'serif'],
        mono: ['IBM Plex Mono', 'ui-monospace', 'Menlo', 'monospace'],
      },
      fontSize: {
        '2xs': ['11px', '1.4'], xs: ['12px', '1.4'], sm: ['13px', '1.5'], base: ['15px', '1.5'], md: ['16px', '1.5'],
        lg: ['18px', '1.68'], xl: ['21px', '1.24'], '2xl': ['25px', '1.24'], '3xl': ['31px', '1.24'],
        '4xl': ['39px', '1.24'], '5xl': ['49px', '1.08'], '6xl': ['61px', '1.08'],
      },
      letterSpacing: { display: '-0.024em', tight: '-0.012em', wide: '0.03em', caps: '0.09em' },
      spacing: { 0.5: '2px', 1: '4px', 2: '8px', 3: '12px', 4: '16px', 5: '20px', 6: '24px', 7: '28px', 8: '32px', 10: '40px', 12: '48px', 16: '64px', 20: '80px', 24: '96px', 32: '128px' },
      borderRadius: { xs: '4px', sm: '6px', md: '8px', lg: '12px', xl: '16px', '2xl': '24px', pill: '999px' },
      boxShadow: {
        xs: '0 1px 2px rgba(12,19,41,0.06)',
        sm: '0 1px 3px rgba(12,19,41,0.08), 0 1px 2px -1px rgba(12,19,41,0.06)',
        md: '0 4px 12px -2px rgba(12,19,41,0.1), 0 2px 4px -2px rgba(12,19,41,0.06)',
        lg: '0 12px 28px -6px rgba(12,19,41,0.16), 0 4px 8px -4px rgba(12,19,41,0.08)',
        xl: '0 28px 60px -12px rgba(12,19,41,0.24)',
        brand: '0 8px 22px -8px rgba(123,68,136,0.45)',
        focus: '0 0 0 3px rgba(123,68,136,0.26)',
      },
      backgroundImage: {
        ink: 'linear-gradient(155deg, #1e2b57 0%, #0c1329 62%)',
        plum: 'linear-gradient(138deg, #8a4c99 0%, #532d5b 100%)',
        'plum-navy': 'linear-gradient(140deg, #7b4488 0%, #2a3a72 100%)',
        ember: 'linear-gradient(138deg, #f7be79 0%, #f0912a 100%)',
        veil: 'linear-gradient(180deg, rgba(12,19,41,0) 0%, rgba(12,19,41,0.78) 100%)',
      },
      transitionTimingFunction: { standard: 'cubic-bezier(.2,.6,.2,1)', out: 'cubic-bezier(.16,1,.3,1)', in: 'cubic-bezier(.5,0,.9,.2)' },
      transitionDuration: { instant: '90ms', fast: '140ms', base: '200ms', slow: '300ms', slower: '460ms' },
      maxWidth: { content: '1200px', prose: '66ch' },
    },
  },
};

if (typeof module !== 'undefined' && module.exports) { module.exports = tfbPreset; }
