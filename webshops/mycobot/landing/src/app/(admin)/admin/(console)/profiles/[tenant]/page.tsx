import { redirect } from 'next/navigation';

/** Moved. Kept so links shared before the move still land in the right place. */
export default async function ProfileRedirect({ params }: { params: Promise<{ tenant: string }> }) {
  redirect(`/admin/clients/${(await params).tenant}?tab=personnalisation`);
}
