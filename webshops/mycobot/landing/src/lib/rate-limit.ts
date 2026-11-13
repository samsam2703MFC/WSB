/**
 * In-process fixed-window rate limiter for the public POST endpoints.
 *
 * Deliberately simple: it holds counters in memory, so it protects a single
 * instance only. Behind more than one Node process, move this to Redis or the
 * reverse proxy — the call sites do not change.
 */

type Bucket = { count: number; resetAt: number };

const buckets = new Map<string, Bucket>();
let lastSweep = 0;

function sweep(now: number) {
  // Cheap amortised cleanup so the map cannot grow without bound.
  if (now - lastSweep < 60_000) return;
  lastSweep = now;
  for (const [key, b] of buckets) if (b.resetAt <= now) buckets.delete(key);
}

export interface RateLimitResult {
  ok: boolean;
  remaining: number;
  /** Seconds until the window resets — goes into Retry-After. */
  retryAfter: number;
}

export function rateLimit(key: string, limit: number, windowMs: number): RateLimitResult {
  const now = Date.now();
  sweep(now);

  const existing = buckets.get(key);
  if (!existing || existing.resetAt <= now) {
    buckets.set(key, { count: 1, resetAt: now + windowMs });
    return { ok: true, remaining: limit - 1, retryAfter: 0 };
  }

  existing.count += 1;
  const retryAfter = Math.ceil((existing.resetAt - now) / 1000);
  if (existing.count > limit) return { ok: false, remaining: 0, retryAfter };
  return { ok: true, remaining: limit - existing.count, retryAfter };
}

/** Best-effort client IP from the proxy headers. */
export function clientIp(request: Request): string {
  const forwarded = request.headers.get('x-forwarded-for');
  if (forwarded) return forwarded.split(',')[0]!.trim();
  return request.headers.get('x-real-ip') || 'unknown';
}
