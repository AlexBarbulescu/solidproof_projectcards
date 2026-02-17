import { defineConfig } from 'vite';
import path from 'node:path';
import fs from 'node:fs';

const phpOrigin = process.env.PHP_ORIGIN || 'http://127.0.0.1:8000';

function getAppVersion() {
  // Prefer explicit version override (CI/build systems can set this).
  const fromEnv = process.env.VITE_APP_VERSION || '';
  if (fromEnv) return String(fromEnv).trim();

  // Default: use frontend/package.json version (stable across local + Cloudflare builds).
  try {
    const pkgPath = path.resolve(__dirname, 'package.json');
    const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf8'));
    return String(pkg?.version || '').trim();
  } catch {
    return '';
  }
}

function digitsOnly(value) {
  const s = String(value ?? '').trim();
  if (!s) return '';
  const m = s.match(/\d+/g);
  return m ? m.join('') : '';
}

function getBuildNumber() {
  // Prefer CI-provided numeric build/run identifiers.
  const candidates = [
    process.env.CF_PAGES_BUILD_ID,
    process.env.CF_PAGES_DEPLOYMENT_ID,
    process.env.GITHUB_RUN_NUMBER,
    process.env.GITHUB_RUN_ID,
    process.env.CI_PIPELINE_IID,
    process.env.CI_PIPELINE_ID,
    process.env.BUILD_NUMBER,
    process.env.RUN_NUMBER,
  ];

  for (const c of candidates) {
    const d = digitsOnly(c);
    if (d) return d;
  }

  // Local fallback: timestamp-based build number (changes on every build).
  const now = new Date();
  const yyyy = String(now.getUTCFullYear());
  const mm = String(now.getUTCMonth() + 1).padStart(2, '0');
  const dd = String(now.getUTCDate()).padStart(2, '0');
  const hh = String(now.getUTCHours()).padStart(2, '0');
  const mi = String(now.getUTCMinutes()).padStart(2, '0');
  return `${yyyy}${mm}${dd}${hh}${mi}`;
}

function toFourPartVersion(baseVersion, buildNumber) {
  // Windows-style: major.minor.patch.build
  // If baseVersion is missing/odd, fall back to 0.0.0.
  const parts = String(baseVersion || '').trim().split('.').map((p) => digitsOnly(p)).filter(Boolean);
  const major = parts[0] || '0';
  const minor = parts[1] || '0';
  const patch = parts[2] || '0';
  const build = digitsOnly(buildNumber) || '0';
  return `${major}.${minor}.${patch}.${build}`;
}

export default defineConfig(({ command, mode }) => {
  const isBuild = command === 'build';
  const isPages = mode === 'pages' || process.env.CF_PAGES === '1';
  const appVersion = getAppVersion();
  const buildNumber = getBuildNumber();
  const version4 = toFourPartVersion(appVersion, buildNumber);

  return {
    root: __dirname,
    base: isBuild ? (isPages ? '/' : '/dist/') : '/',
    appType: 'spa',
    define: {
      __SPCARD_VERSION__: JSON.stringify(appVersion),
      __SPCARD_BUILD_NUMBER__: JSON.stringify(buildNumber),
      __SPCARD_VERSION4__: JSON.stringify(version4),
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
