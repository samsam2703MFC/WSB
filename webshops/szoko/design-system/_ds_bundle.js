/* @ds-bundle: {"format":4,"namespace":"MisterSzokoDesignSystem_613e75","components":[{"name":"ProductCard","sourcePath":"components/commerce/ProductCard.jsx"},{"name":"RatingStars","sourcePath":"components/commerce/RatingStars.jsx"},{"name":"Badge","sourcePath":"components/core/Badge.jsx"},{"name":"Button","sourcePath":"components/core/Button.jsx"},{"name":"Icon","sourcePath":"components/core/Icon.jsx"},{"name":"StarIcon","sourcePath":"components/core/Icon.jsx"},{"name":"IconButton","sourcePath":"components/core/IconButton.jsx"},{"name":"PriceTag","sourcePath":"components/core/PriceTag.jsx"},{"name":"Tag","sourcePath":"components/core/Tag.jsx"},{"name":"Input","sourcePath":"components/forms/Input.jsx"},{"name":"QuantityStepper","sourcePath":"components/forms/QuantityStepper.jsx"},{"name":"Select","sourcePath":"components/forms/Select.jsx"},{"name":"Card","sourcePath":"components/layout/Card.jsx"},{"name":"SectionHeading","sourcePath":"components/layout/SectionHeading.jsx"}],"sourceHashes":{"components/commerce/ProductCard.jsx":"1bc9406723eb","components/commerce/RatingStars.jsx":"86a42a5d65a0","components/core/Badge.jsx":"9976b3f47c32","components/core/Button.jsx":"95b67b0b3252","components/core/Icon.jsx":"8fda8e4efe0e","components/core/IconButton.jsx":"35ec34d5e431","components/core/PriceTag.jsx":"b4f42009ad37","components/core/Tag.jsx":"c949f81da61e","components/forms/Input.jsx":"309ec4167b95","components/forms/QuantityStepper.jsx":"6c4c28881271","components/forms/Select.jsx":"4e641ff1cbf1","components/layout/Card.jsx":"ef5a4002a693","components/layout/SectionHeading.jsx":"1fdb2b13185d"},"inlinedExternals":[],"unexposedExports":[]} */

(() => {

const __ds_ns = (window.MisterSzokoDesignSystem_613e75 = window.MisterSzokoDesignSystem_613e75 || {});

const __ds_scope = {};

(__ds_ns.__errors = __ds_ns.__errors || []);

// components/core/Badge.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
// Marketing flag for product imagery — "New", "-20%", "Bestseller".
const tones = {
  sale: {
    background: 'var(--sale)',
    color: 'var(--white)'
  },
  new: {
    background: 'var(--brand)',
    color: 'var(--text-inverse)'
  },
  gold: {
    background: 'var(--gold-500)',
    color: 'var(--choco-900)'
  },
  soft: {
    background: 'var(--surface-card)',
    color: 'var(--brand)',
    boxShadow: 'var(--shadow-sm)'
  }
};
function Badge({
  children,
  tone = 'new',
  style,
  ...rest
}) {
  const t = tones[tone] || tones.new;
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-xs)',
      fontWeight: 'var(--weight-extra)',
      letterSpacing: '0.02em',
      padding: '5px 12px',
      borderRadius: 'var(--radius-pill)',
      lineHeight: 1,
      whiteSpace: 'nowrap',
      ...t,
      ...style
    }
  }, rest), children);
}
Object.assign(__ds_scope, { Badge });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Badge.jsx", error: String((e && e.message) || e) }); }

// components/core/Button.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const base = {
  fontFamily: 'var(--font-sans)',
  fontWeight: 'var(--weight-bold)',
  border: 'none',
  cursor: 'pointer',
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  gap: 'var(--space-2)',
  borderRadius: 'var(--radius-pill)',
  lineHeight: 1,
  letterSpacing: '0.01em',
  textDecoration: 'none',
  whiteSpace: 'nowrap',
  transition: 'transform var(--dur-fast) var(--ease-soft), background var(--dur-base) var(--ease-out), box-shadow var(--dur-base) var(--ease-out), color var(--dur-base) var(--ease-out)'
};
const sizes = {
  sm: {
    fontSize: 'var(--text-sm)',
    padding: '9px 16px'
  },
  md: {
    fontSize: 'var(--text-md)',
    padding: '13px 24px'
  },
  lg: {
    fontSize: 'var(--text-lg)',
    padding: '17px 34px'
  }
};
const variants = {
  primary: {
    rest: {
      background: 'var(--brand)',
      color: 'var(--text-inverse)',
      boxShadow: 'var(--shadow-sm)'
    },
    hover: {
      background: 'var(--brand-hover)',
      boxShadow: 'var(--shadow-md)'
    }
  },
  accent: {
    rest: {
      background: 'var(--accent)',
      color: 'var(--text-on-accent)',
      boxShadow: 'var(--shadow-sm)'
    },
    hover: {
      background: 'var(--accent-hover)',
      boxShadow: 'var(--shadow-md)'
    }
  },
  secondary: {
    rest: {
      background: 'transparent',
      color: 'var(--brand)',
      boxShadow: 'inset 0 0 0 1.5px var(--border-strong)'
    },
    hover: {
      background: 'var(--brand-quiet)',
      boxShadow: 'inset 0 0 0 1.5px var(--brand)'
    }
  },
  ghost: {
    rest: {
      background: 'transparent',
      color: 'var(--brand)'
    },
    hover: {
      background: 'var(--accent-quiet)'
    }
  }
};
function Button({
  children,
  variant = 'primary',
  size = 'md',
  block = false,
  disabled = false,
  iconLeft,
  iconRight,
  as = 'button',
  style,
  ...rest
}) {
  const El = as;
  const [hover, setHover] = React.useState(false);
  const [press, setPress] = React.useState(false);
  const v = variants[variant] || variants.primary;
  return /*#__PURE__*/React.createElement(El, _extends({
    disabled: El === 'button' ? disabled : undefined,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => {
      setHover(false);
      setPress(false);
    },
    onMouseDown: () => setPress(true),
    onMouseUp: () => setPress(false),
    style: {
      ...base,
      ...sizes[size],
      ...v.rest,
      ...(hover && !disabled ? v.hover : null),
      width: block ? '100%' : undefined,
      transform: press ? 'scale(0.97)' : hover && !disabled ? 'translateY(-2px)' : 'none',
      opacity: disabled ? 0.5 : 1,
      pointerEvents: disabled ? 'none' : undefined,
      ...style
    }
  }, rest), iconLeft, children, iconRight);
}
Object.assign(__ds_scope, { Button });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Button.jsx", error: String((e && e.message) || e) }); }

// components/core/Icon.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
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
  gift: ['M20 12v8a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-8', 'M2 7h20v5H2z', 'M12 22V7', 'M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7Z', 'M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7Z']
};
function Icon({
  name = 'bag',
  size = 20,
  strokeWidth = 1.75,
  style,
  ...rest
}) {
  const paths = PATHS[name] || PATHS.bag;
  const filled = name === 'star';
  return /*#__PURE__*/React.createElement("svg", _extends({
    width: size,
    height: size,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: strokeWidth,
    strokeLinecap: "round",
    strokeLinejoin: "round",
    style: {
      display: 'inline-block',
      flex: 'none',
      ...style
    },
    "aria-hidden": "true"
  }, rest), paths.map((d, i) => /*#__PURE__*/React.createElement("path", {
    key: i,
    d: d
  })));
}

// Star handled separately so it can be filled (used by RatingStars).
function StarIcon({
  size = 18,
  filled = false,
  strokeWidth = 1.5,
  style,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("svg", _extends({
    width: size,
    height: size,
    viewBox: "0 0 24 24",
    fill: filled ? 'currentColor' : 'none',
    stroke: "currentColor",
    strokeWidth: strokeWidth,
    strokeLinecap: "round",
    strokeLinejoin: "round",
    style: {
      display: 'inline-block',
      flex: 'none',
      ...style
    },
    "aria-hidden": "true"
  }, rest), /*#__PURE__*/React.createElement("path", {
    d: "M11.48 3.5a.6.6 0 0 1 1.04 0l2.28 4.62a.6.6 0 0 0 .45.33l5.1.74a.6.6 0 0 1 .33 1.02l-3.69 3.6a.6.6 0 0 0-.17.53l.87 5.08a.6.6 0 0 1-.87.63l-4.56-2.4a.6.6 0 0 0-.56 0l-4.56 2.4a.6.6 0 0 1-.87-.63l.87-5.08a.6.6 0 0 0-.17-.53l-3.69-3.6a.6.6 0 0 1 .33-1.02l5.1-.74a.6.6 0 0 0 .45-.33Z"
  }));
}
Object.assign(__ds_scope, { Icon, StarIcon });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Icon.jsx", error: String((e && e.message) || e) }); }

// components/commerce/RatingStars.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function RatingStars({
  value = 0,
  count,
  size = 16,
  showValue = false,
  style,
  ...rest
}) {
  const full = Math.round(value);
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 'var(--space-2)',
      color: 'var(--gold-500)',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      gap: 2
    }
  }, [0, 1, 2, 3, 4].map(i => /*#__PURE__*/React.createElement(__ds_scope.StarIcon, {
    key: i,
    size: size,
    filled: i < full
  }))), showValue && /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-mono)',
      fontSize: 'var(--text-xs)',
      color: 'var(--text-muted)'
    }
  }, value.toFixed(1), count != null ? ` (${count})` : ''));
}
Object.assign(__ds_scope, { RatingStars });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/commerce/RatingStars.jsx", error: String((e && e.message) || e) }); }

// components/core/IconButton.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const sizes = {
  sm: 34,
  md: 42,
  lg: 50
};
const iconSizes = {
  sm: 18,
  md: 20,
  lg: 24
};
const variants = {
  solid: {
    rest: {
      background: 'var(--brand)',
      color: 'var(--text-inverse)'
    },
    hover: {
      background: 'var(--brand-hover)'
    }
  },
  soft: {
    rest: {
      background: 'var(--surface-raised)',
      color: 'var(--brand)'
    },
    hover: {
      background: 'var(--accent-quiet)'
    }
  },
  ghost: {
    rest: {
      background: 'transparent',
      color: 'var(--text-body)'
    },
    hover: {
      background: 'var(--surface-raised)'
    }
  },
  outline: {
    rest: {
      background: 'var(--surface-card)',
      color: 'var(--brand)',
      boxShadow: 'inset 0 0 0 1.5px var(--border-default)'
    },
    hover: {
      background: 'var(--surface-raised)'
    }
  }
};
function IconButton({
  children,
  label,
  variant = 'ghost',
  size = 'md',
  disabled = false,
  style,
  ...rest
}) {
  const [hover, setHover] = React.useState(false);
  const [press, setPress] = React.useState(false);
  const v = variants[variant] || variants.ghost;
  const dim = sizes[size];
  return /*#__PURE__*/React.createElement("button", _extends({
    "aria-label": label,
    title: label,
    disabled: disabled,
    onMouseEnter: () => setHover(true),
    onMouseLeave: () => {
      setHover(false);
      setPress(false);
    },
    onMouseDown: () => setPress(true),
    onMouseUp: () => setPress(false),
    style: {
      width: dim,
      height: dim,
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      border: 'none',
      cursor: 'pointer',
      borderRadius: 'var(--radius-pill)',
      transition: 'transform var(--dur-fast) var(--ease-soft), background var(--dur-base) var(--ease-out)',
      ...v.rest,
      ...(hover && !disabled ? v.hover : null),
      transform: press ? 'scale(0.92)' : 'none',
      opacity: disabled ? 0.5 : 1,
      pointerEvents: disabled ? 'none' : undefined,
      ...style
    }
  }, rest), React.isValidElement(children) ? React.cloneElement(children, {
    size: children.props.size || iconSizes[size]
  }) : children);
}
Object.assign(__ds_scope, { IconButton });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/IconButton.jsx", error: String((e && e.message) || e) }); }

// components/core/PriceTag.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
// Price display with optional struck-through original (sale). Monospace figures.
const sizes = {
  sm: {
    now: 'var(--text-md)',
    was: 'var(--text-xs)'
  },
  md: {
    now: 'var(--text-xl)',
    was: 'var(--text-sm)'
  },
  lg: {
    now: 'var(--text-3xl)',
    was: 'var(--text-lg)'
  }
};
function PriceTag({
  amount,
  was,
  currency = '€',
  size = 'md',
  style,
  ...rest
}) {
  const s = sizes[size] || sizes.md;
  const onSale = was != null;
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'baseline',
      gap: 'var(--space-3)',
      fontFamily: 'var(--font-mono)',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: s.now,
      fontWeight: 'var(--weight-medium)',
      color: onSale ? 'var(--sale)' : 'var(--price)',
      letterSpacing: '-0.01em'
    }
  }, currency, amount), onSale && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: s.was,
      color: 'var(--text-muted)',
      textDecoration: 'line-through'
    }
  }, currency, was));
}
Object.assign(__ds_scope, { PriceTag });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/PriceTag.jsx", error: String((e && e.message) || e) }); }

// components/core/Tag.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
// Small uppercase eyebrow / category label with wide tracking.
const tones = {
  origin: {
    color: 'var(--choco-600)',
    background: 'var(--brand-quiet)'
  },
  accent: {
    color: 'var(--caramel-600)',
    background: 'var(--accent-quiet)'
  },
  berry: {
    color: 'var(--white)',
    background: 'var(--berry-500)'
  },
  plain: {
    color: 'var(--text-muted)',
    background: 'transparent',
    boxShadow: 'inset 0 0 0 1px var(--border-default)'
  }
};
function Tag({
  children,
  tone = 'origin',
  icon,
  style,
  ...rest
}) {
  const t = tones[tone] || tones.origin;
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 'var(--space-2)',
      fontFamily: 'var(--font-mono)',
      fontSize: 'var(--text-2xs)',
      fontWeight: 'var(--weight-medium)',
      textTransform: 'uppercase',
      letterSpacing: 'var(--tracking-caps)',
      padding: '5px 11px',
      borderRadius: 'var(--radius-pill)',
      lineHeight: 1,
      whiteSpace: 'nowrap',
      ...t,
      ...style
    }
  }, rest), icon, children);
}
Object.assign(__ds_scope, { Tag });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Tag.jsx", error: String((e && e.message) || e) }); }

// components/forms/Input.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function Input({
  label,
  hint,
  error,
  icon,
  size = 'md',
  id,
  style,
  ...rest
}) {
  const [focus, setFocus] = React.useState(false);
  const uid = id || React.useId();
  const pad = size === 'lg' ? '15px 16px' : '12px 14px';
  return /*#__PURE__*/React.createElement("label", {
    htmlFor: uid,
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 'var(--space-2)',
      fontFamily: 'var(--font-sans)'
    }
  }, label && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 'var(--text-sm)',
      fontWeight: 'var(--weight-semibold)',
      color: 'var(--text-strong)'
    }
  }, label), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 'var(--space-2)',
      background: 'var(--surface-card)',
      borderRadius: 'var(--radius-md)',
      padding: pad,
      color: 'var(--text-muted)',
      boxShadow: error ? 'inset 0 0 0 1.5px var(--danger)' : focus ? 'inset 0 0 0 1.5px var(--brand), var(--ring)' : 'inset 0 0 0 1.5px var(--border-default)',
      transition: 'box-shadow var(--dur-base) var(--ease-out)'
    }
  }, icon, /*#__PURE__*/React.createElement("input", _extends({
    id: uid,
    onFocus: () => setFocus(true),
    onBlur: () => setFocus(false),
    style: {
      flex: 1,
      border: 'none',
      outline: 'none',
      background: 'transparent',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-md)',
      color: 'var(--text-body)',
      minWidth: 0,
      ...style
    }
  }, rest))), (hint || error) && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 'var(--text-xs)',
      color: error ? 'var(--danger)' : 'var(--text-muted)'
    }
  }, error || hint));
}
Object.assign(__ds_scope, { Input });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Input.jsx", error: String((e && e.message) || e) }); }

// components/forms/QuantityStepper.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
// +/- quantity control for basket lines and PDP.
function QuantityStepper({
  value = 1,
  min = 1,
  max = 99,
  onChange,
  size = 'md',
  style,
  ...rest
}) {
  const dim = size === 'sm' ? 32 : 40;
  const set = n => {
    const c = Math.max(min, Math.min(max, n));
    onChange && onChange(c);
  };
  const btn = (dir, name, disabled) => /*#__PURE__*/React.createElement("button", {
    "aria-label": dir,
    disabled: disabled,
    onClick: () => set(value + (dir === 'increase' ? 1 : -1)),
    style: {
      width: dim,
      height: dim,
      border: 'none',
      cursor: disabled ? 'default' : 'pointer',
      background: 'transparent',
      color: disabled ? 'var(--border-default)' : 'var(--brand)',
      display: 'inline-flex',
      alignItems: 'center',
      justifyContent: 'center',
      borderRadius: 'var(--radius-pill)',
      transition: 'background var(--dur-base) var(--ease-out)'
    },
    onMouseEnter: e => {
      if (!disabled) e.currentTarget.style.background = 'var(--surface-raised)';
    },
    onMouseLeave: e => {
      e.currentTarget.style.background = 'transparent';
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: name,
    size: size === 'sm' ? 16 : 18
  }));
  return /*#__PURE__*/React.createElement("div", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      background: 'var(--surface-card)',
      borderRadius: 'var(--radius-pill)',
      boxShadow: 'inset 0 0 0 1.5px var(--border-default)',
      ...style
    }
  }, rest), btn('decrease', 'minus', value <= min), /*#__PURE__*/React.createElement("span", {
    style: {
      minWidth: 28,
      textAlign: 'center',
      fontFamily: 'var(--font-mono)',
      fontSize: 'var(--text-md)',
      color: 'var(--text-strong)',
      fontWeight: 'var(--weight-medium)'
    }
  }, value), btn('increase', 'plus', value >= max));
}
Object.assign(__ds_scope, { QuantityStepper });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/QuantityStepper.jsx", error: String((e && e.message) || e) }); }

// components/forms/Select.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function Select({
  label,
  hint,
  options = [],
  id,
  style,
  ...rest
}) {
  const [focus, setFocus] = React.useState(false);
  const uid = id || React.useId();
  return /*#__PURE__*/React.createElement("label", {
    htmlFor: uid,
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 'var(--space-2)',
      fontFamily: 'var(--font-sans)'
    }
  }, label && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 'var(--text-sm)',
      fontWeight: 'var(--weight-semibold)',
      color: 'var(--text-strong)'
    }
  }, label), /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'relative',
      display: 'flex',
      alignItems: 'center',
      background: 'var(--surface-card)',
      borderRadius: 'var(--radius-md)',
      boxShadow: focus ? 'inset 0 0 0 1.5px var(--brand), var(--ring)' : 'inset 0 0 0 1.5px var(--border-default)',
      transition: 'box-shadow var(--dur-base) var(--ease-out)'
    }
  }, /*#__PURE__*/React.createElement("select", _extends({
    id: uid,
    onFocus: () => setFocus(true),
    onBlur: () => setFocus(false),
    style: {
      appearance: 'none',
      WebkitAppearance: 'none',
      border: 'none',
      outline: 'none',
      background: 'transparent',
      width: '100%',
      padding: '12px 40px 12px 14px',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-md)',
      color: 'var(--text-body)',
      cursor: 'pointer',
      ...style
    }
  }, rest), options.map(o => {
    const val = typeof o === 'string' ? o : o.value;
    const lbl = typeof o === 'string' ? o : o.label;
    return /*#__PURE__*/React.createElement("option", {
      key: val,
      value: val
    }, lbl);
  })), /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'absolute',
      right: 12,
      pointerEvents: 'none',
      color: 'var(--text-muted)',
      display: 'inline-flex'
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "chevronDown",
    size: 18
  }))), hint && /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 'var(--text-xs)',
      color: 'var(--text-muted)'
    }
  }, hint));
}
Object.assign(__ds_scope, { Select });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Select.jsx", error: String((e && e.message) || e) }); }

// components/layout/Card.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
// Warm surface container. `hover` enables the gentle lift used by product cards.
function Card({
  children,
  padding = 'var(--space-6)',
  hover = false,
  tone = 'card',
  radius = 'var(--radius-lg)',
  style,
  ...rest
}) {
  const [h, setH] = React.useState(false);
  const tones = {
    card: {
      background: 'var(--surface-card)',
      color: 'var(--text-body)'
    },
    raised: {
      background: 'var(--surface-raised)',
      color: 'var(--text-body)'
    },
    choco: {
      background: 'var(--bg-inverse)',
      color: 'var(--text-inverse)'
    }
  };
  return /*#__PURE__*/React.createElement("div", _extends({
    onMouseEnter: () => hover && setH(true),
    onMouseLeave: () => hover && setH(false),
    style: {
      borderRadius: radius,
      padding,
      overflow: 'hidden',
      boxShadow: h ? 'var(--shadow-md)' : 'var(--shadow-sm)',
      transform: h ? 'var(--lift)' : 'none',
      transition: 'transform var(--dur-base) var(--ease-soft), box-shadow var(--dur-base) var(--ease-out)',
      ...tones[tone],
      ...style
    }
  }, rest), children);
}
Object.assign(__ds_scope, { Card });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/layout/Card.jsx", error: String((e && e.message) || e) }); }

// components/commerce/ProductCard.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/**
 * Product tile for shop grids. Pass an `image` URL, or leave it out for a warm
 * chocolate placeholder swatch (no product photography was supplied to the system).
 */
function ProductCard({
  name,
  origin,
  cocoa,
  price,
  was,
  image,
  badge,
  rating,
  count,
  wishlisted = false,
  onWishlist,
  onAdd,
  style,
  ...rest
}) {
  const [wish, setWish] = React.useState(wishlisted);
  return /*#__PURE__*/React.createElement(__ds_scope.Card, _extends({
    hover: true,
    padding: "0",
    radius: "var(--radius-lg)",
    style: {
      display: 'flex',
      flexDirection: 'column',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      aspectRatio: '1 / 1',
      overflow: 'hidden',
      background: image ? `center/cover no-repeat url("${image}")` : 'radial-gradient(120% 120% at 30% 20%, var(--choco-500), var(--choco-800))'
    }
  }, !image && /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      inset: 0,
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      color: 'var(--choco-200)',
      fontFamily: 'var(--font-mono)',
      fontSize: 'var(--text-2xs)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      opacity: 0.6
    }
  }, "Product photo"), badge && /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      top: 12,
      left: 12
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Badge, {
    tone: badge.tone
  }, badge.label)), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      top: 10,
      right: 10
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.IconButton, {
    label: "Add to wishlist",
    variant: "soft",
    size: "sm",
    onClick: () => {
      setWish(w => !w);
      onWishlist && onWishlist(!wish);
    },
    style: wish ? {
      color: 'var(--berry-500)'
    } : undefined
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "heart"
  })))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 'var(--space-3)',
      padding: 'var(--space-5)'
    }
  }, (origin || cocoa) && /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 'var(--space-2)',
      flexWrap: 'wrap'
    }
  }, origin && /*#__PURE__*/React.createElement(__ds_scope.Tag, {
    tone: "origin",
    icon: /*#__PURE__*/React.createElement(__ds_scope.Icon, {
      name: "leaf",
      size: 12
    })
  }, origin), cocoa && /*#__PURE__*/React.createElement(__ds_scope.Tag, {
    tone: "accent"
  }, cocoa)), /*#__PURE__*/React.createElement("h3", {
    style: {
      fontFamily: 'var(--font-display)',
      fontSize: 'var(--text-xl)',
      lineHeight: 'var(--leading-snug)',
      color: 'var(--text-strong)',
      margin: 0,
      fontWeight: 700
    }
  }, name), rating != null && /*#__PURE__*/React.createElement(__ds_scope.RatingStars, {
    value: rating,
    count: count,
    showValue: true,
    size: 15
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 'var(--space-3)',
      marginTop: 'var(--space-1)'
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.PriceTag, {
    amount: price,
    was: was,
    size: "md"
  }), /*#__PURE__*/React.createElement(__ds_scope.IconButton, {
    label: `Add ${name} to basket`,
    variant: "solid",
    onClick: onAdd
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "bag"
  })))));
}
Object.assign(__ds_scope, { ProductCard });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/commerce/ProductCard.jsx", error: String((e && e.message) || e) }); }

// components/layout/SectionHeading.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
// Editorial section header: uppercase eyebrow + serif title + optional lead.
function SectionHeading({
  eyebrow,
  title,
  lead,
  align = 'left',
  invert = false,
  style,
  ...rest
}) {
  const strong = invert ? 'var(--text-inverse)' : 'var(--text-strong)';
  const body = invert ? 'var(--cream-200)' : 'var(--text-muted)';
  return /*#__PURE__*/React.createElement("div", _extends({
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 'var(--space-3)',
      textAlign: align,
      alignItems: align === 'center' ? 'center' : 'flex-start',
      ...style
    }
  }, rest), eyebrow && /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-mono)',
      fontSize: 'var(--text-xs)',
      textTransform: 'uppercase',
      letterSpacing: 'var(--tracking-caps)',
      color: 'var(--accent)',
      fontWeight: 'var(--weight-medium)'
    }
  }, eyebrow), title && /*#__PURE__*/React.createElement("h2", {
    style: {
      fontFamily: 'var(--font-display)',
      fontSize: 'var(--text-3xl)',
      lineHeight: 'var(--leading-tight)',
      fontWeight: 700,
      color: strong,
      margin: 0,
      letterSpacing: 'var(--tracking-tight)',
      textWrap: 'balance'
    }
  }, title), lead && /*#__PURE__*/React.createElement("p", {
    style: {
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-lg)',
      lineHeight: 'var(--leading-normal)',
      color: body,
      margin: 0,
      maxWidth: '52ch',
      textWrap: 'pretty'
    }
  }, lead));
}
Object.assign(__ds_scope, { SectionHeading });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/layout/SectionHeading.jsx", error: String((e && e.message) || e) }); }

__ds_ns.ProductCard = __ds_scope.ProductCard;

__ds_ns.RatingStars = __ds_scope.RatingStars;

__ds_ns.Badge = __ds_scope.Badge;

__ds_ns.Button = __ds_scope.Button;

__ds_ns.Icon = __ds_scope.Icon;

__ds_ns.StarIcon = __ds_scope.StarIcon;

__ds_ns.IconButton = __ds_scope.IconButton;

__ds_ns.PriceTag = __ds_scope.PriceTag;

__ds_ns.Tag = __ds_scope.Tag;

__ds_ns.Input = __ds_scope.Input;

__ds_ns.QuantityStepper = __ds_scope.QuantityStepper;

__ds_ns.Select = __ds_scope.Select;

__ds_ns.Card = __ds_scope.Card;

__ds_ns.SectionHeading = __ds_scope.SectionHeading;

})();
