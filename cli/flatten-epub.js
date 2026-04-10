#!/usr/bin/env node
/**
 * This file is part of Lucimoo.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 * flatten-epub.js — Pre-renders fixed-layout EPUB pages to composite JPGs.
 *
 * Takes an EPUB with CSS-positioned text overlays (e.g. McGraw-Hill Inspire Science)
 * and produces a new EPUB where each page image contains the fully-rendered composite
 * (background + text + fonts), ready for import into Moodle via the fixed-layout importer.
 *
 * Usage:
 *   node flatten-epub.js <input.epub> [output.epub] [--quality 90] [--concurrency 4] [--scale 2]
 *
 * If output is omitted, writes to <input>-flat.epub.
 */

import { execSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import { chromium } from 'playwright';

const args = process.argv.slice(2);
const positional = args.filter(a => !a.startsWith('--'));
const inputEpub = positional[0];
const outputEpub = positional[1] || inputEpub.replace(/\.epub$/i, '-flat.epub');

if (!inputEpub) {
    console.error('Usage: flatten-epub.js <input.epub> [output.epub] [--quality N] [--concurrency N] [--scale N]');
    process.exit(1);
}

function getArg(name, defaultVal) {
    const idx = args.indexOf(name);
    return idx >= 0 && args[idx + 1] ? args[idx + 1] : defaultVal;
}

const QUALITY = parseInt(getArg('--quality', '90'), 10);
const CONCURRENCY = parseInt(getArg('--concurrency', '4'), 10);
const SCALE = parseInt(getArg('--scale', '2'), 10);

(async () => {
    console.log(`Input:  ${inputEpub}`);
    console.log(`Output: ${outputEpub}`);

    // Create temp directory for extraction
    const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'epub-flatten-'));
    console.log(`Working dir: ${tempDir}`);

    try {
        // Extract EPUB
        console.log('Extracting EPUB...');
        execSync(`unzip -q -o ${JSON.stringify(inputEpub)} -d ${JSON.stringify(tempDir)}`, { stdio: 'pipe' });

        // Find OPS directory
        const opsDir = findOpsDir(tempDir);
        if (!opsDir) {
            throw new Error('Could not find OPS/OEBPS directory');
        }

        // Find XHTML page files
        const xhtmlFiles = fs.readdirSync(opsDir)
            .filter(f => /^page\d+\.xhtml$/i.test(f))
            .sort();

        if (xhtmlFiles.length === 0) {
            throw new Error('No page*.xhtml files found');
        }

        // Check if this EPUB actually needs rendering (has CSS text overlays)
        const sampleContent = fs.readFileSync(path.join(opsDir, xhtmlFiles[Math.min(5, xhtmlFiles.length - 1)]), 'utf8');
        const needsRendering = sampleContent.includes('id="bodyimage"') &&
            sampleContent.includes('id="PageContainer"') &&
            /class="[^"]*psa[^"]*"/.test(sampleContent);

        if (!needsRendering) {
            console.log('This EPUB does not appear to use CSS text overlays. Copying as-is.');
            fs.copyFileSync(inputEpub, outputEpub);
            process.exit(0);
        }

        // Get viewport dimensions from first page
        const firstContent = fs.readFileSync(path.join(opsDir, xhtmlFiles[0]), 'utf8');
        const viewportMatch = firstContent.match(/name="viewport"\s+content="width=(\d+),\s*height=(\d+)"/);
        const pageWidth = viewportMatch ? parseInt(viewportMatch[1], 10) : 612;
        const pageHeight = viewportMatch ? parseInt(viewportMatch[2], 10) : 783;

        console.log(`Rendering ${xhtmlFiles.length} pages at ${pageWidth * SCALE}x${pageHeight * SCALE} (quality=${QUALITY})`);

        const imagesDir = path.join(opsDir, 'images');
        if (!fs.existsSync(imagesDir)) {
            fs.mkdirSync(imagesDir, { recursive: true });
        }

        // Render all pages
        const browser = await chromium.launch();
        const startTime = Date.now();
        let rendered = 0;

        for (let i = 0; i < xhtmlFiles.length; i += CONCURRENCY) {
            const batch = xhtmlFiles.slice(i, i + CONCURRENCY);
            await Promise.all(batch.map(async (xhtmlFile) => {
                const pageName = path.basename(xhtmlFile, '.xhtml');
                const outputPath = path.join(imagesDir, pageName + '.jpg');
                const xhtmlPath = path.join(opsDir, xhtmlFile);

                const context = await browser.newContext({
                    viewport: { width: pageWidth, height: pageHeight },
                    deviceScaleFactor: SCALE,
                });
                const page = await context.newPage();

                await page.goto(`file://${xhtmlPath}`, {
                    waitUntil: 'networkidle',
                    timeout: 15000,
                });
                await page.waitForTimeout(100);

                await page.screenshot({
                    path: outputPath,
                    type: 'jpeg',
                    quality: QUALITY,
                    fullPage: false,
                });

                await context.close();
                rendered++;

                if (rendered % 20 === 0 || rendered === xhtmlFiles.length) {
                    const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
                    console.log(`  ${rendered}/${xhtmlFiles.length} pages (${elapsed}s)`);
                }
            }));
        }

        await browser.close();
        const renderTime = ((Date.now() - startTime) / 1000).toFixed(1);
        console.log(`Rendered ${rendered} pages in ${renderTime}s`);

        // Repack as EPUB (zip with mimetype first, uncompressed)
        console.log('Repacking EPUB...');
        if (fs.existsSync(outputEpub)) {
            fs.unlinkSync(outputEpub);
        }

        const absOutput = path.resolve(outputEpub);
        
        // Add mimetype first (uncompressed, per EPUB spec)
        const mimetypePath = path.join(tempDir, 'mimetype');
        if (!fs.existsSync(mimetypePath)) {
            fs.writeFileSync(mimetypePath, 'application/epub+zip');
        }

        execSync(`cd ${JSON.stringify(tempDir)} && zip -0 -X ${JSON.stringify(absOutput)} mimetype`, { stdio: 'pipe' });
        execSync(`cd ${JSON.stringify(tempDir)} && zip -r -9 ${JSON.stringify(absOutput)} . -x mimetype`, { stdio: 'pipe' });

        const outputSize = (fs.statSync(outputEpub).size / 1048576).toFixed(1);
        const inputSize = (fs.statSync(inputEpub).size / 1048576).toFixed(1);
        console.log(`\nDone! ${inputSize} MB → ${outputSize} MB`);
        console.log(`Output: ${outputEpub}`);

    } finally {
        // Cleanup
        execSync(`rm -rf ${JSON.stringify(tempDir)}`, { stdio: 'pipe' });
    }
})();

function findOpsDir(baseDir) {
    for (const candidate of ['OPS', 'OEBPS', 'ops', 'oebps']) {
        const p = path.join(baseDir, candidate);
        if (fs.existsSync(p) && fs.statSync(p).isDirectory()) {
            return p;
        }
    }
    const files = fs.readdirSync(baseDir);
    if (files.some(f => /^page\d+\.xhtml$/i.test(f))) {
        return baseDir;
    }
    return null;
}
