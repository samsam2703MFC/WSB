'use client';

import React from 'react';
import { Badge, Button, Card, Textarea, Toast } from '@/design-system';

export interface EditorLanguage {
  code: string;
  name: string;
  isRtl: boolean;
  isDefault: boolean;
}

export interface EditorField {
  field: string;
  values: Record<string, string>;
}

/**
 * The 8-language editor: one column per active locale, the default locale first.
 *
 * An empty cell is not stored as '' — it is deleted, because an absent
 * tfb_translations row is exactly what makes the resolver fall back to French.
 * The cell says so, so nobody mistakes a blank for a bug.
 */
export function TranslationEditor({
  entityType,
  entityId,
  entityLabel,
  languages,
  fields,
}: {
  entityType: string;
  entityId: number;
  entityLabel: string;
  languages: EditorLanguage[];
  fields: EditorField[];
}) {
  const [rows, setRows] = React.useState(fields);
  const [onlyIncomplete, setOnlyIncomplete] = React.useState(false);
  const [saving, setSaving] = React.useState(false);
  const [dirty, setDirty] = React.useState(false);
  const [toast, setToast] = React.useState<{ tone: 'success' | 'danger'; title: string; message: string } | null>(null);

  const defaultCode = languages.find((l) => l.isDefault)?.code ?? 'fr';

  const set = (fieldIndex: number, code: string) => (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const value = e.target.value;
    setDirty(true);
    setRows((current) => current.map((r, i) => (i === fieldIndex ? { ...r, values: { ...r.values, [code]: value } } : r)));
  };

  const missing = rows.reduce((n, r) => n + languages.filter((l) => !r.values[l.code]).length, 0);
  const shown = onlyIncomplete ? languages.filter((l) => rows.some((r) => !r.values[l.code])) : languages;

  const save = async () => {
    setSaving(true);
    try {
      const values: Record<string, Record<string, string>> = {};
      for (const r of rows) values[r.field] = r.values;

      const response = await fetch('/api/admin/translations', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ entityType, entityId, values }),
      });
      if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as { error?: string };
        setToast({ tone: 'danger', title: 'Enregistrement impossible', message: body.error ?? 'Aucune traduction modifiée.' });
        return;
      }
      setDirty(false);
      setToast({ tone: 'success', title: 'Traductions enregistrées', message: 'La landing se met à jour au prochain rendu.' });
    } catch {
      setToast({ tone: 'danger', title: 'Enregistrement impossible', message: 'Le serveur est injoignable.' });
    } finally {
      setSaving(false);
    }
  };

  if (rows.length === 0) {
    return (
      <Card padding="lg" title="Éditeur de traductions" subtitle={`${entityLabel} · tfb_translations`}>
        <p style={{ font: 'var(--type-body)', color: 'var(--text-tertiary)' }}>
          Aucun champ traduisible n’existe encore pour cette entité.
        </p>
      </Card>
    );
  }

  return (
    <>
      <Card
        padding="none"
        title="Éditeur de traductions"
        subtitle={`${entityLabel} · tfb_translations`}
        actions={
          <>
            <Badge tone={missing ? 'warning' : 'success'} dot>
              {missing ? `${missing} valeurs manquantes` : 'Complet'}
            </Badge>
            <Button variant="secondary" size="sm" iconLeft="funnel" onClick={() => setOnlyIncomplete((v) => !v)}>
              {onlyIncomplete ? 'Toutes les langues' : 'Langues incomplètes'}
            </Button>
            <Button size="sm" iconLeft="save" loading={saving} disabled={saving || !dirty} onClick={save}>
              Enregistrer
            </Button>
          </>
        }
        footer={`Une valeur vide affiche le ${defaultCode.toUpperCase()} sur la landing (fallback langue par défaut).`}
      >
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 900 }}>
            <thead>
              <tr>
                <th style={{ ...thStyle, position: 'sticky', insetInlineStart: 0, background: 'var(--slate-25)', width: 150 }}>Champ</th>
                {shown.map((l) => (
                  <th key={l.code} style={{ ...thStyle, minWidth: 210 }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                      <span className="fb-num">{l.code.toUpperCase()}</span>
                      <span style={{
                        color: 'var(--text-tertiary)', fontWeight: 'var(--weight-regular)',
                        textTransform: 'none', letterSpacing: 0,
                        fontFamily: l.isRtl ? 'var(--font-arabic)' : undefined,
                      }}>{l.name}</span>
                      {l.isDefault && <Badge size="sm" tone="brand">défaut</Badge>}
                      {l.isRtl && <Badge size="sm" tone="neutral">RTL</Badge>}
                    </span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, fieldIndex) => (
                <tr key={r.field}>
                  <td style={{ ...tdStyle, position: 'sticky', insetInlineStart: 0, background: 'var(--surface-card)', verticalAlign: 'top' }}>
                    <span className="fb-num" style={{ font: 'var(--type-body-sm)', fontWeight: 'var(--weight-medium)' }}>{r.field}</span>
                  </td>
                  {shown.map((l) => (
                    <td key={l.code} style={{ ...tdStyle, verticalAlign: 'top' }}>
                      <Textarea
                        rows={2}
                        value={r.values[l.code] ?? ''}
                        onChange={set(fieldIndex, l.code)}
                        placeholder={r.values[defaultCode] ?? ''}
                        error={!r.values[l.code] ? `Vide — fallback ${defaultCode.toUpperCase()}` : undefined}
                        // Arabic cells are authored right-to-left in the Arabic face.
                        style={{ direction: l.isRtl ? 'rtl' : 'ltr', fontFamily: l.isRtl ? 'var(--font-arabic)' : undefined }}
                      />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      {toast && (
        <div style={{ position: 'fixed', insetInlineEnd: 24, bottom: 24, zIndex: 80 }}>
          <Toast {...toast} onDismiss={() => setToast(null)} />
        </div>
      )}
    </>
  );
}

const thStyle: React.CSSProperties = {
  textAlign: 'start',
  font: 'var(--weight-semibold) var(--text-xs)/1 var(--font-sans)',
  letterSpacing: 'var(--tracking-wide)', textTransform: 'uppercase', color: 'var(--text-tertiary)',
  padding: 'var(--space-3) var(--space-4)', background: 'var(--slate-25)',
  borderBottom: '1px solid var(--border-default)', whiteSpace: 'nowrap',
};

const tdStyle: React.CSSProperties = {
  padding: 'var(--space-3) var(--space-4)',
  borderBottom: '1px solid var(--border-subtle)',
};
