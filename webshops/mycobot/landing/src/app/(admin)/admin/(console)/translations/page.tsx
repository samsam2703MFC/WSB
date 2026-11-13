import { prisma } from '@/lib/prisma';
import { Card, Tag } from '@/design-system';
import { getEditorLanguages, getTranslationGrid, SEED_FIELDS } from '@/lib/admin/queries';
import { TranslationEditor } from '@/components/admin/TranslationEditor';
import { TranslationCoverage } from '@/components/admin/TranslationCoverage';
import Link from 'next/link';

/**
 * The translation editor, for any entity.
 *
 * ?entityType=module&entityId=4 — defaults to the `ui` pseudo-entity, which holds
 * every landing string (hero, nav, section headers, group captions) at entity_id 0.
 */
const ENTITY_TYPES = ['ui', 'module', 'plan', 'brand', 'section', 'screenshot'] as const;

type Search = Promise<{ entityType?: string; entityId?: string }>;

export default async function TranslationsPage({ searchParams }: { searchParams: Search }) {
  const params = await searchParams;
  const entityType = (ENTITY_TYPES as readonly string[]).includes(params.entityType ?? '')
    ? params.entityType!
    : 'ui';
  const parsedId = Number(params.entityId);
  const entityId = Number.isInteger(parsedId) && parsedId >= 0 ? parsedId : 0;

  const languages = await getEditorLanguages();
  const fields = await getTranslationGrid(entityType, entityId, languages, SEED_FIELDS[entityType] ?? []);

  // Coverage is per language across every translation row, not just this entity —
  // it is the number that tells you whether a locale is shippable.
  const [totalFields, perLanguage] = await Promise.all([
    prisma.translation.groupBy({ by: ['entityType', 'entityId', 'field'] }).then((g) => g.length),
    prisma.translation.groupBy({ by: ['languageCode'], _count: { _all: true } }),
  ]);
  const coverage = languages.map((l) => {
    const count = perLanguage.find((p) => p.languageCode === l.code)?._count._all ?? 0;
    return { ...l, coverage: totalFields > 0 ? Math.round((count / totalFields) * 100) : 0 };
  });

  const label = entityType === 'ui'
    ? 'chaînes de la landing (entity_type = ui)'
    : `${entityType} #${entityId}`;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 'var(--space-5)', maxWidth: 1240 }}>
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 380px', gap: 'var(--space-4)', alignItems: 'start' }}>
        <Card padding="lg" title="Entité à traduire" subtitle="Chaque entité a ses propres champs traduisibles.">
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 'var(--space-2)' }}>
            <Link href="/admin/translations?entityType=ui&entityId=0" style={{ textDecoration: 'none' }}>
              <Tag icon="panels-top-left" selected={entityType === 'ui'} interactive>Chaînes de la landing</Tag>
            </Link>
            <Link href="/admin/modules" style={{ textDecoration: 'none' }}>
              <Tag icon="layers" interactive>Modules</Tag>
            </Link>
            <Link href="/admin/plans" style={{ textDecoration: 'none' }}>
              <Tag icon="credit-card" interactive>Tarifs</Tag>
            </Link>
            <Link href="/admin/brands" style={{ textDecoration: 'none' }}>
              <Tag icon="store" interactive>Enseignes</Tag>
            </Link>
            <Link href="/admin/sections" style={{ textDecoration: 'none' }}>
              <Tag icon="grip-vertical" interactive>Sections</Tag>
            </Link>
          </div>
        </Card>
        <TranslationCoverage languages={coverage} />
      </div>

      <TranslationEditor
        entityType={entityType}
        entityId={entityId}
        entityLabel={label}
        languages={languages}
        fields={fields}
      />
    </div>
  );
}
