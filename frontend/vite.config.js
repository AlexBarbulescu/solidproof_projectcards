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

function readCounter(filePath) {
  try {
    const raw = fs.readFileSync(filePath, 'utf8').trim();
    const n = Number.parseInt(raw, 10);
    return Number.isFinite(n) && n >= 0 ? n : 0;
  } catch {
    return 0;
  }
}

function writeCounter(filePath, value) {
  try {
    fs.writeFileSync(filePath, String(value), 'utf8');
    return true;
  } catch {
    return false;
  }
}

function getBuildNumber({ isBuild }) {
  // Prefer CI-provided numeric build/run identifiers.
  const candidates = [
    process.env.VITE_BUILD_NUMBER,
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

  // Local fallback: persistent auto-incrementing build counter.
  const counterFile = path.resolve(__dirname, '.build-number');
  const current = readCounter(counterFile);

  if (isBuild) {
    const next = current + 1;
    if (writeCounter(counterFile, next)) {
      return String(next);
    }

    // Read-only filesystem fallback (some CI environments): use epoch seconds.
    return String(Math.floor(Date.now() / 1000));
  }

  return String(current);
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
  const buildNumber = getBuildNumber({ isBuild });
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
