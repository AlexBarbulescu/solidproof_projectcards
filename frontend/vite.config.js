import { defineConfig } from 'vite';
import path from 'node:path';
import { execSync } from 'node:child_process';

const phpOrigin = process.env.PHP_ORIGIN || 'http://127.0.0.1:8000';

function getBuildVersion() {
  const fromEnv =
    process.env.CF_PAGES_COMMIT_SHA ||
    process.env.GITHUB_SHA ||
    process.env.COMMIT_SHA ||
    process.env.VITE_APP_VERSION ||
    '';

  if (fromEnv) return String(fromEnv).slice(0, 12);

  try {
    const repoRoot = path.resolve(__dirname, '..');
    const sha = execSync('git rev-parse --short=12 HEAD', { cwd: repoRoot, stdio: ['ignore', 'pipe', 'ignore'] })
      .toString('utf8')
      .trim();
    return sha;
  } catch {
    return '';
  }
}

export default defineConfig(({ command, mode }) => {
  const isBuild = command === 'build';
  const isPages = mode === 'pages' || process.env.CF_PAGES === '1';
  const buildVersion = getBuildVersion();

  return {
    root: __dirname,
    base: isBuild ? (isPages ? '/' : '/dist/') : '/',
    appType: 'spa',
    define: {
      __SPCARD_BUILD__: JSON.stringify(buildVersion),
    },
    server: {
      port: 5173,
      strictPort: true,
      proxy: {
        '/api': phpOrigin,
        '/certificate-intervention.php': phpOrigin,
        '/preview.php': phpOrigin,
        '/assets': phpOrigin,
        '/img': phpOrigin,
        '/fonts': phpOrigin
      }
    },
    build: {
      outDir: isPages
        ? path.resolve(__dirname, '../cf-pages')
        : path.resolve(__dirname, '../public/dist'),
      emptyOutDir: true,
      manifest: true,
      rollupOptions: {
        input: {
          app: path.resolve(__dirname, 'index.html')
        }
      }
    }
  };
});
