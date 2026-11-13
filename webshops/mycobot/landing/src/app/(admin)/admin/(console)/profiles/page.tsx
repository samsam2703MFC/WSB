import { redirect } from 'next/navigation';

/** Moved: a client's configuration is now a tab of their own page. */
export default function ProfilesRedirect() {
  redirect('/admin/clients');
}
