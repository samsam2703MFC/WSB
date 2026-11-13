'use client';

import React from 'react';
import { Button, Icon, Input, Textarea } from '@/design-system';
import type { LandingPayload } from '@/lib/landing/types';
import { SECTION } from './layout';

export interface ContactResult {
  tone: 'success' | 'danger';
  title: string;
  message: string;
}

/** POST /api/contact → tfb_contact_messages. Honeypot field included. */
export function ContactSection({
  payload,
  onResult,
}: {
  payload: LandingPayload;
  onResult: (result: ContactResult) => void;
}) {
  const t = (key: string) => payload.strings[key] ?? key;
  const [form, setForm] = React.useState({ name: '', email: '', company: '', message: '', website: '' });
  const [errors, setErrors] = React.useState<Record<string, string>>({});
  const [sending, setSending] = React.useState(false);

  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    setForm((f) => ({ ...f, [key]: e.target.value }));

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSending(true);
    setErrors({});
    try {
      const response = await fetch('/api/contact', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, languageCode: payload.locale }),
      });
      const body = (await response.json().catch(() => ({}))) as { fields?: Record<string, string>; error?: string };

      if (!response.ok) {
        if (body.fields) setErrors(body.fields);
        onResult({ tone: 'danger', title: t('contact.error'), message: body.error ?? t('contact.errormsg') });
        return;
      }
      setForm({ name: '', email: '', company: '', message: '', website: '' });
      onResult({ tone: 'success', title: t('contact.sent'), message: t('contact.sentmsg') });
    } catch {
      onResult({ tone: 'danger', title: t('contact.error'), message: t('contact.errormsg') });
    } finally {
      setSending(false);
    }
  };

  return (
    <section id="contact" style={{ background: 'var(--surface-card)', padding: 'var(--space-24) 0' }}>
      <div style={{ ...SECTION, display: 'grid', gridTemplateColumns: '0.9fr 1.1fr', gap: 'var(--space-12)', alignItems: 'start' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
          <span className="fb-eyebrow">{t('contact.eyebrow')}</span>
          <h2 style={{ font: 'var(--type-heading-1)' }}>{t('contact.title')}</h2>
          <p style={{ font: 'var(--type-body-lg)', color: 'var(--text-secondary)' }}>{t('contact.lead')}</p>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)', marginTop: 'var(--space-2)' }}>
            <span style={contactLine}><Icon name="mail" size={16} color="var(--text-tertiary)" />{t('contact.mail')}</span>
            <span style={contactLine}><Icon name="clock" size={16} color="var(--text-tertiary)" />{t('contact.hours')}</span>
          </div>
        </div>
        <form
          onSubmit={submit}
          style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)', padding: 'var(--space-6)', borderRadius: 'var(--radius-panel)', background: 'var(--surface-page)', border: '1px solid var(--border-default)' }}
        >
          <Input label={t('contact.name')} value={form.name} onChange={set('name')} error={errors.name} required />
          <Input label={t('contact.email')} type="email" iconLeft="mail" value={form.email} onChange={set('email')} error={errors.email} required />
          <Input label={t('contact.company')} value={form.company} onChange={set('company')} wrapperStyle={{ gridColumn: 'span 2' }} />
          <Textarea
            label={t('contact.message')} rows={5} maxLength={1200} showCount
            value={form.message} onChange={set('message')} error={errors.message}
            wrapperStyle={{ gridColumn: 'span 2' }} required
          />
          {/* Honeypot. Uses .fb-sr (clip) rather than an off-screen offset, so it
              cannot cause overflow in either writing direction. */}
          <input
            type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true"
            className="fb-sr" value={form.website} onChange={set('website')}
          />
          <div style={{ gridColumn: 'span 2', display: 'flex', alignItems: 'center', gap: 'var(--space-4)' }}>
            <Button type="submit" variant="warm" iconRight="arrow-right" loading={sending} disabled={sending}>
              {t('contact.send')}
            </Button>
          </div>
        </form>
      </div>
    </section>
  );
}

const contactLine = {
  display: 'inline-flex',
  alignItems: 'center',
  gap: 8,
  font: 'var(--type-body)',
  color: 'var(--text-secondary)',
} as const;
