/**
 * The 8 locales in tfb_languages. Every one is routable (/fr, /en, /ar …);
 * copy that has no tfb_translations row falls back to DEFAULT_LOCALE.
 */
export const LOCALES = ['fr', 'en', 'nl', 'de', 'pl', 'uk', 'ru', 'ar'] as const;

export type Locale = (typeof LOCALES)[number];

/** French is tfb_languages.is_default — the fallback for every missing string. */
export const DEFAULT_LOCALE: Locale = (process.env.DEFAULT_LOCALE as Locale) || 'fr';

/** Arabic is the only RTL locale (tfb_languages.is_rtl). */
export const RTL_LOCALES: readonly Locale[] = ['ar'];

/** Native names, as stored in tfb_languages.name. */
export const LOCALE_NAMES: Record<Locale, string> = {
  fr: 'Français',
  en: 'English',
  nl: 'Nederlands',
  de: 'Deutsch',
  pl: 'Polski',
  uk: 'Українська',
  ru: 'Русский',
  ar: 'العربية',
};

export function isLocale(value: string): value is Locale {
  return (LOCALES as readonly string[]).includes(value);
}

export function isRtl(locale: string): boolean {
  return (RTL_LOCALES as readonly string[]).includes(locale);
}

export function dirFor(locale: string): 'rtl' | 'ltr' {
  return isRtl(locale) ? 'rtl' : 'ltr';
}

/**
 * Intl locale tag used for number and currency formatting. Arabic figures stay in
 * Western digits so they match the invoices and the Stripe dashboard.
 */
export function intlLocale(locale: string): string {
  return locale === 'ar' ? 'ar-u-nu-latn' : locale;
}

/** Formats tfb_plans.amount (cents) for display. NULL amount ⇒ null (caller shows "sur devis"). */
export function formatMoney(amountInCents: number | null | undefined, currency: string, locale: string): string | null {
  if (amountInCents == null) return null;
  return (amountInCents / 100).toLocaleString(intlLocale(locale), {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  });
}
