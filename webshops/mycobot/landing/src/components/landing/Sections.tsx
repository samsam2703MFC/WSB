'use client';

import React from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Badge, Button, Card, Carousel, Icon, ModuleCard, PlanCard, StatTile, Tag } from '@/design-system';
import type { LandingPayload } from '@/lib/landing/types';
import { SECTION } from './layout';

type T = (key: string) => string;

function useT(payload: LandingPayload): T {
  return React.useCallback((key: string) => payload.strings[key] ?? key, [payload.strings]);
}

/* ------------------------------------------------------------------ Hero ---- */

export function Hero({ payload, onDemo }: { payload: LandingPayload; onDemo: () => void }) {
  const t = useT(payload);
  return (
    <section style={{ background: 'var(--gradient-ink)', color: 'var(--text-inverse)', padding: 'var(--space-20) 0', overflow: 'hidden' }}>
      <div style={{ ...SECTION, display: 'grid', gridTemplateColumns: '1.05fr 0.95fr', gap: 'var(--space-12)', alignItems: 'center' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
          <span className="fb-eyebrow" style={{ color: 'var(--ember-300)' }}>{t('hero.eyebrow')}</span>
          <h1 style={{ font: 'var(--type-display-2)', letterSpacing: 'var(--tracking-display)', color: 'var(--text-inverse)', maxWidth: '20ch' }}>
            {t('hero.title')}
          </h1>
          <p style={{ font: 'var(--type-body-lg)', color: 'var(--text-inverse-secondary)', maxWidth: '54ch' }}>{t('hero.subtitle')}</p>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', flexWrap: 'wrap' }}>
            <Button variant="warm" size="lg" iconRight="arrow-right" onClick={onDemo}>{t('cta.demo')}</Button>
            <Button variant="inverse" size="lg" iconLeft="play">{t('cta.start')}</Button>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-5)', font: 'var(--type-body-sm)', color: 'var(--text-inverse-secondary)', flexWrap: 'wrap' }}>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              <Icon name="circle-check" size={15} color="var(--teal-300)" />{t('hero.proof1')}
            </span>
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
              <Icon name="languages" size={15} color="var(--teal-300)" />{t('hero.proof2')}
            </span>
          </div>
        </div>
        <HeroMock payload={payload} />
      </div>
    </section>
  );
}

/** The product mock: a --radius-panel frame with a header strip, over an ember glow. */
function HeroMock({ payload }: { payload: LandingPayload }) {
  const t = useT(payload);
  // The three rows stand in for the console's module list — abbreviated content,
  // real component vocabulary.
  const rows: [string, string, 'success' | 'warm', string][] = [
    [payload.modules[0]?.name ?? 'Shop', payload.modules[0]?.icon ?? 'shopping-cart', 'success', t('mock.active')],
    [payload.modules[3]?.name ?? 'Scan', payload.modules[3]?.icon ?? 'qr-code', 'warm', t('modules.new')],
    [payload.modules[5]?.name ?? 'CEObot', payload.modules[5]?.icon ?? 'bot', 'warm', t('modules.new')],
  ];
  return (
    <div style={{ position: 'relative' }}>
      <div style={{ position: 'absolute', inset: '-16% -12%', background: 'radial-gradient(58% 58% at 58% 40%, rgba(240,145,42,0.34), transparent 70%)', filter: 'var(--blur-veil)' }} />
      <div style={{ position: 'relative', borderRadius: 'var(--radius-panel)', background: 'var(--surface-card)', boxShadow: 'var(--shadow-xl)', border: '1px solid var(--border-inverse)', overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 14px', borderBottom: '1px solid var(--border-subtle)', background: 'var(--slate-25)' }}>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src="/brand/logo-mark.png" alt="" width={18} height={18} />
          <span style={{ font: 'var(--weight-semibold) var(--text-xs)/1 var(--font-sans)', color: 'var(--text-secondary)' }}>{t('mock.brand')}</span>
          <span className="fb-num" style={{ marginInlineStart: 'auto', font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{t('mock.stores')}</span>
        </div>
        <div style={{ padding: 'var(--space-4)', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', background: 'var(--surface-page)' }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-3)' }}>
            <StatTile
              label={t('mock.collected')} value={t('mock.collected.value')} delta={t('mock.collected.delta')}
              deltaLabel={t('mock.vsjune')} icon="wallet" style={{ padding: 'var(--space-4)' }}
            />
            <StatTile
              label={t('mock.licenses')} value={t('mock.licenses.value')} delta={t('mock.licenses.delta')}
              deltaLabel={t('mock.thismonth')} icon="credit-card" style={{ padding: 'var(--space-4)' }}
            />
          </div>
          <Card padding="sm">
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
              {rows.map(([name, icon, tone, status]) => (
                <div key={name} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
                  <Icon name={icon} size={15} color="var(--text-tertiary)" />
                  <span style={{ font: 'var(--type-body-sm)' }}>{name}</span>
                  <Badge size="sm" tone={tone} dot={tone === 'success'} style={{ marginInlineStart: 'auto' }}>{status}</Badge>
                </div>
              ))}
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------ BrandStrip ---- */

export function BrandStrip({ payload }: { payload: LandingPayload }) {
  const t = useT(payload);
  return (
    <section style={{ background: 'var(--navy-900)', padding: 'var(--space-8) 0', borderTop: '1px solid var(--border-inverse)' }}>
      <div style={{ ...SECTION, display: 'flex', alignItems: 'center', gap: 'var(--space-8)', flexWrap: 'wrap' }}>
        <span style={{ font: 'var(--type-caption)', letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', color: 'rgba(255,255,255,0.42)' }}>
          {t('brands.title')}
        </span>
        {payload.brands.map((b) => (
          b.logoPath ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img key={b.slug} src={b.logoPath} alt={b.name} height={26} style={{ height: 26, width: 'auto', opacity: 0.82 }} />
          ) : (
            // No tfb_brands.logo_path uploaded yet — a dashed name chip stands in
            // rather than an invented mark.
            <span key={b.slug} title={`tfb_brands.logo_path — ${b.slug}`} style={{
              display: 'inline-flex', alignItems: 'center', height: 34, padding: '0 12px',
              border: '1px dashed var(--border-inverse)', borderRadius: 'var(--radius-sm)',
              font: 'var(--weight-semibold) var(--text-base)/1 var(--font-display)', color: 'rgba(255,255,255,0.62)',
            }}>{b.name}</span>
          )
        ))}
      </div>
    </section>
  );
}

/* --------------------------------------------- Modules — layout A (grid) ---- */

export function ModulesGrid({ payload }: { payload: LandingPayload }) {
  const t = useT(payload);
  const router = useRouter();
  const all = payload.modules;
  // Chips key off the raw module_group so the filter survives translation.
  const groupKeys = React.useMemo(
    () => ['*', ...Array.from(new Set(all.map((m) => m.groupKey).filter(Boolean)))],
    [all],
  );
  const [group, setGroup] = React.useState('*');
  const modules = group === '*' ? all : all.filter((m) => m.groupKey === group);
  const newCount = all.filter((m) => m.isNew).length;

  return (
    <section id="modules" style={{ background: 'var(--surface-card)', padding: 'var(--space-24) 0' }}>
      <div style={SECTION}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: 660, marginBottom: 'var(--space-8)' }}>
          <span className="fb-eyebrow">{t('modules.eyebrow')}</span>
          <h2 style={{ font: 'var(--type-heading-1)' }}>{t('modules.title')}</h2>
          <p style={{ font: 'var(--type-body-lg)', color: 'var(--text-secondary)' }}>{t('modules.lead')}</p>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', flexWrap: 'wrap', marginBottom: 'var(--space-5)' }}>
          {groupKeys.map((key) => (
            <Tag key={key} selected={group === key} onClick={() => setGroup(key)}>
              {key === '*' ? t('modules.all') : t(`group.${key.toLowerCase()}`)}
            </Tag>
          ))}
          <span style={{ marginInlineStart: 'auto', font: 'var(--type-body-sm)', color: 'var(--text-tertiary)' }}>
            <span className="fb-num">{modules.length}</span> {t('modules.count')} · <span className="fb-num">{newCount}</span> {t('modules.newcount')}
          </span>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 'var(--space-4)' }}>
          {modules.map((m) => {
            const href = `/${payload.locale}/modules/${m.slug}`;
            return (
              <ModuleCard
                key={m.key}
                icon={m.icon}
                name={m.name}
                description={m.description}
                group={m.group}
                isNew={m.isNew}
                newLabel={t('modules.new')}
                linkLabel={t('modules.link')}
                href={href}
                screenshots={m.screenshots.map((s) => ({ src: s.src ?? undefined, alt: s.alt }))}
                rtl={payload.dir === 'rtl'}
                placeholderPath={`/storage/screenshots/${m.key}-…`}
                onClick={(e) => {
                  // The whole card is the target; the inner link still works on
                  // its own for middle-click and keyboard.
                  if ((e.target as HTMLElement).closest('a,button')) return;
                  router.push(href);
                }}
                style={{ cursor: 'pointer' }}
              />
            );
          })}
        </div>
      </div>
    </section>
  );
}

/* ----------------------------------------------------------------- Steps ---- */

export function Steps({ payload }: { payload: LandingPayload }) {
  const t = useT(payload);
  return (
    <section style={{ background: 'var(--surface-page)', padding: 'var(--space-20) 0' }}>
      <div style={SECTION}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', marginBottom: 'var(--space-10)', maxWidth: 620 }}>
          <span className="fb-eyebrow">{t('steps.eyebrow')}</span>
          <h2 style={{ font: 'var(--type-heading-1)' }}>{t('steps.title')}</h2>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 'var(--space-4)' }}>
          {[1, 2, 3, 4].map((n) => (
            <div key={n} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', paddingTop: 'var(--space-4)', borderTop: '2px solid var(--plum-200)' }}>
              <span className="fb-num" style={{ font: 'var(--weight-bold) var(--text-2xl)/1 var(--font-display)', color: 'var(--text-brand)' }}>0{n}</span>
              <h3 style={{ font: 'var(--type-heading-4)' }}>{t(`steps.${n}t`)}</h3>
              <p style={{ font: 'var(--type-body)', color: 'var(--text-secondary)' }}>{t(`steps.${n}d`)}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ------------------------------------------------------- Differentiators ---- */

export function Differentiators({ payload }: { payload: LandingPayload }) {
  const t = useT(payload);
  const items: { icon: string; n: number }[] = [
    { icon: 'wallet', n: 1 }, { icon: 'building', n: 2 }, { icon: 'languages', n: 3 }, { icon: 'layers', n: 4 },
  ];
  return (
    <section style={{ background: 'var(--surface-card)', padding: 'var(--space-24) 0' }}>
      <div style={{ ...SECTION, display: 'grid', gridTemplateColumns: '0.9fr 1.1fr', gap: 'var(--space-12)', alignItems: 'start' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
          <span className="fb-eyebrow">{t('diff.eyebrow')}</span>
          <h2 style={{ font: 'var(--type-heading-1)' }}>{t('diff.title')}</h2>
          {/* Une citation ne s'affiche que si quelqu'un l'a réellement prononcée :
              vider `quote.text` dans tfb_translations retire le bloc. Un témoignage
              d'attente sur une page commerciale se lit comme un vrai témoignage. */}
          {payload.strings['quote.text'] && (
            <blockquote style={{ margin: 0, marginTop: 'var(--space-4)', padding: 'var(--space-5)', borderRadius: 'var(--radius-panel)', background: 'var(--surface-brand-subtle)', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
              <p style={{ font: 'var(--weight-semibold) var(--text-lg)/1.5 var(--font-display)', letterSpacing: 'var(--tracking-tight)' }}>{t('quote.text')}</p>
              <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 36, height: 36, flex: 'none', borderRadius: 'var(--radius-circle)', background: 'var(--plum-500)', color: '#fff', font: 'var(--weight-semibold) var(--text-sm)/1 var(--font-sans)' }}>
                  {t('quote.initials')}
                </span>
                <div style={{ display: 'flex', flexDirection: 'column' }}>
                  <span style={{ font: 'var(--weight-medium) var(--text-base)/1.3 var(--font-sans)' }}>{t('quote.author')}</span>
                  <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-secondary)' }}>{t('quote.role')}</span>
                </div>
              </div>
            </blockquote>
          )}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)' }}>
          {items.map((it) => (
            <Card key={it.n} padding="lg" interactive>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 40, height: 40, borderRadius: 'var(--radius-md)', background: 'var(--ember-50)', color: 'var(--ember-700)' }}>
                  <Icon name={it.icon} size={20} />
                </span>
                <h3 style={{ font: 'var(--type-heading-4)' }}>{t(`diff.${it.n}t`)}</h3>
                <p style={{ font: 'var(--type-body)', color: 'var(--text-secondary)' }}>{t(`diff.${it.n}d`)}</p>
              </div>
            </Card>
          ))}
        </div>
      </div>
    </section>
  );
}

/* --------------------------------------------------------------- Pricing ---- */

export function Pricing({
  payload,
  onCheckout,
}: {
  payload: LandingPayload;
  onCheckout: (planKey: string) => void;
}) {
  const t = useT(payload);
  return (
    <section id="pricing" style={{ background: 'var(--surface-page)', padding: 'var(--space-24) 0' }}>
      <div style={SECTION}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', alignItems: 'center', textAlign: 'center', marginBottom: 'var(--space-10)' }}>
          <span className="fb-eyebrow">{t('pricing.eyebrow')}</span>
          <h2 style={{ font: 'var(--type-heading-1)' }}>{t('pricing.title')}</h2>
          <p style={{ font: 'var(--type-body-lg)', color: 'var(--text-secondary)', maxWidth: '58ch' }}>{t('pricing.lead')}</p>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 'var(--space-4)', alignItems: 'start' }}>
          {payload.plans.map((p) => (
            <PlanCard
              key={p.key}
              name={p.name}
              priceLabel={p.priceLabel ?? t('pricing.custom')}
              intervalLabel={p.amount != null ? t('pricing.month') : null}
              description={p.description}
              features={p.features}
              featured={p.featured}
              badgeLabel={t('pricing.recommended')}
              ctaLabel={p.amount != null ? t('plan.cta') : t('plan.cta.custom')}
              onSelect={() => onCheckout(p.key)}
            />
          ))}
        </div>
      </div>
    </section>
  );
}

/* --------------------------------------------------------- Module detail ---- */

/**
 * Le franchiseur d'abord, l'outil ensuite. Chaque carte met sa phrase — celle
 * qu'il dit vraiment en rendez-vous — face à ce que l'exploitation écrite en fait.
 *
 * Cinq paires, numérotées dans les traductions (pain.1p / pain.1a …), pour qu'on
 * puisse en ajouter ou en retirer depuis le back-office sans toucher au code.
 */
export function Pains({ payload }: { payload: LandingPayload }) {
  const t = useT(payload);
  const pairs = [1, 2, 3, 4, 5]
    .map((n) => ({ n, pain: payload.strings[`pain.${n}p`], answer: payload.strings[`pain.${n}a`] }))
    .filter((p) => p.pain && p.answer);

  if (pairs.length === 0) return null;

  return (
    <section style={{ background: 'var(--surface-page)', padding: 'var(--space-20) 0' }}>
      <div style={SECTION}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)', maxWidth: '62ch' }}>
          <span className="fb-eyebrow">{t('pain.eyebrow')}</span>
          <h2 style={{ font: 'var(--type-display-3)', letterSpacing: 'var(--tracking-display)' }}>{t('pain.title')}</h2>
          <p style={{ font: 'var(--type-body-lg)', color: 'var(--text-secondary)' }}>{t('pain.lead')}</p>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 'var(--space-4)', marginTop: 'var(--space-10)' }}>
          {pairs.map(({ n, pain, answer }) => (
            <Card key={n} padding="lg">
              <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
                <p style={{ font: 'var(--weight-semibold) var(--text-lg)/1.45 var(--font-display)', letterSpacing: 'var(--tracking-tight)', color: 'var(--text-primary)' }}>
                  {pain}
                </p>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-3)', paddingTop: 'var(--space-4)', borderTop: '1px solid var(--border-subtle)' }}>
                  <Icon name="arrow-up-right" size={18} color="var(--plum-600)" className="fb-flip" style={{ marginTop: 3, flex: 'none' }} />
                  <p style={{ font: 'var(--type-body)', color: 'var(--text-secondary)' }}>{answer}</p>
                </div>
              </div>
            </Card>
          ))}
        </div>
      </div>
    </section>
  );
}

/** Layout C — the page tfb_modules.redirect_url opens. */
export function ModuleDetail({
  payload,
  moduleKey,
  onDemo,
}: {
  payload: LandingPayload;
  moduleKey: string;
  onDemo: () => void;
}) {
  const t = useT(payload);
  const m = payload.modules.find((x) => x.key === moduleKey) ?? payload.modules[0]!;
  const connected = payload.modules.filter((x) => x.key !== m.key);
  const [metricValue, metricLabel] = m.metric;
  const shots = m.screenshots.map((s) => ({ src: s.src ?? undefined, alt: s.alt }));

  return (
    <>
      <section style={{ background: 'var(--gradient-ink)', color: 'var(--text-inverse)', padding: 'var(--space-12) 0 var(--space-16)' }}>
        <div style={SECTION}>
          <Link
            href={`/${payload.locale}#modules`}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginBottom: 'var(--space-6)', font: 'var(--type-body-sm)', color: 'var(--text-inverse-secondary)', textDecoration: 'none' }}
          >
            <Icon name="arrow-left" size={15} className="fb-flip" />{t('detail.back')}
          </Link>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1.1fr', gap: 'var(--space-12)', alignItems: 'center' }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)' }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 52, height: 52, flex: 'none', borderRadius: 'var(--radius-lg)', background: 'var(--alpha-white-08)', border: '1px solid var(--border-inverse)', color: 'var(--plum-300)' }}>
                  <Icon name={m.icon} size={26} />
                </span>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)' }}>
                    <h1 style={{ font: 'var(--type-heading-1)', color: 'var(--text-inverse)' }}>{m.name}</h1>
                    {m.isNew && <Badge tone="warm">{t('modules.new')}</Badge>}
                  </div>
                  <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-inverse-secondary)' }}>
                    {m.group} · <span className="fb-num">{m.repo ?? m.key}</span>
                  </span>
                </div>
              </div>
              <p style={{ font: 'var(--type-body-lg)', color: 'var(--text-inverse-secondary)', maxWidth: '52ch' }}>{m.description}</p>
              <div style={{ display: 'flex', alignItems: 'baseline', gap: 'var(--space-3)', alignSelf: 'flex-start', padding: 'var(--space-3) var(--space-4)', borderRadius: 'var(--radius-md)', background: 'var(--alpha-white-08)', border: '1px solid var(--border-inverse)' }}>
                <span className="fb-num" style={{ font: 'var(--weight-bold) var(--text-3xl)/1 var(--font-display)', color: 'var(--ember-300)' }}>{metricValue}</span>
                <span style={{ font: 'var(--type-body-sm)', color: 'var(--text-inverse-secondary)' }}>{metricLabel}</span>
              </div>
              <div style={{ display: 'flex', gap: 'var(--space-3)' }}>
                <Button variant="warm" size="lg" iconRight="arrow-right" onClick={onDemo}>{t('cta.demo')}</Button>
                <Button variant="inverse" size="lg" iconLeft="mail">{t('detail.sheet')}</Button>
              </div>
            </div>
            <ModuleFrame payload={payload} moduleKey={m.key} shots={shots} />
          </div>
        </div>
      </section>

      {m.overview && (
        <section style={{ background: 'var(--surface-page)', padding: 'var(--space-14) 0' }}>
          <div style={{ ...SECTION, maxWidth: 760 }}>
            <span className="fb-eyebrow">{t('detail.overview')}</span>
            <p style={{ marginTop: 'var(--space-4)', font: 'var(--type-body-lg)', color: 'var(--text-secondary)' }}>{m.overview}</p>
          </div>
        </section>
      )}

      {m.features.length > 0 && (
        <section style={{ background: 'var(--surface-card)', padding: 'var(--space-16) 0' }}>
          <div style={SECTION}>
            <span className="fb-eyebrow">{t('detail.functions')}</span>
            <h2 style={{ marginTop: 'var(--space-3)', font: 'var(--type-heading-1)', color: 'var(--text-primary)' }}>{t('detail.functions.title')}</h2>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-12)', marginTop: 'var(--space-10)' }}>
              {m.features.map((f, i) => (
                <FeatureRow key={f.key} payload={payload} feature={f} flipped={i % 2 === 1} />
              ))}
            </div>
          </div>
        </section>
      )}

      <section style={{ background: 'var(--surface-card)', padding: 'var(--space-16) 0' }}>
        <div style={{ ...SECTION, display: 'grid', gridTemplateColumns: '1.1fr 0.9fr', gap: 'var(--space-12)', alignItems: 'start' }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)' }}>
            <span className="fb-eyebrow">{t('detail.included')}</span>
            {m.bullets.length > 0 ? (
              <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)' }}>
                {m.bullets.map((b) => (
                  <li key={b} style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-2)', font: 'var(--type-body)' }}>
                    <Icon name="circle-check" size={17} color="var(--status-success)" style={{ marginTop: 2 }} />{b}
                  </li>
                ))}
              </ul>
            ) : m.features.length > 0 ? (
              // The functions section above already carries the explanation; the
              // bullets are the older, shorter form of the same thing.
              <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)' }}>
                {m.features.map((f) => (
                  <li key={f.key} style={{ display: 'flex', alignItems: 'flex-start', gap: 'var(--space-2)', font: 'var(--type-body)' }}>
                    <Icon name="circle-check" size={17} color="var(--status-success)" style={{ marginTop: 2 }} />{f.name}
                  </li>
                ))}
              </ul>
            ) : (
              // Explanation bullets live in tfb_translations (field 'bullets'). An
              // empty list says so instead of inventing copy.
              <p style={{ font: 'var(--type-body)', color: 'var(--text-tertiary)' }}>
                Aucune puce d’explication n’est encore saisie pour ce module (<span className="fb-num">tfb_translations · bullets</span>).
              </p>
            )}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-3)', marginTop: 'var(--space-2)' }}>
              <StatTile label={t('detail.gain')} value={metricValue} unit={metricLabel} icon="trending-up" />
              <StatTile label={t('detail.setup')} value="1" unit={t('detail.week')} icon="clock" />
            </div>
          </div>
          <Card tone="sunken" padding="lg" title={t('detail.connected')}>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
              {connected.map((x) => (
                <Link key={x.key} href={`/${payload.locale}/modules/${x.slug}`} style={{ textDecoration: 'none' }}>
                  <Tag icon={x.icon} interactive>{x.name}</Tag>
                </Link>
              ))}
            </div>
          </Card>
        </div>
      </section>
    </>
  );
}

/**
 * One documented function: what it does on the left, the screens that prove it
 * on the right. Rows alternate sides, and the alternation is written with
 * `direction` rather than left/right so Arabic mirrors with the rest of the page.
 */
function FeatureRow({
  payload,
  feature,
  flipped,
}: {
  payload: LandingPayload;
  feature: LandingPayload['modules'][number]['features'][number];
  flipped: boolean;
}) {
  const t = useT(payload);
  const shots = feature.screenshots.map((s) => ({ src: s.src ?? undefined, alt: s.alt }));

  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: '0.9fr 1.1fr',
        gap: 'var(--space-10)',
        alignItems: 'center',
        direction: flipped ? (payload.dir === 'rtl' ? 'ltr' : 'rtl') : undefined,
      }}
    >
      <div style={{ direction: payload.dir, display: 'flex', flexDirection: 'column', gap: 'var(--space-3)' }}>
        <span
          style={{
            display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 44, height: 44,
            borderRadius: 'var(--radius-lg)', background: 'var(--plum-50)', color: 'var(--plum-600)',
          }}
        >
          <Icon name={feature.icon} size={22} />
        </span>
        <h3 style={{ font: 'var(--type-heading-3)', color: 'var(--text-primary)' }}>{feature.name}</h3>
        {feature.description ? (
          <p style={{ font: 'var(--type-body)', color: 'var(--text-secondary)', maxWidth: '46ch' }}>{feature.description}</p>
        ) : (
          <p style={{ font: 'var(--type-body-sm)', color: 'var(--text-tertiary)' }}>
            <span className="fb-num">tfb_translations · feature · description</span>
          </p>
        )}
      </div>
      <div style={{ direction: payload.dir }}>
        <Carousel
          items={shots.length > 0 ? shots : [{ src: undefined, alt: t('shot.alt') }]}
          rtl={payload.dir === 'rtl'}
          autoplay={shots.length > 1}
          aspect="16 / 10"
          placeholderPath={`/storage/screenshots/${feature.key}-…`}
        />
      </div>
    </div>
  );
}

/** The app-frame treatment around the module carousel, with autoplay. */
function ModuleFrame({
  payload,
  moduleKey,
  shots,
}: {
  payload: LandingPayload;
  moduleKey: string;
  shots: { src?: string; alt: string }[];
}) {
  return (
    <div style={{ borderRadius: 'var(--radius-panel)', overflow: 'hidden', border: '1px solid var(--border-inverse)', background: 'var(--surface-card)', boxShadow: 'var(--shadow-xl)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '10px 14px', borderBottom: '1px solid var(--border-subtle)', background: 'var(--slate-25)' }}>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src="/brand/logo-mark.png" alt="" width={16} height={16} />
        <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-secondary)' }}>tfb / {moduleKey}</span>
        <span className="fb-num" style={{ marginInlineStart: 'auto', font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{shots.length}</span>
      </div>
      <div style={{ padding: 'var(--space-4)', background: 'var(--surface-page)' }}>
        <Carousel
          items={shots}
          rtl={payload.dir === 'rtl'}
          autoplay
          aspect="16 / 10"
          placeholderPath={`/storage/screenshots/${moduleKey}-…`}
        />
      </div>
    </div>
  );
}
