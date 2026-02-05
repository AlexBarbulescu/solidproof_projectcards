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

## v4 image output

The endpoint below renders a real JPEG using Intervention Image (GD driver):

- `http://127.0.0.1:8000/preview.php?project=<slug>`
- `http://127.0.0.1:8000/certificate-intervention.php?project=<slug>&type=audit_v4`
- `http://127.0.0.1:8000/certificate-intervention.php?project=<slug>&type=kyc_v4`

## Static Pages (Cloudflare Pages)

Cloudflare Pages cannot run PHP at request-time, but you can deploy a fully static gallery by pre-rendering the v4 cards locally.

Note: Cloudflare Pages build environments are not guaranteed to have PHP available, so the simplest workflow is to build `dist/` locally (or in GitHub Actions) and deploy the generated folder.

### Build

- Fetch data: `php scripts/fetch_projects.php`
- Build static output: `php -c config/php.ini scripts/build_static_pages.php`

This creates `dist/index.html` + the rendered images under `dist/cards/` and thumbnails under `dist/thumbs/`.

Optional flags:

- `--out=dist` (output folder)
- `--limit=50` (render first N projects)
- `--thumb=720` (thumbnail max size)

### Deploy

- Install Wrangler (once): `npm i -g wrangler`
- Deploy: `wrangler pages deploy dist`

## QR Generator https://goqr.me/