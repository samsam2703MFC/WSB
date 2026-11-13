'use client';

import React from 'react';
import { Button, Dialog, Input, Select, Toast } from '@/design-system';
import type { LandingPayload } from '@/lib/landing/types';
import { LandingHeader, LandingFooter } from './Chrome';
import { Hero, BrandStrip, Pains, ModulesGrid, Steps, Differentiators, Pricing, ModuleDetail } from './Sections';
import { ContactSection, type ContactResult } from './ContactSection';

type ToastState = { tone: 'info' | 'success' | 'warning' | 'danger'; title: string; message: string } | null;

/**
 * The landing shell: chrome, the demo dialog, the toast stack, and whichever body
 * the route asked for — the section stack (layout A) or a module page (layout C).
 *
 * Which sections render, and in what order, comes from tfb_sections. Nothing here
 * is hard-coded except the mapping from a section `type` to its component.
 */
export function LandingApp({
  payload,
  moduleKey,
}: {
  payload: LandingPayload;
  /** Set on /[locale]/modules/[slug]; absent on the home route. */
  moduleKey?: string;
}) {
  const [demoOpen, setDemoOpen] = React.useState(false);
  const [toast, setToast] = React.useState<ToastState>(null);
  const timer = React.useRef<number | undefined>(undefined);

  const t = (key: string) => payload.strings[key] ?? key;

  const flash = React.useCallback((next: NonNullable<ToastState>) => {
    setToast(next);
    window.clearTimeout(timer.current);
    timer.current = window.setTimeout(() => setToast(null), 5200);
  }, []);

  React.useEffect(() => () => window.clearTimeout(timer.current), []);

  const checkout = async (planKey: string) => {
    try {
      const response = await fetch('/api/checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ planKey }),
      });
      const body = (await response.json()) as { status?: string; url?: string; reason?: string; error?: string };

      if (body.status === 'redirect' && body.url) {
        window.location.href = body.url;
        return;
      }
      if (body.status === 'contact') {
        // "Sur devis" plans route to the form, not to Checkout.
        document.getElementById('contact')?.scrollIntoView({ behavior: 'smooth' });
        return;
      }
      flash({
        tone: body.status === 'stub' ? 'info' : 'danger',
        title: t('checkout.title'),
        message: body.reason ?? body.error ?? t('checkout.stub'),
      });
    } catch {
      flash({ tone: 'danger', title: t('checkout.title'), message: t('checkout.stub') });
    }
  };

  const onContactResult = (result: ContactResult) => flash(result);

  // tfb_sections drives the order; a type with no renderer is skipped rather than
  // guessed at.
  const renderers: Record<string, React.ReactNode> = {
    hero: <Hero key="hero" payload={payload} onDemo={() => setDemoOpen(true)} />,
    'logo-strip': <BrandStrip key="brands" payload={payload} />,
    pains: <Pains key="pains" payload={payload} />,
    'module-grid': <ModulesGrid key="modules" payload={payload} />,
    steps: <Steps key="steps" payload={payload} />,
    'feature-grid': <Differentiators key="diff" payload={payload} />,
    pricing: <Pricing key="pricing" payload={payload} onCheckout={checkout} />,
    form: <ContactSection key="contact" payload={payload} onResult={onContactResult} />,
  };

  return (
    <div style={{ position: 'relative', minHeight: '100vh' }}>
      <LandingHeader payload={payload} onDemo={() => setDemoOpen(true)} />

      {moduleKey ? (
        <ModuleDetail payload={payload} moduleKey={moduleKey} onDemo={() => setDemoOpen(true)} />
      ) : (
        payload.sections.map((s) => renderers[s.type] ?? null)
      )}

      <LandingFooter payload={payload} />

      <Dialog
        open={demoOpen}
        title={t('demo.title')}
        description={t('demo.desc')}
        onClose={() => setDemoOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDemoOpen(false)}>{t('demo.cancel')}</Button>
            <Button
              variant="warm"
              iconRight="arrow-right"
              onClick={() => {
                setDemoOpen(false);
                // A demo request is a contact message; the form below is the
                // recorded path, so this points the visitor at it.
                document.getElementById('contact')?.scrollIntoView({ behavior: 'smooth' });
              }}
            >
              {t('demo.confirm')}
            </Button>
          </>
        }
      >
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)' }}>
          <Input label={t('contact.name')} />
          <Input label={t('contact.email')} iconLeft="mail" />
          <Input label={t('contact.company')} />
          <Select label={t('demo.locations')} options={['1 — 9', '10 — 49', '50 — 199', '200+']} />
        </div>
      </Dialog>

      {toast && (
        <div style={{ position: 'fixed', insetInlineEnd: 24, bottom: 24, zIndex: 80 }}>
          <Toast {...toast} onDismiss={() => setToast(null)} />
        </div>
      )}
    </div>
  );
}
