import esbuild from 'esbuild';
import * as importMap from "esbuild-plugin-import-map";
import fs from 'fs';

importMap.load({
    imports: {
        'path': './src/node_polyfills/path.js'
    }
});

await fs.promises.copyFile('./src/index.html', './dist/index.html');
  
const ctx = await esbuild.context({
    entryPoints: ['src/main.js'],
    bundle: true,
    format: 'esm',
    minify: false,
    sourcemap: false,
    plugins: [importMap.plugin()],
    outfile: 'dist/main.js',
});

if (process.argv.includes('--serve')) {
    let { host, port } = await ctx.serve({
        servedir: 'dist',
    });

    console.log(`App server is running on http://localhost:${port}`);
} else {
    await ctx.build();
}