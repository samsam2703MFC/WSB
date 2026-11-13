import { PrismaClient } from '@prisma/client';

// One client per process. Next's dev server re-evaluates modules on every edit, so
// cache it on globalThis or each reload opens a new MySQL pool.
const globalForPrisma = globalThis as unknown as { prisma?: PrismaClient };

export const prisma =
  globalForPrisma.prisma ??
  new PrismaClient({
    log: process.env.NODE_ENV === 'development' ? ['warn', 'error'] : ['error'],
  });

if (process.env.NODE_ENV !== 'production') globalForPrisma.prisma = prisma;
