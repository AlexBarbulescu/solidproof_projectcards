const $mount = document.querySelector('#app');

// For static hosting (Cloudflare Pages / Workers assets), these can be configured at build time:
// - VITE_PROJECTS_URL=/api/projects.json
// - VITE_RENDER_URL=https://your-renderer.example.com
// Defaults:
// - Local (node/php): /api/projects.php
// - Static deploy (mode=pages): /api/projects.json
const DEFAULT_PROJECTS_URL = (import.meta?.env?.MODE === 'pages') ? '/api/projects.json' : '/api/projects.php';
const PROJECTS_URL = (import.meta?.env?.VITE_PROJECTS_URL) || DEFAULT_PROJECTS_URL;
const RENDER_BASE_URL = (import.meta?.env?.VITE_RENDER_URL) || '';
const IS_PAGES_BUILD = import.meta?.env?.MODE === 'pages';

function el(tag, props = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(props || {})) {
    if (value == null) continue;
    if (key === 'class') node.className = value;
    else if (key === 'text') node.textContent = String(value);
    else if (key === 'html') node.innerHTML = String(value);
    else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2).toLowerCase(), value);
    else node.setAttribute(key, String(value));
  }
  for (const child of Array.isArray(children) ? children : [children]) {
    if (child == null) continue;
    node.append(child.nodeType ? child : document.createTextNode(String(child)));
  }
  return node;
}

async function fetchJson(url) {
  const res = await fetch(url, { headers: { Accept: 'application/json' } });
  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`HTTP ${res.status} ${res.statusText}${text ? `: ${text}` : ''}`);
  }
  return res.json();
}

function joinBaseUrl(base, pathname) {
  const b = String(base || '').trim();
  if (!b) return pathname;

  // If base is a full URL, use URL resolution.
  try {
    const u = new URL(b);
    return new URL(pathname, u).toString();
  } catch {
    // Support base like "/api" or "https://example.com".
    const left = b.endsWith('/') ? b.slice(0, -1) : b;
    const right = pathname.startsWith('/') ? pathname : `/${pathname}`;
    return `${left}${right}`;
  }
}

function normalizeUrl(url) {
  url = String(url || '').trim();
  if (!url) return null;
  if (url.startsWith('http://') || url.startsWith('https://')) return url;
  return `https://${url}`;
}

function scoreClass(score) {
  const s = Number(score || 0);
  if (s >= 85) return 'score-pill good';
  if (s >= 70) return 'score-pill ok';
  if (s >= 50) return 'score-pill warn';
  return 'score-pill bad';
}

function projectKey(p) {
  return String(p?.slug || p?.uuid || p?.name || '');
}

function getSearchBlob(p) {
  const id = String(p?.id ?? '');
  const name = String(p?.name ?? p?.title ?? '');
  const slug = String(p?.slug ?? p?.uuid ?? '');
  const uuid = String(p?.uuid ?? '');
  const website = String(p?.website ?? '');
  const contracts = Array.isArray(p?.contracts) ? p.contracts : [];
  const addresses = contracts
    .map(c => String(c?.address ?? '').trim())
    .filter(Boolean);

  return [id, name, slug, uuid, website, addresses.join(' ')].join(' ').toLowerCase().trim();
}

function setView(next) {
  const url = new URL(window.location.href);
  if (next?.view) url.searchParams.set('view', next.view);
  else url.searchParams.delete('view');

  if (next?.project) url.searchParams.set('project', next.project);
  else url.searchParams.delete('project');

  if (next?.logoScale) url.searchParams.set('logoScale', next.logoScale);
  else url.searchParams.delete('logoScale');

  window.history.pushState({}, '', url);
  render();
}

function iconSvg(kind) {
  switch (kind) {
    case 'website':
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10.01 10.01 0 0 0 12 2Zm7.93 9h-3.16a15.5 15.5 0 0 0-1.18-5.02A8.03 8.03 0 0 1 19.93 11ZM12 4c.9 0 2.24 1.66 3.05 5H8.95C9.76 5.66 11.1 4 12 4ZM4.07 13h3.16a15.5 15.5 0 0 0 1.18 5.02A8.03 8.03 0 0 1 4.07 13ZM7.23 11H4.07a8.03 8.03 0 0 1 4.34-5.02A15.5 15.5 0 0 0 7.23 11Zm1.72 2h6.1c-.81 3.34-2.15 5-3.05 5s-2.24-1.66-3.05-5Zm6.82 0h3.16a8.03 8.03 0 0 1-4.34 5.02A15.5 15.5 0 0 0 15.77 13Zm-6.82-2c-.07.66-.11 1.33-.11 2s.04 1.34.11 2h6.1c.07-.66.11-1.33.11-2s-.04-1.34-.11-2Z"/></svg>';
    case 'x':
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M18.9 2H22l-6.8 7.8L23 22h-6.1l-4.8-6.2L6.7 22H3.6l7.3-8.4L1 2h6.2l4.3 5.6L18.9 2Zm-1.1 18h1.7L7.1 3.9H5.3L17.8 20Z"/></svg>';
    case 'telegram':
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M21.8 4.4c.2-.8-.6-1.5-1.4-1.2L2.9 10.2c-.9.4-.8 1.7.2 1.9l4.7 1 1.8 5.4c.3.9 1.5 1.1 2.1.4l2.6-3 4.9 3.6c.8.6 2 .1 2.2-.9l2.4-14.2ZM9.5 13.9l9.2-7.8-7.4 8.9-.3 3.2-1.8-5.3 0 0 0 0 .3-.9Z"/></svg>';
    case 'discord':
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M20 4.7A16.5 16.5 0 0 0 16.1 3l-.5 1a15 15 0 0 1 3.4 1.8A13 13 0 0 0 5 5.8 15 15 0 0 1 8.4 4l-.5-1A16.5 16.5 0 0 0 4 4.7C2.1 7.5 1.6 10.2 1.8 12.9a16.2 16.2 0 0 0 5 2.6l.7-1.1a10.4 10.4 0 0 1-1.6-.8l.4-.3a11.6 11.6 0 0 0 10.7 0l.4.3c-.5.3-1 .6-1.6.8l.7 1.1a16.2 16.2 0 0 0 5-2.6c.3-2.9-.2-5.6-1.9-8.2ZM8.5 12.4c-.8 0-1.4-.7-1.4-1.6 0-.9.6-1.6 1.4-1.6s1.4.7 1.4 1.6c0 .9-.6 1.6-1.4 1.6Zm7 0c-.8 0-1.4-.7-1.4-1.6 0-.9.6-1.6 1.4-1.6s1.4.7 1.4 1.6c0 .9-.6 1.6-1.4 1.6Z"/></svg>';
    case 'github':
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.9.6-3.5-1.2-3.5-1.2-.5-1.2-1.1-1.5-1.1-1.5-.9-.6.1-.6.1-.6 1 0 1.6 1 1.6 1 .9 1.6 2.5 1.1 3.1.8.1-.7.4-1.1.7-1.4-2.3-.3-4.7-1.1-4.7-5A3.9 3.9 0 0 1 6.7 8.1a3.6 3.6 0 0 1 .1-2.6s.8-.3 2.6 1a9 9 0 0 1 4.7 0c1.8-1.2 2.6-1 2.6-1a3.6 3.6 0 0 1 .1 2.6 3.9 3.9 0 0 1 1 2.7c0 3.9-2.4 4.7-4.7 5 .4.3.7 1 .7 2v3c0 .3.2.6.7.5A10 10 0 0 0 12 2Z"/></svg>';
    case 'medium':
      return '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M4 7.2c0-.6.2-1 .7-1.4l1.7-1.4v-.2H1.2v.2l1.4 1.7c.1.1.2.4.2.6v12.6c0 .3-.1.5-.2.6l-1.4 1.7v.2h5.2v-.2l-1.4-1.7c-.1-.1-.2-.4-.2-.6V7.2Zm6.1-3.9 4.4 9.7 3.9-9.7h4.2v.2l-1.2 1.2c-.1.1-.1.2-.1.4v14.2c0 .2 0 .3.1.4l1.2 1.2v.2h-6.1v-.2l1.2-1.2c.1-.1.1-.2.1-.4V7.9l-5 12.5h-.7L6.4 7.9v10.5c0 .3.1.7.3 1l1.6 1.9v.2H3.8v-.2l1.6-1.9c.2-.3.3-.7.3-1V6.2c0-.3-.1-.6-.3-.8L4 3.5v-.2h6.1Z"/></svg>';
    default:
      return '';
  }
}

function socialLinks(p) {
  const socials = typeof p?.socials === 'object' && p.socials ? p.socials : {};
  const website = normalizeUrl(p?.website || '');
  const twitter = normalizeUrl(socials?.twitter || '');
  const telegram = normalizeUrl(socials?.telegram || '');
  const discord = normalizeUrl(socials?.discord || '');
  const github = normalizeUrl(socials?.github || '');
  const medium = normalizeUrl(socials?.medium || '');

  const links = [];
  if (website) links.push({ kind: 'website', href: website, title: 'Website', aria: 'Website' });
  if (twitter) links.push({ kind: 'x', href: twitter, title: 'X', aria: 'X' });
  if (telegram) links.push({ kind: 'telegram', href: telegram, title: 'Telegram', aria: 'Telegram' });
  if (discord) links.push({ kind: 'discord', href: discord, title: 'Discord', aria: 'Discord' });
  if (github) links.push({ kind: 'github', href: github, title: 'GitHub', aria: 'GitHub' });
  if (medium) links.push({ kind: 'medium', href: medium, title: 'Medium', aria: 'Medium' });

  return el('div', { class: 'socials', 'aria-label': 'Links' },
    links.map(l => el('a', {
      class: 'icon-link',
      href: l.href,
      target: '_blank',
      rel: 'noreferrer',
      title: l.title,
      'aria-label': l.aria,
      html: iconSvg(l.kind)
    }))
  );
}

function buildInterventionUrl({ project, type, logoScale }) {
  const baseUrl = joinBaseUrl(RENDER_BASE_URL, '/certificate-intervention.php');
  const u = new URL(baseUrl, window.location.origin);
  if (project) u.searchParams.set('project', project);
  if (type) u.searchParams.set('type', type);
  if (logoScale) u.searchParams.set('logoScale', logoScale);
  return u.toString();
}

function renderImageBlock({ label, imgAlt, imgSrc }) {
  const help = el('p', {
    class: 'muted',
    style: 'margin: 8px 0 0 0; display:none;',
    text: IS_PAGES_BUILD && !RENDER_BASE_URL
      ? 'Images require a separate PHP renderer. Set VITE_RENDER_URL and redeploy.'
      : 'Image failed to load. Open it in a new tab to see the error text.'
  });

  const img = el('img', {
    class: 'img-preview',
    alt: imgAlt,
    src: imgSrc,
    onError: () => {
      help.style.display = '';
    }
  });

  return el('div', { style: 'flex: 1 1 520px; min-width: min(520px, 96vw);' }, [
    el('div', { class: 'muted', style: 'margin: 0 0 8px 0;', text: label }),
    img,
    help,
  ]);
}

function readParams() {
  const url = new URL(window.location.href);
  return {
    view: url.searchParams.get('view') || 'index',
    project: url.searchParams.get('project') || '',
    logoScale: url.searchParams.get('logoScale') || ''
  };
}

function loadFavs() {
  try {
    const raw = localStorage.getItem('solidproof_favs_v1');
    if (!raw) return {};
    const obj = JSON.parse(raw);
    return obj && typeof obj === 'object' ? obj : {};
  } catch {
    return {};
  }
}

function saveFavs(map) {
  try {
    localStorage.setItem('solidproof_favs_v1', JSON.stringify(map || {}));
  } catch {
    // ignore
  }
}

function renderIndex(projects) {
  const root = el('div', { class: 'page' }, [
    el('header', { class: 'topbar' }, [
      el('div', { class: 'brand', text: 'Certificate Preview' }),
      el('div', { class: 'actions' }, [
        el('a', {
          class: 'btn',
          href: '#',
          onClick: (e) => {
            e.preventDefault();
            setView({ view: 'preview' });
          },
          text: 'Open preview'
        })
      ])
    ]),
    el('main', { class: 'content' }, [
      el('div', { class: 'stack' }, [
        el('div', { class: 'card' }, [
          el('div', { class: 'card-title' }, [
            el('div', { class: 'title-row' }, [
              el('div', { text: 'Projects' }),
              el('div', { class: 'muted', id: 'resultsLabel', text: '' })
            ])
          ]),
          el('div', { class: 'card-body' }, [
            el('div', { class: 'searchbar' }, [
              el('input', { id: 'projectSearch', class: 'search-input', type: 'search', placeholder: 'Search for a project by name or address', autocomplete: 'off' }),
              el('div', { class: 'search-actions' }, [
                el('button', { class: 'btn btn-small', id: 'clearSearch', type: 'button', text: 'Clear' })
              ])
            ]),
            !projects.length
              ? el('p', { text: 'No projects loaded yet.' })
              : el('div', { class: 'table-wrap', role: 'region', 'aria-label': 'Projects table', tabIndex: '0' }, [
                  el('table', { class: 'projects-table' }, [
                    el('thead', {}, [
                      el('tr', {}, [
                        el('th', { class: 'col-fav', 'aria-label': 'Favorite' }),
                        el('th', { class: 'col-id', text: '#' }),
                        el('th', { class: 'col-name', text: 'Name' }),
                        el('th', { class: 'col-score', text: 'Security score' }),
                        el('th', { class: 'col-services', text: 'Services & certificates' }),
                        el('th', { class: 'col-ecosystems', text: 'Ecosystems' }),
                        el('th', { class: 'col-category', text: 'Category' })
                      ])
                    ]),
                    el('tbody', { id: 'projectsTbody' }, [])
                  ])
                ])
          ])
        ]),

        el('div', { class: 'card' }, [
          el('div', { class: 'card-title', text: 'How to run' }),
          el('div', { class: 'card-body' }, [
            el('ol', { class: 'list-ol' }, [
              el('li', { html: 'Fetch data: <code>php scripts/fetch_projects.php</code>' }),
              el('li', { html: 'Start server: <code>php -c config/php.ini -S 127.0.0.1:8000 -t public</code>' }),
              el('li', { html: 'Open: <code>http://127.0.0.1:8000</code>' })
            ]),
            el('p', { class: 'muted', text: 'Edits to PHP/CSS show on refresh.' })
          ])
        ])
      ])
    ])
  ]);

  const tbody = root.querySelector('#projectsTbody');
  const input = root.querySelector('#projectSearch');
  const clearBtn = root.querySelector('#clearSearch');
  const label = root.querySelector('#resultsLabel');

  const favs = loadFavs();

  function syncFavUI() {
    const rows = tbody.querySelectorAll('tr[data-slug]');
    for (const row of rows) {
      const slug = row.getAttribute('data-slug') || '';
      const on = !!favs[slug];
      const btn = row.querySelector('.fav-btn');
      const icon = row.querySelector('.fav-icon');
      if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (icon) icon.textContent = on ? '★' : '☆';
      row.classList.toggle('is-fav', on);
    }
  }

  function tokens(s) {
    s = String(s || '').toLowerCase().trim();
    if (!s) return [];
    return s.split(/\s+/g).filter(Boolean);
  }

  function applyFilter() {
    const tks = tokens(input.value);
    const rows = Array.from(tbody.querySelectorAll('tr[data-search]'));
    let visible = 0;
    for (const row of rows) {
      const blob = String(row.getAttribute('data-search') || '').toLowerCase().trim();
      let ok = true;
      for (const tk of tks) {
        if (!blob.includes(tk)) { ok = false; break; }
      }
      row.style.display = ok ? '' : 'none';
      if (ok) visible++;
    }
    if (label) label.textContent = `${visible} / ${rows.length}`;
  }

  function renderRows() {
    const sorted = [...projects].sort((a, b) => Number(b?.id || 0) - Number(a?.id || 0));
    tbody.replaceChildren(
      ...sorted.map(p => {
        const id = String(p?.id ?? '');
        const name = String(p?.name ?? p?.title ?? 'Untitled');
        const slug = String(p?.slug ?? p?.uuid ?? name);
        const scoreRaw = String(p?.score ?? '0');
        const score = Number(scoreRaw || 0);
        const logo = String(p?.full_logo_url ?? '');
        const auditBadge = String(p?.audit_badge ?? '');
        const kycBadge = String(p?.kyc_badge ?? '');
        const category = String(p?.category ?? '');
        const blockchains = Array.isArray(p?.blockchains) ? p.blockchains : [];
        const searchBlob = getSearchBlob(p);

        const row = el('tr', { class: 'project-tr', 'data-search': searchBlob, 'data-slug': slug }, [
          el('td', { class: 'col-fav' }, [
            el('button', { class: 'fav-btn', type: 'button', 'aria-label': 'Toggle favorite', title: 'Favorite' }, [
              el('span', { class: 'fav-icon', 'aria-hidden': 'true', text: '☆' })
            ])
          ]),
          el('td', { class: 'col-id' }, [el('span', { class: 'mono', text: id })]),
          el('td', { class: 'col-name' }, [
            el('div', { class: 'name-cell' }, [
              logo
                ? el('img', { class: 'project-logo', src: logo, alt: '', loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' })
                : el('div', { class: 'project-logo placeholder', 'aria-hidden': 'true' }),
              el('div', { class: 'name-meta' }, [
                el('a', {
                  class: 'project-name',
                  href: '#',
                  onClick: (e) => {
                    e.preventDefault();
                    setView({ view: 'preview', project: slug });
                  },
                  text: name
                }),
                socialLinks(p)
              ])
            ])
          ]),
          el('td', { class: 'col-score' }, [
            el('span', { class: scoreClass(score), text: Number.isFinite(score) ? score.toFixed(2) : '0.00' })
          ]),
          el('td', { class: 'col-services' }, [
            el('div', { class: 'service-badges' }, [
              auditBadge ? el('img', { class: 'service-badge', src: auditBadge, alt: 'Audit badge', loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' }) : null,
              kycBadge ? el('img', { class: 'service-badge', src: kycBadge, alt: 'KYC badge', loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' }) : null
            ])
          ]),
          el('td', { class: 'col-ecosystems' }, [
            !blockchains.length
              ? el('span', { class: 'muted', text: '—' })
              : el('div', { class: 'ecosystems', 'aria-label': 'Ecosystems' },
                  blockchains.slice(0, 4).map(bc => {
                    const bcName = String(bc?.name ?? '');
                    const bcIcon = String(bc?.icon_url ?? '');
                    return bcIcon
                      ? el('img', { class: 'ecosystem', src: bcIcon, alt: '', title: bcName, loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' })
                      : el('span', { class: 'ecosystem placeholder', title: bcName });
                  })
                )
          ]),
          el('td', { class: 'col-category' }, [
            category
              ? el('span', { class: 'category-pill', text: category })
              : el('span', { class: 'muted', text: '—' })
          ])
        ]);

        return row;
      })
    );
  }

  tbody.addEventListener('click', (e) => {
    const btn = e.target?.closest?.('.fav-btn');
    if (!btn) return;
    const row = btn.closest('tr');
    const slug = row?.getAttribute('data-slug') || '';
    if (!slug) return;
    favs[slug] = !favs[slug];
    if (!favs[slug]) delete favs[slug];
    saveFavs(favs);
    syncFavUI();
  });

  input.addEventListener('input', applyFilter);
  clearBtn?.addEventListener('click', () => {
    input.value = '';
    input.focus();
    applyFilter();
  });

  renderRows();
  syncFavUI();
  applyFilter();

  return root;
}

function renderPreview(projects, params) {
  const requested = String(params.project || '');
  const logoScale = String(params.logoScale || '').trim();

  let project = null;
  let currentIndex = null;
  if (projects.length) {
    if (requested) {
      for (let i = 0; i < projects.length; i++) {
        const p = projects[i];
        const slug = String(p?.slug ?? p?.uuid ?? '');
        const name = String(p?.name ?? p?.title ?? '');
        if (slug === requested || name === requested) {
          project = p;
          currentIndex = i;
          break;
        }
      }
    }
    if (!project) {
      project = projects[0];
      currentIndex = 0;
    }
  }

  let prevKey = null;
  let nextKey = null;
  let prevName = null;
  let nextName = null;
  if (projects.length && currentIndex != null && projects.length > 1) {
    const count = projects.length;
    const prev = projects[(currentIndex - 1 + count) % count];
    const next = projects[(currentIndex + 1) % count];
    prevKey = String(prev?.slug ?? prev?.uuid ?? prev?.name ?? prev?.title ?? '');
    nextKey = String(next?.slug ?? next?.uuid ?? next?.name ?? next?.title ?? '');
    prevName = String(prev?.name ?? prev?.title ?? 'Previous');
    nextName = String(next?.name ?? next?.title ?? 'Next');
  }

  const slug = String(project?.slug ?? '');
  const scoreFloat = Number(project?.score || 0);
  const logoUrl = String(project?.full_logo_url ?? project?.logo_url ?? project?.logo ?? '');
  const auditBadgeUrl = String(project?.audit_badge ?? '');
  const kycBadgeUrl = String(project?.kyc_badge ?? '');
  const projectId = String(project?.id ?? '');
  const projectCategory = String(project?.category ?? '');
  const projectBlockchains = Array.isArray(project?.blockchains) ? project.blockchains : [];
  const projectSocials = typeof project?.socials === 'object' && project.socials ? project.socials : {};
  const websiteHref = normalizeUrl(project?.website ?? project?.url ?? '');
  const twitterHref = normalizeUrl(projectSocials?.twitter ?? '');
  const telegramHref = normalizeUrl(projectSocials?.telegram ?? '');
  const discordHref = normalizeUrl(projectSocials?.discord ?? '');
  const githubHref = normalizeUrl(projectSocials?.github ?? '');
  const mediumHref = normalizeUrl(projectSocials?.medium ?? '');

  function linkOrNull(href, kind, title, aria) {
    if (!href) return null;
    return el('a', { class: 'icon-link', href, target: '_blank', rel: 'noreferrer', title, 'aria-label': aria, html: iconSvg(kind) });
  }

  const auditUrl = buildInterventionUrl({ project: slug, type: 'audit_v4', logoScale });
  const kycUrl = buildInterventionUrl({ project: slug, type: 'kyc_v4', logoScale });

  const root = el('div', { class: 'page' }, [
    el('header', { class: 'topbar' }, [
      el('a', {
        class: 'btn',
        href: '#',
        onClick: (e) => {
          e.preventDefault();
          setView({ view: 'index' });
        },
        text: '← Projects'
      }),
      el('div', { class: 'spacer' }),
      el('div', { class: 'muted', text: 'v4 preview' })
    ]),
    el('main', { class: 'content' }, [
      (IS_PAGES_BUILD && !RENDER_BASE_URL)
        ? el('div', { class: 'card warning', style: 'margin-bottom: 12px;' }, [
            el('div', { class: 'card-title', text: 'Renderer required' }),
            el('div', { class: 'card-body' }, [
              el('p', { text: 'This site is deployed as static assets (Workers/Pages). The Audit/KYC images are generated by PHP (GD/Intervention) and cannot run here.' }),
              el('p', { class: 'muted', text: 'Set VITE_RENDER_URL to the origin of a PHP renderer (e.g. https://renderer.example.com) and redeploy.' })
            ])
          ])
        : null,
      !project
        ? el('div', { class: 'card warning' }, [
            el('div', { class: 'card-title', text: 'No data' }),
            el('div', { class: 'card-body' }, [
              el('p', { html: 'Run <code>php scripts/fetch_projects.php</code> to download sample data.' })
            ])
          ])
        : el('section', { class: 'cert-wrap' }, [
            el('div', { style: 'width: min(1100px, 96vw);' }, [
              el('div', { class: 'row', style: 'justify-content:space-between; margin-bottom: 10px;' }, [
                el('div', { class: 'row' }, [
                  prevKey ? el('a', {
                    class: 'btn',
                    href: '#',
                    title: `Previous: ${prevName || 'Previous'}`,
                    onClick: (e) => {
                      e.preventDefault();
                      setView({ view: 'preview', project: prevKey, logoScale });
                    },
                    text: '← Prev'
                  }) : null,
                  nextKey ? el('a', {
                    class: 'btn',
                    href: '#',
                    title: `Next: ${nextName || 'Next'}`,
                    onClick: (e) => {
                      e.preventDefault();
                      setView({ view: 'preview', project: nextKey, logoScale });
                    },
                    text: 'Next →'
                  }) : null
                ].filter(Boolean)),
                el('div', { class: 'muted', text: 'Display card v4 (2000×2000)' }),
                el('div', { class: 'row' }, [
                  el('a', { class: 'btn', href: auditUrl, target: '_blank', rel: 'noreferrer', text: 'Open Audit v4' }),
                  el('a', { class: 'btn', href: kycUrl, target: '_blank', rel: 'noreferrer', text: 'Open KYC v4' })
                ])
              ]),

              el('div', { style: 'display:flex; gap: 12px; align-items:flex-start; flex-wrap:wrap;' }, [
                renderImageBlock({ label: 'Audit v4', imgAlt: 'Generated display card (audit_v4)', imgSrc: auditUrl }),
                renderImageBlock({ label: 'KYC v4', imgAlt: 'Generated display card (kyc_v4)', imgSrc: kycUrl })
              ]),

              el('div', { class: 'card', style: 'margin-top: 12px; width: 100%;' }, [
                el('div', { class: 'card-title', text: 'v4 details' }),
                el('div', { class: 'card-body' }, [
                  el('div', { class: 'table-wrap', role: 'region', 'aria-label': 'Project summary', tabIndex: '0' }, [
                    el('table', { class: 'projects-table' }, [
                      el('thead', {}, [
                        el('tr', {}, [
                          el('th', { class: 'col-id', text: '#' }),
                          el('th', { class: 'col-name', text: 'Name' }),
                          el('th', { class: 'col-score', text: 'Security score' }),
                          el('th', { class: 'col-services', text: 'Services & certificates' }),
                          el('th', { class: 'col-ecosystems', text: 'Ecosystems' }),
                          el('th', { class: 'col-category', text: 'Category' })
                        ])
                      ]),
                      el('tbody', {}, [
                        el('tr', { class: 'project-tr' }, [
                          el('td', { class: 'col-id' }, [el('span', { class: 'mono', text: projectId })]),
                          el('td', { class: 'col-name' }, [
                            el('div', { class: 'name-cell' }, [
                              logoUrl
                                ? el('img', { class: 'project-logo', src: logoUrl, alt: '', loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' })
                                : el('div', { class: 'project-logo placeholder', 'aria-hidden': 'true' }),
                              el('div', { class: 'name-meta' }, [
                                el('a', {
                                  class: 'project-name',
                                  href: '#',
                                  onClick: (e) => {
                                    e.preventDefault();
                                    setView({ view: 'preview', project: projectKey(project), logoScale });
                                  },
                                  text: String(project?.name ?? project?.title ?? 'Project')
                                }),
                                el('div', { class: 'socials', 'aria-label': 'Links' }, [
                                  linkOrNull(websiteHref, 'website', 'Website', 'Website'),
                                  linkOrNull(twitterHref, 'x', 'X', 'X'),
                                  linkOrNull(telegramHref, 'telegram', 'Telegram', 'Telegram'),
                                  linkOrNull(discordHref, 'discord', 'Discord', 'Discord'),
                                  linkOrNull(githubHref, 'github', 'GitHub', 'GitHub'),
                                  linkOrNull(mediumHref, 'medium', 'Medium', 'Medium')
                                ].filter(Boolean))
                              ])
                            ])
                          ]),
                          el('td', { class: 'col-score' }, [
                            el('span', { class: scoreClass(scoreFloat), text: Number.isFinite(scoreFloat) ? scoreFloat.toFixed(2) : '0.00' })
                          ]),
                          el('td', { class: 'col-services' }, [
                            el('div', { class: 'service-badges' }, [
                              auditBadgeUrl ? el('img', { class: 'service-badge', src: auditBadgeUrl, alt: 'Audit badge', loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' }) : null,
                              kycBadgeUrl ? el('img', { class: 'service-badge', src: kycBadgeUrl, alt: 'KYC badge', loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' }) : null
                            ])
                          ]),
                          el('td', { class: 'col-ecosystems' }, [
                            !projectBlockchains.length
                              ? el('span', { class: 'muted', text: '—' })
                              : el('div', { class: 'ecosystems', 'aria-label': 'Ecosystems' },
                                  projectBlockchains.slice(0, 4).map(bc => {
                                    const bcName = String(bc?.name ?? '');
                                    const bcIcon = String(bc?.icon_url ?? '');
                                    return bcIcon
                                      ? el('img', { class: 'ecosystem', src: bcIcon, alt: '', title: bcName, loading: 'lazy', decoding: 'async', referrerpolicy: 'no-referrer' })
                                      : el('span', { class: 'ecosystem placeholder', title: bcName });
                                  })
                                )
                          ]),
                          el('td', { class: 'col-category' }, [
                            projectCategory
                              ? el('span', { class: 'category-pill', text: projectCategory })
                              : el('span', { class: 'muted', text: '—' })
                          ])
                        ])
                      ])
                    ])
                  ])
                ])
              ])
            ])
          ])
    ])
  ]);

  return root;
}

let cachedProjects = null;
let projectsPromise = null;

async function fetchProjectsWithFallback() {
  const tried = [];
  const candidates = [
    PROJECTS_URL,
    // Static deploy artifact written by build:pages
    '/api/projects.json'
  ].filter((v, i, a) => v && a.indexOf(v) === i);

  let lastError = null;
  for (const url of candidates) {
    tried.push(url);
    try {
      const payload = await fetchJson(url);
      const list = Array.isArray(payload?.data)
        ? payload.data
        : (Array.isArray(payload) ? payload : []);
      return list;
    } catch (e) {
      lastError = e;
    }
  }

  const msg = String(lastError?.message || lastError || 'Unknown error');
  throw new Error(`${msg}\n\nTried: ${tried.join(', ')}`);
}

async function getProjects() {
  if (cachedProjects) return cachedProjects;
  if (!projectsPromise) {
    projectsPromise = fetchProjectsWithFallback().then((list) => {
      cachedProjects = list;
      return list;
    });
  }
  return projectsPromise;
}

async function render() {
  const params = readParams();
  $mount.replaceChildren(
    el('div', { class: 'page' }, [
      el('div', { class: 'card' }, [
        el('div', { class: 'card-title', text: 'Loading' }),
        el('div', { class: 'card-body' }, [el('p', { class: 'muted', text: 'Loading projects…' })])
      ])
    ])
  );

  let projects;
  try {
    projects = await getProjects();
  } catch (e) {
    $mount.replaceChildren(
      el('div', { class: 'page' }, [
        el('div', { class: 'card warning' }, [
          el('div', { class: 'card-title', text: 'Setup' }),
          el('div', { class: 'card-body' }, [
            el('p', { text: String(e?.message || e) }),
            el('p', { class: 'muted', text: 'Local dev: run php scripts/fetch_projects.php. Static deploy: ensure /api/projects.json exists or set VITE_PROJECTS_URL.' })
          ])
        ])
      ])
    );
    return;
  }

  // Keep same behavior as PHP: default project is the first if none is specified.
  if (params.view === 'preview' && !params.project && projects.length) {
    params.project = projectKey(projects[0]);
  }

  const viewNode = params.view === 'preview'
    ? renderPreview(projects, params)
    : renderIndex(projects);
  $mount.replaceChildren(viewNode);
}

window.addEventListener('popstate', () => render());

render();
