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

## v4 image output

The endpoint below renders a real JPEG using Intervention Image (GD driver):

- `http://127.0.0.1:8000/preview.php?project=<slug>`
- `http://127.0.0.1:8000/certificate-intervention.php?project=<slug>&type=audit_v4`
- `http://127.0.0.1:8000/certificate-intervention.php?project=<slug>&type=kyc_v4`

## QR Generator https://goqr.me/