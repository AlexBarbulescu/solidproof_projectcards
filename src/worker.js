// Cloudflare Worker renderer (no PHP/GD):
// - Serves static assets from `cf-pages/` via the ASSETS binding
// - Implements `/certificate-intervention.php` by generating a self-contained SVG (2000×2000)
//
// This intentionally mirrors the PHP query shape:
//   /certificate-intervention.php?project=<slug>&type=audit_v4|kyc_v4

function send(status, body, headers = {}) {
  return new Response(body, { status, headers });
}

function isNavigationRequest(request) {
  if (request.method !== 'GET' && request.method !== 'HEAD') return false;
  const mode = (request.headers.get('Sec-Fetch-Mode') || '').toLowerCase();
  if (mode === 'navigate') return true;

  const accept = (request.headers.get('Accept') || '').toLowerCase();
  return accept.includes('text/html') || accept.includes('application/xhtml+xml');
}

function base64FromArrayBuffer(buf) {
  const bytes = new Uint8Array(buf);
  let binary = '';
  const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunk));
  }
  // btoa is available in Workers
  return btoa(binary);
}

function dataUri(contentType, arrayBuffer) {
  return `data:${contentType};base64,${base64FromArrayBuffer(arrayBuffer)}`;
}

function escapeXml(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

function pngSize(buf) {
  // PNG IHDR is at byte offset 16 (width) and 20 (height), big-endian 32-bit.
  const u8 = new Uint8Array(buf);
  if (u8.length < 24) return null;
  // PNG signature
  const sig = [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A];
  for (let i = 0; i < sig.length; i++) {
    if (u8[i] !== sig[i]) return null;
  }
  const view = new DataView(buf);
  const width = view.getUint32(16);
  const height = view.getUint32(20);
  if (!width || !height) return null;
  return { width, height };
}

function ordinal(n) {
  const v = n % 100;
  if (v >= 11 && v <= 13) return `${n}th`;
  switch (n % 10) {
    case 1: return `${n}st`;
    case 2: return `${n}nd`;
    case 3: return `${n}rd`;
    default: return `${n}th`;
  }
}

function formatOnboarded(raw) {
  try {
    const d = raw ? new Date(raw) : new Date();
    // Use UTC-ish display; this is just visual.
    const month = d.toLocaleString('en-US', { month: 'long', timeZone: 'UTC' });
    const day = d.toLocaleString('en-US', { day: 'numeric', timeZone: 'UTC' });
    const year = d.toLocaleString('en-US', { year: 'numeric', timeZone: 'UTC' });
    return `${month} ${ordinal(Number(day))}, ${year}`;
  } catch {
    return '';
  }
}

function websiteHost(url) {
  const s = String(url ?? '').trim();
  if (!s) return '';
  try {
    const u = new URL(s.startsWith('http') ? s : `https://${s}`);
    return u.host || s;
  } catch {
    return s;
  }
}

async function assetBytes(env, pathname) {
  if (!env?.ASSETS?.fetch) {
    throw new Error('Missing ASSETS binding (wrangler assets config).');
  }
  const url = new URL(pathname, 'https://assets.local');
  const res = await env.ASSETS.fetch(new Request(url.toString()));
  if (!res.ok) {
    throw new Error(`Missing asset: ${pathname} (${res.status})`);
  }
  return await res.arrayBuffer();
}

async function assetJson(env, pathname) {
  const buf = await assetBytes(env, pathname);
  const text = new TextDecoder('utf-8').decode(buf);
  return JSON.parse(text);
}

let fontCssPromise = null;
async function getFontCss(env) {
  if (fontCssPromise) return fontCssPromise;
  fontCssPromise = (async () => {
    const fonts = {
      extraBold: '/fonts/Montserrat/static/Montserrat-ExtraBold.ttf',
      medium: '/fonts/Montserrat/static/Montserrat-Medium.ttf',
      mediumItalic: '/fonts/Montserrat/static/Montserrat-MediumItalic.ttf',
    };

    const [extraBold, medium, mediumItalic] = await Promise.all([
      assetBytes(env, fonts.extraBold),
      assetBytes(env, fonts.medium),
      assetBytes(env, fonts.mediumItalic),
    ]);

    const extraBoldUri = dataUri('font/ttf', extraBold);
    const mediumUri = dataUri('font/ttf', medium);
    const mediumItalicUri = dataUri('font/ttf', mediumItalic);

    return `
@font-face { font-family: Montserrat; src: url(${extraBoldUri}) format('truetype'); font-weight: 800; font-style: normal; }
@font-face { font-family: Montserrat; src: url(${mediumUri}) format('truetype'); font-weight: 500; font-style: normal; }
@font-face { font-family: Montserrat; src: url(${mediumItalicUri}) format('truetype'); font-weight: 500; font-style: italic; }
`;
  })();

  return fontCssPromise;
}

let projectsPromise = null;
async function getProjects(env) {
  if (projectsPromise) return projectsPromise;
  projectsPromise = (async () => {
    const decoded = await assetJson(env, '/api/projects.json');
    const data = Array.isArray(decoded?.data) ? decoded.data : (Array.isArray(decoded) ? decoded : []);
    return data;
  })();
  return projectsPromise;
}

function pickAuditBackground(project) {
  const badge = String(project?.audit_badge ?? '');
  if (badge.includes('audit_unknown')) return '/certificates/Audit_blank_v4_unknown.png';
  return '/certificates/Audit_blank_v4.png';
}

function pickKycBackground(project) {
  const badge = String(project?.kyc_badge ?? '');
  if (badge.includes('kyc_gold')) return '/certificates/KYC_blank_v4_gold.png';
  if (badge.includes('kyc_unknown')) return '/certificates/KYC_blank_v4_unknown.png';
  return '/certificates/KYC_blank_v4.png';
}

function pickAuditBadges(project) {
  const badge = String(project?.audit_badge ?? '');
  if (badge.includes('audit_standard')) {
    return [
      { key: 'contract', path: '/img/badges/Contract_Audiated.png', x: -250, y: 430, h: 170 },
      { key: 'final', path: '/img/badges/Contract_Finalized.png', x: 230, y: 430, h: 170 },
    ];
  }

  if (!badge || badge.includes('audit_unknown')) {
    return [{ key: 'contract_center', path: '/img/badges/Contract_Unknown.png', x: 0, y: 430, h: 170 }];
  }

  // Any other audit_* (except audit_standard)
  return [{ key: 'contract_center', path: '/img/badges/Contract_Audiated.png', x: 0, y: 430, h: 170 }];
}

function haystackForKyc(project) {
  const fields = [
    project?.kyc_partner,
    project?.kyc_platform,
    project?.partner,
    project?.website,
    project?.url,
    project?.name,
    project?.slug,
    project?.description,
  ];
  return fields.map((v) => String(v ?? '')).join(' ').toLowerCase();
}

function pickKycBadges(project) {
  const badge = String(project?.kyc_badge ?? '');
  const hasTier = badge.includes('kyc_gold') || badge.includes('kyc_silver') || badge.includes('kyc_bronze');

  let tierPath = null;
  if (badge.includes('kyc_gold')) tierPath = '/img/badges/KYC_Gold.png';
  else if (badge.includes('kyc_silver')) tierPath = '/img/badges/KYC_Silver.png';
  else if (badge.includes('kyc_bronze')) tierPath = '/img/badges/KYC_Bronze.png';

  const hay = haystackForKyc(project);
  let statusPath = '/img/badges/KYC_Solidproof.png';
  if (!hasTier) statusPath = '/img/badges/KYC_Unknown.png';
  if (hay.includes('gempad')) statusPath = '/img/badges/KYC_Gempad.png';
  if (hay.includes('pinksale')) statusPath = '/img/badges/KYC_Pinksale.png';

  const badges = [];
  if (tierPath) {
    badges.push({ key: 'tier', path: tierPath, x: -250, y: 430, h: 170 });
    badges.push({ key: 'status', path: statusPath, x: 230, y: 430, h: 170 });
  } else {
    badges.push({ key: 'status_center', path: statusPath, x: 0, y: 430, h: 170 });
  }

  return badges;
}

async function buildCardSvg(env, { type, project }) {
  const W = 2000;
  const H = 2000;
  const CX = 1000;
  const CY = 1000;

  const title = String(project?.name ?? project?.title ?? 'Project');
  const date = formatOnboarded(project?.onboarded);
  const website = websiteHost(project?.website ?? project?.url ?? '');
  const copyright = `© ${new Date().getUTCFullYear()} SolidProof.io`;

  const bgPath = type === 'kyc_v4' ? pickKycBackground(project) : pickAuditBackground(project);
  const bgBytes = await assetBytes(env, bgPath);
  const bgUri = dataUri('image/png', bgBytes);

  // Logo
  let logoUri = '';
  const logoUrl = String(project?.full_logo_url ?? '');
  if (logoUrl) {
    try {
      const res = await fetch(logoUrl, { cf: { cacheTtl: 3600, cacheEverything: true } });
      if (res.ok) {
        const buf = await res.arrayBuffer();
        const ct = res.headers.get('content-type') || (logoUrl.toLowerCase().endsWith('.jpg') || logoUrl.toLowerCase().endsWith('.jpeg') ? 'image/jpeg' : 'image/png');
        logoUri = dataUri(ct, buf);
      }
    } catch {
      // ignore
    }
  }

  const badges = type === 'kyc_v4' ? pickKycBadges(project) : pickAuditBadges(project);

  // Preload badge bytes (and sizes for better layout)
  const badgeInfos = [];
  for (const b of badges) {
    const bytes = await assetBytes(env, b.path);
    const uri = dataUri('image/png', bytes);
    const size = pngSize(bytes) || { width: b.h, height: b.h };
    const drawH = b.h;
    const drawW = Math.max(1, Math.round(drawH * (size.width / size.height)));
    badgeInfos.push({ ...b, uri, drawW, drawH });
  }

  const fontCss = await getFontCss(env);

  const logoCenterX = CX + 0;
  const logoCenterY = CY - 210;
  const logoR = 225;
  const logoX = logoCenterX - logoR;
  const logoY = logoCenterY - logoR;

  const websiteText = website || '';

  // SVG layout: background -> logo (clipped) -> badges -> texts.
  const badgeSvg = badgeInfos.map((b, i) => {
    const centerX = CX + b.x;
    const centerY = CY + b.y;
    const x = Math.round(centerX - (b.drawW / 2));
    const y = Math.round(centerY - (b.drawH / 2));
    return `<image href="${b.uri}" x="${x}" y="${y}" width="${b.drawW}" height="${b.drawH}" opacity="1" />`;
  }).join('\n');

  const svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
  <defs>
    <style><![CDATA[
      ${fontCss}
      .t-title { font-family: Montserrat, sans-serif; font-weight: 800; font-size: 140px; fill: #ffffff; }
      .t-date { font-family: Montserrat, sans-serif; font-weight: 500; font-style: italic; font-size: 70px; fill: #ffffff; }
      .t-web { font-family: Montserrat, sans-serif; font-weight: 500; font-size: 44px; fill: #ffffff; }
      .t-copy { font-family: Montserrat, sans-serif; font-weight: 500; font-size: 26px; fill: #ffffff; }
    ]]></style>
    <clipPath id="logoClip">
      <circle cx="${logoCenterX}" cy="${logoCenterY}" r="${logoR}" />
    </clipPath>
  </defs>

  <image href="${bgUri}" x="0" y="0" width="${W}" height="${H}" />

  ${logoUri ? `<image href="${logoUri}" x="${logoX}" y="${logoY}" width="${logoR * 2}" height="${logoR * 2}" clip-path="url(#logoClip)" preserveAspectRatio="xMidYMid slice" />` : ''}

  ${badgeSvg}

  <text x="${CX}" y="1130" text-anchor="middle" dominant-baseline="middle" class="t-title">${escapeXml(title)}</text>
  <text x="1875" y="115" text-anchor="end" dominant-baseline="middle" class="t-date">${escapeXml(date)}</text>
  <text x="${CX}" y="1230" text-anchor="middle" dominant-baseline="middle" class="t-web">${escapeXml(websiteText)}</text>
  <text x="${CX}" y="1910" text-anchor="middle" dominant-baseline="middle" class="t-copy">${escapeXml(copyright)}</text>
</svg>`;

  return svg;
}

async function handleRender(request, env) {
  const url = new URL(request.url);
  const type = String(url.searchParams.get('type') || 'audit_v4');
  const requested = String(url.searchParams.get('project') || '');

  if (type !== 'audit_v4' && type !== 'kyc_v4') {
    return send(400, 'Invalid type. Supported: audit_v4, kyc_v4', { 'Content-Type': 'text/plain; charset=utf-8' });
  }

  let projects;
  try {
    projects = await getProjects(env);
  } catch (e) {
    return send(500, `Missing /api/projects.json in assets. (${String(e?.message || e)})`, { 'Content-Type': 'text/plain; charset=utf-8' });
  }

  if (!projects?.length) {
    return send(404, 'No projects loaded.', { 'Content-Type': 'text/plain; charset=utf-8' });
  }

  let project = null;
  if (requested) {
    project = projects.find((p) => String(p?.slug ?? '') === requested) || null;
  }
  project = project || projects[0];

  try {
    const svg = await buildCardSvg(env, { type, project });
    return send(200, svg, {
      'Content-Type': 'image/svg+xml; charset=utf-8',
      'Cache-Control': 'no-cache',
    });
  } catch (e) {
    return send(500, `Render error: ${String(e?.message || e)}`, { 'Content-Type': 'text/plain; charset=utf-8' });
  }
}

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    if (url.pathname === '/__spcard/version') {
      return send(200, JSON.stringify({
        name: 'spcard',
        worker: 'svg-renderer',
        compatibility_date: '2026-02-09',
      }), { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-cache' });
    }

    // Back-compat for older builds that still request the PHP API route.
    if (url.pathname === '/api/projects.php') {
      return Response.redirect(new URL('/api/projects.json', url), 302);
    }

    // Avoid noisy 500s in logs.
    if (url.pathname === '/favicon.ico') {
      return send(204, null, { 'Cache-Control': 'public, max-age=86400' });
    }

    if (url.pathname === '/certificate-intervention.php') {
      return handleRender(request, env);
    }

    if (!env?.ASSETS?.fetch) {
      return send(500, 'Missing ASSETS binding.', { 'Content-Type': 'text/plain; charset=utf-8' });
    }

    // Serve static assets first.
    // If a path is missing and it's a navigation request, fall back to `index.html`
    // (SPA routing) — but only after giving dynamic endpoints a chance above.
    const assetRes = await env.ASSETS.fetch(request);
    if (assetRes.status !== 404) return assetRes;

    if (isNavigationRequest(request)) {
      const indexUrl = new URL(request.url);
      indexUrl.pathname = '/index.html';
      indexUrl.search = '';
      return env.ASSETS.fetch(new Request(indexUrl.toString(), request));
    }

    return assetRes;
  }
};
