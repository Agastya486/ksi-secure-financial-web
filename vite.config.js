import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

// Build output:
//   - src/style.css  ->  dist/output.css  (nama stabil,.diubah oleh file PHP)
//
// Entry point sengaja bukan index.html supaya Vite tidak memproses
// <link href="./dist/output.css"> di index.html (loop: menulis ke dist sendiri).
export default defineConfig({
  plugins: [
    tailwindcss(),
  ],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: 'src/style.css',
      output: {
        assetFileNames: 'output.css',
      },
    },
  },
})
