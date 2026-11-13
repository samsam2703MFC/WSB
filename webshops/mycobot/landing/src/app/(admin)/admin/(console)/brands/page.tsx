import { prisma } from '@/lib/prisma';
import { BrandsTable } from '@/components/admin/ContentScreens';

/** tfb_brands — the landing's trust strip. */
export default async function BrandsPage() {
  const brands = await prisma.brand.findMany({ orderBy: { sortOrder: 'asc' } });
  return (
    <BrandsTable
      brands={brands.map((b) => ({
        id: b.id, name: b.name, slug: b.slug, logoPath: b.logoPath, isActive: b.isActive,
      }))}
    />
  );
}
