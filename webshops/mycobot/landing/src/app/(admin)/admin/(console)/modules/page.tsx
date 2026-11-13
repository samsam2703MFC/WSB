import { prisma } from '@/lib/prisma';
import { DEFAULT_LOCALE } from '@/lib/i18n/config';
import { formatDate } from '@/lib/admin/queries';
import { ModulesTable } from '@/components/admin/ContentScreens';

/** tfb_modules — fed by the morning routine, edited here. */
export default async function ModulesPage() {
  const [modules, names] = await Promise.all([
    prisma.module.findMany({
      orderBy: { sortOrder: 'asc' },
      include: { _count: { select: { screenshots: true } } },
    }),
    prisma.translation.findMany({
      where: { entityType: 'module', field: 'name', languageCode: DEFAULT_LOCALE },
      select: { entityId: true, value: true },
    }),
  ]);
  const nameById = new Map(names.map((n) => [n.entityId, n.value]));

  return (
    <ModulesTable
      modules={modules.map((m) => ({
        id: m.id,
        key: m.key,
        slug: m.slug,
        name: nameById.get(m.id) ?? m.key,
        group: m.moduleGroup ?? '—',
        repo: m.repo,
        isNew: m.isNew,
        isActive: m.isActive,
        screenshotCount: m._count.screenshots,
        updatedAt: formatDate(m.updatedAt),
      }))}
    />
  );
}
