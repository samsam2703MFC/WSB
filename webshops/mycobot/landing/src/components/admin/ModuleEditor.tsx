'use client';

import React from 'react';
import { useRouter } from 'next/navigation';
import {
  Button, Card, FileDrop, Icon, IconButton, Input, Select, Switch, Tabs, Toast,
} from '@/design-system';
import { TranslationEditor, type EditorField, type EditorLanguage } from './TranslationEditor';

export interface EditableModule {
  id: number;
  key: string;
  slug: string;
  icon: string;
  moduleGroup: string | null;
  repo: string | null;
  redirectUrl: string | null;
  isActive: boolean;
  isNew: boolean;
  updatedAt: string;
}

export interface EditableScreenshot {
  id: number;
  filePath: string;
  sortOrder: number;
}

const GROUPS = ['Ventes', 'Finance', 'Marketing', 'Logistique', 'Assistance', 'Terrain'];

/** Module editor: the tfb_modules row, its screenshot carousel, and its translations. */
export function ModuleEditor({
  module,
  screenshots,
  languages,
  fields,
}: {
  module: EditableModule;
  screenshots: EditableScreenshot[];
  languages: EditorLanguage[];
  fields: EditorField[];
}) {
  const router = useRouter();
  const [tab, setTab] = React.useState('contenu');
  const [form, setForm] = React.useState({
    slug: module.slug,
    icon: module.icon,
    moduleGroup: module.moduleGroup ?? GROUPS[0]!,
    repo: module.repo ?? '',
    redirectUrl: module.redirectUrl ?? '',
    isActive: module.isActive,
    isNew: module.isNew,
  });
  const [shots, setShots] = React.useState(screenshots);
  const [saving, setSaving] = React.useState(false);
  const [toast, setToast] = React.useState<{ tone: 'success' | 'danger' | 'info'; title: string; message: string } | null>(null);

  const flash = (next: NonNullable<typeof toast>) => setToast(next);

  const save = async () => {
    setSaving(true);
    try {
      const response = await fetch(`/api/admin/modules/${module.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          slug: form.slug,
          icon: form.icon,
          moduleGroup: form.moduleGroup,
          repo: form.repo || null,
          redirectUrl: form.redirectUrl || null,
          isActive: form.isActive,
          isNew: form.isNew,
        }),
      });
      if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as { error?: string };
        flash({ tone: 'danger', title: 'Enregistrement impossible', message: body.error ?? 'Le module est inchangé.' });
        return;
      }
      flash({ tone: 'success', title: 'Module enregistré', message: 'La landing se met à jour au prochain rendu.' });
      router.refresh();
    } finally {
      setSaving(false);
    }
  };

  const upload = async (files: File[]) => {
    if (files.length === 0) return;
    const body = new FormData();
    for (const f of files) body.append('files', f);
    const response = await fetch(`/api/admin/modules/${module.id}/screenshots`, { method: 'POST', body });
    const result = (await response.json().catch(() => ({}))) as { screenshots?: EditableScreenshot[]; error?: string };
    if (!response.ok) {
      flash({ tone: 'danger', title: 'Upload refusé', message: result.error ?? 'Le fichier a été refusé.' });
      // A partial upload still created rows; show them.
      if (result.screenshots?.length) setShots((s) => [...s, ...result.screenshots!]);
      return;
    }
    setShots((s) => [...s, ...(result.screenshots ?? [])]);
    flash({ tone: 'success', title: 'Screenshots ajoutés', message: `${result.screenshots?.length ?? 0} fichier(s) dans /storage/screenshots/.` });
    router.refresh();
  };

  const remove = async (shot: EditableScreenshot) => {
    const response = await fetch(`/api/admin/modules/${module.id}/screenshots/${shot.id}`, { method: 'DELETE' });
    if (!response.ok) {
      flash({ tone: 'danger', title: 'Suppression impossible', message: shot.filePath });
      return;
    }
    setShots((s) => s.filter((x) => x.id !== shot.id));
    router.refresh();
  };

  /** Moves a frame and persists the whole new order — sort_order is the carousel. */
  const move = async (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= shots.length) return;
    const next = [...shots];
    [next[index], next[target]] = [next[target]!, next[index]!];
    const renumbered = next.map((s, i) => ({ ...s, sortOrder: i + 1 }));
    setShots(renumbered);

    const response = await fetch(`/api/admin/modules/${module.id}/screenshots`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order: renumbered.map((s) => ({ id: s.id, sortOrder: s.sortOrder })) }),
    });
    if (!response.ok) {
      setShots(screenshots);
      flash({ tone: 'danger', title: 'Réordonnancement impossible', message: 'L’ordre précédent a été restauré.' });
    }
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)', maxWidth: 1240 }}>
      <Tabs
        items={[
          { value: 'contenu', label: 'Contenu' },
          { value: 'shots', label: 'Screenshots', count: shots.length },
          { value: 'trads', label: 'Traductions', icon: 'languages' },
        ]}
        value={tab}
        onChange={setTab}
      />

      {tab === 'contenu' && (
        <div style={{ display: 'grid', gridTemplateColumns: '1.3fr 1fr', gap: 'var(--space-4)', alignItems: 'start' }}>
          <Card
            title="Fiche module"
            subtitle={`tfb_modules · id ${module.id}`}
            padding="lg"
            footer={
              <div style={{ display: 'flex', gap: 'var(--space-3)' }}>
                <Button variant="secondary" onClick={() => router.push('/admin/modules')}>Annuler</Button>
                <Button iconLeft="save" loading={saving} disabled={saving} onClick={save}>Enregistrer</Button>
              </div>
            }
          >
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)' }}>
              {/* key is the identifier the morning routine and every translation row
                  key off, so it is immutable after publication. */}
              <Input label="Clé" value={module.key} hint="Immuable après publication" disabled readOnly />
              <Input label="Slug" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} />
              <Input
                label="URL de redirection" value={form.redirectUrl} iconRight="external-link"
                onChange={(e) => setForm({ ...form, redirectUrl: e.target.value })}
                wrapperStyle={{ gridColumn: 'span 2' }}
              />
              <Input label="Dépôt" value={form.repo} onChange={(e) => setForm({ ...form, repo: e.target.value })} />
              <Select
                label="Groupe d'affichage"
                options={GROUPS}
                value={form.moduleGroup}
                onChange={(e) => setForm({ ...form, moduleGroup: e.target.value })}
              />
              <Input
                label="Icône" value={form.icon} hint="Nom de fichier dans public/icons"
                onChange={(e) => setForm({ ...form, icon: e.target.value })}
              />
              <div style={{ display: 'flex', alignItems: 'flex-end', paddingBottom: 4 }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 40, height: 40, borderRadius: 'var(--radius-md)', background: 'var(--surface-brand-subtle)', color: 'var(--text-brand)' }}>
                  <Icon name={form.icon || 'layers'} size={20} />
                </span>
              </div>
              <div style={{ gridColumn: 'span 2', display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', paddingTop: 'var(--space-2)' }}>
                <Switch
                  label="Module actif" description="Visible sur la landing"
                  checked={form.isActive} onChange={(checked) => setForm({ ...form, isActive: checked })}
                />
                <Switch
                  label="Badge « Nouveau »" description="À retirer une fois le module installé dans le réseau"
                  checked={form.isNew} onChange={(checked) => setForm({ ...form, isNew: checked })}
                />
              </div>
            </div>
          </Card>
          <Card tone="brand" title="Écrit par la routine" subtitle={`Dernière écriture : ${module.updatedAt}`} padding="lg">
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-3)', font: 'var(--type-body-sm)' }}>
              {([
                ['tfb_modules', '1 ligne'],
                ['tfb_translations', `${fields.length} champs × ${languages.length} langues`],
                ['tfb_module_screenshots', `${shots.length} fichiers`],
              ] as [string, string][]).map(([table, detail]) => (
                <div key={table} style={{ display: 'flex', gap: 'var(--space-4)' }}>
                  <span className="fb-num" style={{ color: 'var(--text-secondary)' }}>{table}</span>
                  <span style={{ marginInlineStart: 'auto', fontWeight: 'var(--weight-medium)' }}>{detail}</span>
                </div>
              ))}
            </div>
          </Card>
        </div>
      )}

      {tab === 'shots' && (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 'var(--space-4)', alignItems: 'start' }}>
          <Card title="Carrousel" subtitle="L'ordre est sort_order." padding="lg">
            <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-2)' }}>
              {shots.length === 0 && (
                <p style={{ font: 'var(--type-body)', color: 'var(--text-tertiary)' }}>
                  Aucun screenshot — le carrousel affiche un emplacement nommé sur la landing.
                </p>
              )}
              {shots.map((s, i) => (
                <div key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-3)', padding: 'var(--space-2) var(--space-3)', border: '1px solid var(--border-default)', borderRadius: 'var(--radius-md)', background: 'var(--surface-card)' }}>
                  <Icon name="grip-vertical" size={15} color="var(--text-tertiary)" />
                  <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: 44, height: 30, flex: 'none', borderRadius: 'var(--radius-xs)', background: 'var(--surface-sunken)', color: 'var(--text-tertiary)', overflow: 'hidden' }}>
                    <Icon name="image" size={14} />
                  </span>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 1, flex: 1, minWidth: 0 }}>
                    <span style={{ font: 'var(--type-body-sm)', fontWeight: 'var(--weight-medium)' }}>{s.filePath.split('/').pop()}</span>
                    <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>{s.filePath}</span>
                  </div>
                  <span className="fb-num" style={{ font: 'var(--type-caption)', color: 'var(--text-tertiary)' }}>#{i + 1}</span>
                  <IconButton icon="chevron-left" label="Monter" size="sm" disabled={i === 0} onClick={() => move(i, -1)} style={{ transform: 'rotate(90deg)' }} />
                  <IconButton icon="chevron-right" label="Descendre" size="sm" disabled={i === shots.length - 1} onClick={() => move(i, 1)} style={{ transform: 'rotate(90deg)' }} />
                  <IconButton icon="trash-2" label="Supprimer" size="sm" onClick={() => remove(s)} />
                </div>
              ))}
            </div>
          </Card>
          <FileDrop
            label="Ajouter des screenshots"
            storagePath="/storage/screenshots/"
            onFiles={upload}
          />
        </div>
      )}

      {tab === 'trads' && (
        <TranslationEditor
          entityType="module"
          entityId={module.id}
          entityLabel={`module « ${module.key} »`}
          languages={languages}
          fields={fields}
        />
      )}

      {toast && (
        <div style={{ position: 'fixed', insetInlineEnd: 24, bottom: 24, zIndex: 80 }}>
          <Toast {...toast} onDismiss={() => setToast(null)} />
        </div>
      )}
    </div>
  );
}
