import { notFound } from 'next/navigation';
import { prisma } from '@/lib/prisma';
import { formatDate, getEditorLanguages, getTranslationGrid, SEED_FIELDS } from '@/lib/admin/queries';
import { ModuleEditor } from '@/components/admin/ModuleEditor';

/** One module: its row, its screenshot carousel, and its 8-language translations. */
export default async function ModuleEditorPage({ params }: { params: Promise<{ id: string }> }) {
  const { id: raw } = await params;
  const id = Number(raw);
  if (!Number.isInteger(id) || id <= 0) notFound();

  const moduleRow = await prisma.module.findUnique({
    where: { id },
    include: { screenshots: { orderBy: { sortOrder: 'asc' } } },
  });
  if (!moduleRow) notFound();

  const languages = await getEditorLanguages();
  const fields = await getTranslationGrid('module', moduleRow.id, languages, SEED_FIELDS.module);

  return (
    <ModuleEditor
      module={{
        id: moduleRow.id,
        key: moduleRow.key,
        slug: moduleRow.slug,
        icon: moduleRow.icon ?? 'layers',
        moduleGroup: moduleRow.moduleGroup,
        repo: moduleRow.repo,
        redirectUrl: moduleRow.redirectUrl,
        isActive: moduleRow.isActive,
        isNew: moduleRow.isNew,
        updatedAt: formatDate(moduleRow.updatedAt),
      }}
      screenshots={moduleRow.screenshots.map((s) => ({ id: s.id, filePath: s.filePath, sortOrder: s.sortOrder }))}
      languages={languages}
      fields={fields}
    />
  );
}
