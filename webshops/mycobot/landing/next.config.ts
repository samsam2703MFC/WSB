import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  reactStrictMode: true,
  // Module screenshots and brand logos are written to STORAGE_PATH by the upload
  // endpoint and served back through /api/storage/[...path] — see src/lib/storage.ts.
  eslint: { ignoreDuringBuilds: false },
};

export default nextConfig;
