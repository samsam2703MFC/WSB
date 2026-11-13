import { NextResponse } from 'next/server';
import { getLanguages } from '@/lib/landing/queries';

/** GET /api/languages — active tfb_languages rows with the RTL flag (§3.1). */
export async function GET() {
  try {
    const languages = await getLanguages();
    return NextResponse.json({ languages }, {
      headers: { 'Cache-Control': 'public, s-maxage=300, stale-while-revalidate=3600' },
    });
  } catch (error) {
    console.error('GET /api/languages failed', error);
    return NextResponse.json({ error: 'Langues indisponibles.' }, { status: 503 });
  }
}
