import http from 'node:http';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import fsSync from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const repoRoot = path.resolve(__dirname, '..');
const publicDir = path.join(repoRoot, 'public');
const distDir = path.join(publicDir, 'dist');
const assetsDir = path.join(publicDir, 'assets');
const scriptsDir = path.join(repoRoot, 'scripts');

const args = new Set(process.argv.slice(2));
const isDev = args.has('--dev') || (!args.has('--prod') && process.env.NODE_ENV !== 'production');
const port = Number(process.env.PORT || (isDev ? 5173 : 4173));
const host = process.env.HOST || '127.0.0.1';

function detectPhpExtDir() {
  try {
    const r = spawnSync('php', ['-r', 'echo PHP_BINARY;'], { encoding: 'utf8', windowsHide: true });
    if (r.status !== 0) return null;
    const phpBinary = String(r.stdout || '').trim();
    if (!phpBinary) return null;
    const extDir = path.join(path.dirname(phpBinary), 'ext');
    return fsSync.existsSync(extDir) ? extDir : null;
  } catch {
    return null;
  }
}

const phpExtDir = detectPhpExtDir();

function contentTypeFor(filePath) {
  const ext = path.extname(filePath).toLowerCase();
  switch (ext) {
    case '.html': return 'text/html; charset=utf-8';
    case '.js': return 'application/javascript; charset=utf-8';
    case '.css': return 'text/css; charset=utf-8';
    case '.json': return 'application/json; charset=utf-8';
    case '.svg': return 'image/svg+xml';
    case '.png': return 'image/png';
    case '.jpg':
    case '.jpeg': return 'image/jpeg';
    case '.gif': return 'image/gif';
    case '.webp': return 'image/webp';
    case '.woff2': return 'font/woff2';
    case '.woff': return 'font/woff';
    case '.ttf': return 'font/ttf';
    default: return 'application/octet-stream';
  }
}

function send(res, status, body, headers = {}) {
  res.writeHead(status, headers);
  res.end(body);
}

async function sendFile(res, absPath, extraHeaders = {}) {
  try {
    const stat = await fs.stat(absPath);
    if (!stat.isFile()) {
      send(res, 404, 'Not Found');
      return;
    }

    const data = await fs.readFile(absPath);
    send(res, 200, data, {
      'Content-Type': contentTypeFor(absPath),
      'Content-Length': String(data.length),
      'Cache-Control': absPath.includes(path.join(publicDir, 'dist')) ? 'public, max-age=31536000, immutable' : 'no-cache',
      ...extraHeaders,
    });
  } catch {
    send(res, 404, 'Not Found');
  }
}

function safeJoin(baseDir, urlPath) {
  const decoded = decodeURIComponent(urlPath);
  const cleaned = decoded.replace(/\\/g, '/');
  const abs = path.resolve(baseDir, '.' + cleaned);
  if (!abs.startsWith(path.resolve(baseDir))) return null;
  return abs;
}

async function handleApiProjects(req, res) {
  const projectsJsonPath = path.join(repoRoot, 'data', 'projects.json');
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-cache');

  try {
    const raw = await fs.readFile(projectsJsonPath, 'utf8');
    const decoded = JSON.parse(raw);
    const data = Array.isArray(decoded?.data) ? decoded.data : (Array.isArray(decoded) ? decoded : []);
    send(res, 200, JSON.stringify({ data }, null, 0));
  } catch (e) {
    send(res, 404, JSON.stringify({
      error: 'Missing or invalid data/projects.json. Run: php scripts/fetch_projects.php',
      detail: String(e?.message || e),
      data: [],
    }));
  }
}

function runPhpRender(queryString) {
  const cli = path.join(scriptsDir, 'render_certificate_cli.php');
  const phpIni = path.join(repoRoot, 'config', 'php.ini');
  return new Promise((resolve, reject) => {
    const phpArgs = [];
    if (phpExtDir) phpArgs.push('-d', `extension_dir=${phpExtDir}`);
    if (fsSync.existsSync(phpIni)) phpArgs.push('-c', phpIni);
    phpArgs.push(cli, queryString);

    const php = spawn('php', phpArgs, {
      cwd: repoRoot,
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    const out = [];
    const err = [];

    php.stdout.on('data', (d) => out.push(d));
    php.stderr.on('data', (d) => err.push(d));

    php.on('error', (spawnErr) => {
      reject(new Error(`Failed to run php. Is PHP installed and on PATH? (${spawnErr.message})`));
    });

    php.on('close', (code) => {
      const stdout = Buffer.concat(out);
      const stderr = Buffer.concat(err).toString('utf8').trim();
      if (code === 0) {
        resolve(stdout);
      } else {
        reject(new Error(stderr || `PHP render failed (exit ${code})`));
      }
    });
  });
}

async function handleCertificate(req, res, urlObj) {
  const queryString = urlObj.searchParams.toString();
  try {
    const jpeg = await runPhpRender(queryString);
    send(res, 200, jpeg, {
      'Content-Type': 'image/jpeg',
      'Cache-Control': 'no-cache',
    });
  } catch (e) {
    send(res, 500, String(e?.message || e), { 'Content-Type': 'text/plain; charset=utf-8' });
  }
}

async function createServer() {
  let vite = null;
  if (isDev) {
    const { createServer: createViteServer } = await import('vite');
    vite = await createViteServer({
      root: __dirname,
      server: { middlewareMode: true },
      appType: 'spa',
    });
  }

  const server = http.createServer(async (req, res) => {
    try {
      const urlObj = new URL(req.url || '/', `http://${req.headers.host || host}`);
      const pathname = urlObj.pathname;

      // API + renderer endpoints (no PHP web server needed)
      if (pathname === '/api/projects.php' || pathname === '/api/projects') {
        await handleApiProjects(req, res);
        return;
      }
      if (pathname === '/certificate-intervention.php') {
        await handleCertificate(req, res, urlObj);
        return;
      }

      // Shared static assets from public/
      if (pathname.startsWith('/assets/')) {
        const abs = safeJoin(assetsDir, pathname.substring('/assets'.length));
        if (!abs) { send(res, 403, 'Forbidden'); return; }
        await sendFile(res, abs);
        return;
      }
      if (pathname.startsWith('/img/') || pathname.startsWith('/fonts/') || pathname.startsWith('/certificates/')) {
        const abs = safeJoin(publicDir, pathname);
        if (!abs) { send(res, 403, 'Forbidden'); return; }
        await sendFile(res, abs);
        return;
      }

      if (isDev && vite) {
        // Let Vite handle everything else (HMR, module transforms, index.html)
        vite.middlewares(req, res, (err) => {
          if (err) {
            send(res, 500, String(err), { 'Content-Type': 'text/plain; charset=utf-8' });
          } else {
            // Shouldn't usually reach here.
            send(res, 404, 'Not Found');
          }
        });
        return;
      }

      // Production: serve built dist
      if (pathname.startsWith('/dist/')) {
        const abs = safeJoin(distDir, pathname.substring('/dist'.length));
        if (!abs) { send(res, 403, 'Forbidden'); return; }
        await sendFile(res, abs);
        return;
      }

      // SPA fallback to dist/index.html
      const accept = String(req.headers.accept || '');
      if (accept.includes('text/html')) {
        const indexPath = path.join(distDir, 'index.html');
        await sendFile(res, indexPath, { 'Cache-Control': 'no-cache' });
        return;
      }

      send(res, 404, 'Not Found');
    } catch (e) {
      send(res, 500, String(e?.message || e), { 'Content-Type': 'text/plain; charset=utf-8' });
    }
  });

  server.on('error', (e) => {
    // eslint-disable-next-line no-console
    console.error(e);
  });

  server.listen(port, host, () => {
    // eslint-disable-next-line no-console
    console.log(`${isDev ? 'Dev' : 'Prod'} server: http://${host}:${port}/`);
    // eslint-disable-next-line no-console
    console.log(`Renderer: /certificate-intervention.php (PHP CLI)`);
  });

  return { server, vite };
}

createServer();
