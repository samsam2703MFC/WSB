import { prisma } from '@/lib/prisma';
import { SectionsList } from '@/components/admin/ContentScreens';

/** tfb_sections — which sections render on the landing, and in what order. */
export default async function SectionsPage() {
  const sections = await prisma.section.findMany({ orderBy: { sortOrder: 'asc' } });
  return (
    <SectionsList
      sections={sections.map((s) => ({
        id: s.id, key: s.key, type: s.type, isActive: s.isActive, sortOrder: s.sortOrder,
      }))}
    />
  );
}
