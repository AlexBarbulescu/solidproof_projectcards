import { spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import fsSync from 'node:fs';
import path from 'node:path';

const frontendDir = path.resolve(process.cwd());
const repoRoot = path.resolve(frontendDir, '..');
const outDir = path.resolve(repoRoot, 'cf-pages');

function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, {
    stdio: 'inherit',
    shell: process.platform === 'win32',
    env: {
      ...process.env,
      CF_PAGES: '1',
    },
    ...opts,
  });
  if (r.status !== 0) process.exit(r.status ?? 1);
}

async function copyDir(src, dst) {
  if (!fsSync.existsSync(src)) return;
  await fs.mkdir(dst, { recursive: true });
  const entries = await fs.readdir(src, { withFileTypes: true });
  for (const e of entries) {
    const s = path.join(src, e.name);
    const d = path.join(dst, e.name);
    if (e.isDirectory()) await copyDir(s, d);
    else if (e.isFile()) await fs.copyFile(s, d);
  }
}

async function main() {
  // 1) Build Vite app into /cf-pages
  run('npx', ['vite', 'build', '--mode', 'pages'], { cwd: frontendDir });

  // 2) Copy shared static assets needed by the CSS
  const publicDir = path.resolve(repoRoot, 'public');
  await copyDir(path.join(publicDir, 'img'), path.join(outDir, 'img'));
  await copyDir(path.join(publicDir, 'fonts'), path.join(outDir, 'fonts'));
  await copyDir(path.join(publicDir, 'certificates'), path.join(outDir, 'certificates'));

  // Keep CSS path parity: /assets/style.css
  await fs.mkdir(path.join(outDir, 'assets'), { recursive: true });
  const styleSrc = path.join(publicDir, 'assets', 'style.css');
  if (fsSync.existsSync(styleSrc)) {
    await fs.copyFile(styleSrc, path.join(outDir, 'assets', 'style.css'));
  }

  // 3) Optionally publish projects.json as a static API
  //    (You can set VITE_PROJECTS_URL=/api/projects.json in Cloudflare Pages env vars.)
  const projectsSrc = path.join(repoRoot, 'data', 'projects.json');
  if (fsSync.existsSync(projectsSrc)) {
    await fs.mkdir(path.join(outDir, 'api'), { recursive: true });
    await fs.copyFile(projectsSrc, path.join(outDir, 'api', 'projects.json'));
  }

  // 4) SPA fallback for Cloudflare Pages
  const redirects = '/* /index.html 200\n';
  await fs.writeFile(path.join(outDir, '_redirects'), redirects, 'utf8');
}

main().catch((e) => {
  // eslint-disable-next-line no-console
  console.error(e);
  process.exit(1);
});
