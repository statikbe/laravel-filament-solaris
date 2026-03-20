import esbuild from 'esbuild'

const isDev = process.argv.includes('--dev')

esbuild.build({
    entryPoints: ['resources/js/components/dictation.js'],
    outdir: 'dist/components',
    bundle: true,
    platform: 'neutral',
    mainFields: ['module', 'main'],
    target: ['es2020'],
    minify: !isDev,
    keepNames: true,
    sourcemap: isDev ? 'inline' : false,
    format: 'esm',
}).catch(() => process.exit(1))