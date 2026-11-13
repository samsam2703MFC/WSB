'use client';

import React from 'react';
import { useRouter } from 'next/navigation';
import { Button, Input } from '@/design-system';

/** Sign-in against tfb_admin_users. Roles: superadmin | admin. */
export function LoginForm() {
  const router = useRouter();
  const [email, setEmail] = React.useState('');
  const [password, setPassword] = React.useState('');
  const [error, setError] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const response = await fetch('/api/admin/auth/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as { error?: string };
        setError(body.error ?? 'Connexion impossible.');
        return;
      }
      // replace(), not push(): the sign-in screen should not be a back-button stop.
      router.replace('/admin');
    } catch {
      setError('Connexion impossible.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-4)' }}>
      <Input
        label="E-mail" type="email" iconLeft="mail" autoComplete="username"
        value={email} onChange={(e) => setEmail(e.target.value)} required
      />
      <Input
        label="Mot de passe" type="password" iconLeft="lock" autoComplete="current-password"
        value={password} onChange={(e) => setPassword(e.target.value)}
        error={error ?? undefined} required
      />
      <Button type="submit" size="lg" iconRight="arrow-right" fullWidth loading={busy} disabled={busy}>
        Se connecter
      </Button>
      <span style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>
        Sessions signées · rôles superadmin / admin
      </span>
    </form>
  );
}
