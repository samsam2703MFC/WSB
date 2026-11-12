import React from 'react';

// Curated icon set — paths derived from Lucide (ISC-licensed), inlined so the
// component library is self-contained (no CDN dependency inside the bundle).
// Stroke style matches the brand: 1.75px, round caps/joins, currentColor.
const PATHS = {
  bag: ['M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z', 'M3 6h18', 'M16 10a4 4 0 0 1-8 0'],
  heart: ['M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'],
  search: ['M21 21l-4.34-4.34', 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16Z'],
  plus: ['M5 12h14', 'M12 5v14'],
  minus: ['M5 12h14'],
  check: ['M20 6 9 17l-5-5'],
  x: ['M18 6 6 18', 'M6 6l12 12'],
  chevronDown: ['m6 9 6 6 6-6'],
  chevronRight: ['m9 18 6-6-6-6'],
  arrowRight: ['M5 12h14', 'm12 5 7 7-7 7'],
  user: ['M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z'],
  leaf: ['M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z', 'M2 21c0-3 1.85-5.36 5.08-6'],
  truck: ['M10 17h4V5a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h1', 'M14 9h4l3 3v4a1 1 0 0 1-1 1h-1', 'M8 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z', 'M18 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z'],
  gift: ['M20 12v8a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-8', 'M2 7h20v5H2z', 'M12 22V7', 'M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7Z', 'M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7Z'],
};

export function Icon({ name = 'bag', size = 20, strokeWidth = 1.75, style, ...rest }) {
  const paths = PATHS[name] || PATHS.bag;
  const filled = name === 'star';
  return (
    <svg
      width={size} height={size} viewBox="0 0 24 24"
      fill="none" stroke="currentColor" strokeWidth={strokeWidth}
      strokeLinecap="round" strokeLinejoin="round"
      style={{ display: 'inline-block', flex: 'none', ...style }}
      aria-hidden="true" {...rest}
    >
      {paths.map((d, i) => <path key={i} d={d} />)}
    </svg>
  );
}

// Star handled separately so it can be filled (used by RatingStars).
export function StarIcon({ size = 18, filled = false, strokeWidth = 1.5, style, ...rest }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24"
      fill={filled ? 'currentColor' : 'none'} stroke="currentColor"
      strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round"
      style={{ display: 'inline-block', flex: 'none', ...style }} aria-hidden="true" {...rest}>
      <path d="M11.48 3.5a.6.6 0 0 1 1.04 0l2.28 4.62a.6.6 0 0 0 .45.33l5.1.74a.6.6 0 0 1 .33 1.02l-3.69 3.6a.6.6 0 0 0-.17.53l.87 5.08a.6.6 0 0 1-.87.63l-4.56-2.4a.6.6 0 0 0-.56 0l-4.56 2.4a.6.6 0 0 1-.87-.63l.87-5.08a.6.6 0 0 0-.17-.53l-3.69-3.6a.6.6 0 0 1 .33-1.02l5.1-.74a.6.6 0 0 0 .45-.33Z" />
    </svg>
  );
}
