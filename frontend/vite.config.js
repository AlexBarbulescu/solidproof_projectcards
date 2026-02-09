import { defineConfig } from 'vite';
import path from 'node:path';

const phpOrigin = process.env.PHP_ORIGIN || 'http://127.0.0.1:8000';

export default defineConfig(({ command }) => {
  const isBuild = command === 'build';

  return {
    root: __dirname,
    base: isBuild ? '/dist/' : '/',
    appType: 'spa',
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
      outDir: path.resolve(__dirname, '../public/dist'),
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
