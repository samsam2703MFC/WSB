import { randomBytes, scrypt, timingSafeEqual } from 'node:crypto';
import { promisify } from 'node:util';

const scryptAsync = promisify(scrypt) as (
  password: string,
  salt: Buffer,
  keylen: number,
) => Promise<Buffer>;

/**
 * Password hashing for tfb_admin_users.password_hash.
 *
 * scrypt from node:crypto — no native dependency, and the parameters are stored in
 * the hash so they can be raised later without invalidating existing rows.
 * Format: scrypt$<N>$<saltHex>$<keyHex>
 */
const KEY_LENGTH = 64;

export async function hashPassword(password: string): Promise<string> {
  const salt = randomBytes(16);
  const key = await scryptAsync(password, salt, KEY_LENGTH);
  return `scrypt$${KEY_LENGTH}$${salt.toString('hex')}$${key.toString('hex')}`;
}

export async function verifyPassword(password: string, stored: string): Promise<boolean> {
  const parts = stored.split('$');
  if (parts.length !== 4 || parts[0] !== 'scrypt') return false;
  const keylen = Number(parts[1]);
  if (!Number.isInteger(keylen) || keylen <= 0 || keylen > 512) return false;

  const salt = Buffer.from(parts[2], 'hex');
  const expected = Buffer.from(parts[3], 'hex');
  if (expected.length !== keylen) return false;

  const actual = await scryptAsync(password, salt, keylen);
  return timingSafeEqual(actual, expected);
}
