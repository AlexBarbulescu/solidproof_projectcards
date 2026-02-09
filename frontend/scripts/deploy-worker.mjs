import { spawnSync } from 'node:child_process';
import path from 'node:path';

const frontendDir = path.resolve(process.cwd());
const repoRoot = path.resolve(frontendDir, '..');
const wranglerConfig = path.resolve(repoRoot, 'wrangler.jsonc');

function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, {
    stdio: 'inherit',
    shell: process.platform === 'win32',
    ...opts,
  });
  if (r.status !== 0) process.exit(r.status ?? 1);
}

// 1) Build static site into /cf-pages
run('node', ['scripts/build-pages.mjs'], { cwd: frontendDir });

// 2) Deploy Worker + assets (must run from repo root)
run('npx', ['wrangler', 'deploy', '--config', wranglerConfig], { cwd: repoRoot });
