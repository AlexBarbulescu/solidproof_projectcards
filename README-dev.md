# Local PHP preview server (v4-only)

This folder contains a small standalone PHP preview server in `public/` to preview/render the v4 display cards (audit_v4 + kyc_v4).

## 1) Download sample project data

From the workspace root:

- `php scripts/fetch_projects.php`

This writes `data/projects.json`.

## 2) Start the local server

- `php -c config/php.ini -S 127.0.0.1:8000 -t public`

Open:

- `http://127.0.0.1:8000`

## 3) Iterate on design

- Edit `public/assets/style.css`
- Refresh the browser

## Vite app (optional modern UI)

This repo keeps the PHP/GD rendering as-is (see `public/certificate-intervention.php` + `src/CertificateController.php`).
Vite is only used for a nicer UI that calls the existing render endpoint.

### Option A: With a PHP web server (proxy mode)

This is the classic setup: Vite proxies API + render requests to `php -S ...`.

#### 1) Start the PHP server

- `./start-server.cmd`

This serves `public/` at `http://127.0.0.1:8000/`.

#### 2) Start Vite

From `frontend/`:

- `npm install`
- `npm run dev:vite`

Open either:

- `http://localhost:5173/` (Vite dev server; proxies `/api/*` + `/certificate-intervention.php` to PHP)
- `http://127.0.0.1:8000/app.php` (PHP page that loads Vite assets)

#### 3) Production build

From `frontend/`:

- `npm run build`

This writes built assets to `public/dist/` and `public/app.php` will automatically load them.

## Vite with no PHP web server (PHP CLI renderer)

If you don’t want to run `php -S ...`, you can run a single Node server that:

- Serves the Vite UI
- Serves `/assets/style.css` and other static files from `public/`
- Handles `/api/projects.php` by reading `data/projects.json`
- Handles `/certificate-intervention.php` by calling `php` via CLI (GD/Intervention stays unchanged)

From `frontend/`:

- Dev (HMR + PHP CLI rendering): `npm run dev`
- Prod-style (serve build + PHP CLI rendering): `npm run build` then `npm run serve` (or `npm run preview`)

Windows shortcut:

- `./start-vite-solo.cmd`

Open `http://127.0.0.1:5173/` (dev) or `http://127.0.0.1:4173/` (prod-style).

## Deploy to Cloudflare Pages

Cloudflare Pages is **static hosting** (plus Worker-based Functions). It cannot run PHP/GD/Intervention, and it cannot spawn `php` via CLI.

What you *can* do:

- Host the Vite UI on Pages.
- Host `projects.json` as a static file on Pages.
- Point the UI at a separate render backend that serves `/certificate-intervention.php` (your existing PHP renderer) from somewhere else.

### 1) Build the static site

From `frontend/`:

- `npm install`
- `npm run build:pages`

This produces a deployable folder at `cf-pages/` containing:

- `index.html` + Vite assets
- `/assets/style.css` copied from `public/assets/style.css`
- `/img`, `/fonts`, `/certificates` copied from `public/`
- `/api/projects.json` copied from `data/projects.json` (if present)
- (optional) `_redirects` for SPA routing (Cloudflare Pages only)

To generate `_redirects` during the build, set `CF_PAGES_REDIRECTS=1` in the build environment.

### 2) Cloudflare Pages settings

In Cloudflare Pages:

- Framework preset: `Vite` (or `None`)
- Build command: `cd frontend && npm ci && npm run build:pages`
- Build output directory: `cf-pages`

### 3) Configure API + renderer URLs

In Cloudflare Pages → Settings → Environment variables, set:

- `VITE_PROJECTS_URL` to `/api/projects.json` (static file deployed by `build:pages`)
- `VITE_RENDER_URL` to your renderer origin, e.g. `https://renderer.example.com`

Your renderer origin must provide the existing endpoint:

- `GET /certificate-intervention.php?project=<slug>&type=audit_v4`
- `GET /certificate-intervention.php?project=<slug>&type=kyc_v4`

## v4 image output

The endpoint below renders a real JPEG using Intervention Image (GD driver):

- `http://127.0.0.1:8000/preview.php?project=<slug>`
- `http://127.0.0.1:8000/certificate-intervention.php?project=<slug>&type=audit_v4`
- `http://127.0.0.1:8000/certificate-intervention.php?project=<slug>&type=kyc_v4`

## QR Generator https://goqr.me/