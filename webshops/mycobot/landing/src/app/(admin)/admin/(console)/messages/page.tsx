import { prisma } from '@/lib/prisma';
import { formatDateTime } from '@/lib/admin/queries';
import { MessagesInbox } from '@/components/admin/ContentScreens';

/** tfb_contact_messages — the landing's contact form lands here. */
export default async function MessagesPage() {
  const messages = await prisma.contactMessage.findMany({ orderBy: { createdAt: 'desc' }, take: 200 });
  return (
    <MessagesInbox
      messages={messages.map((m) => ({
        id: m.id,
        name: m.name,
        email: m.email,
        company: m.company,
        languageCode: m.languageCode,
        status: m.status,
        message: m.message,
        createdAt: formatDateTime(m.createdAt),
      }))}
    />
  );
}
