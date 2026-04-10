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
 * render-pages.js — Pre-renders fixed-layout EPUB XHTML pages to composite JPGs via Playwright.
 *
 * Usage:
 *   node render-pages.js <extracted-epub-dir> [--quality 90] [--concurrency 4] [--scale 2]
 *
 * Expects the extracted EPUB directory with OPS/ (or similar) containing XHTML + CSS + fonts + images.
 * Renders each page*.xhtml to a JPG, replacing the corresponding images/page*.jpg.
 *
 * Called by the Moodle fixed_layout_importer when CSS-text overlay pages are detected.
 */

import fs from 'node:fs';
import path from 'node:path';

import { chromium } from 'playwright';

const args = process.argv.slice(2);
const epubDir = args[0];
if (!epubDir) {
    console.error('Usage: render-pages.js <extracted-epub-dir> [--quality N] [--concurrency N] [--scale N]');
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
    // Find all XHTML page files
    const opsDir = findOpsDir(epubDir);
    if (!opsDir) {
        console.error('Could not find OPS/OEBPS directory in', epubDir);
        process.exit(1);
    }

    const xhtmlFiles = fs.readdirSync(opsDir)
        .filter(f => /^page\d+\.xhtml$/i.test(f))
        .sort();

    if (xhtmlFiles.length === 0) {
        console.error('No page*.xhtml files found in', opsDir);
        process.exit(1);
    }

    console.log(`Rendering ${xhtmlFiles.length} pages (quality=${QUALITY}, concurrency=${CONCURRENCY}, scale=${SCALE}x)`);

    // Read viewport from first page to get dimensions
    const firstContent = fs.readFileSync(path.join(opsDir, xhtmlFiles[0]), 'utf8');
    const viewportMatch = firstContent.match(/name="viewport"\s+content="width=(\d+),\s*height=(\d+)"/);
    const pageWidth = viewportMatch ? parseInt(viewportMatch[1], 10) : 612;
    const pageHeight = viewportMatch ? parseInt(viewportMatch[2], 10) : 783;

    console.log(`Page dimensions: ${pageWidth}x${pageHeight}, render at ${pageWidth * SCALE}x${pageHeight * SCALE}`);

    const imagesDir = path.join(opsDir, 'images');
    if (!fs.existsSync(imagesDir)) {
        fs.mkdirSync(imagesDir, { recursive: true });
    }

    const browser = await chromium.launch();
    const startTime = Date.now();
    let rendered = 0;
    let errors = 0;

    // Process in batches
    for (let i = 0; i < xhtmlFiles.length; i += CONCURRENCY) {
        const batch = xhtmlFiles.slice(i, i + CONCURRENCY);
        await Promise.all(batch.map(async (xhtmlFile) => {
            const pageName = path.basename(xhtmlFile, '.xhtml');
            const outputPath = path.join(imagesDir, pageName + '.jpg');
            const xhtmlPath = path.join(opsDir, xhtmlFile);

            try {
                const context = await browser.newContext({
                    viewport: { width: pageWidth, height: pageHeight },
                    deviceScaleFactor: SCALE,
                });
                const page = await context.newPage();

                await page.goto(`file://${xhtmlPath}`, {
                    waitUntil: 'networkidle',
                    timeout: 15000,
                });

                // Small delay for font rendering
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
                    const rate = (rendered / (Date.now() - startTime) * 1000).toFixed(1);
                    console.log(`  ${rendered}/${xhtmlFiles.length} pages (${elapsed}s, ${rate} pages/sec)`);
                }
            } catch (err) {
                console.error(`  ERROR rendering ${xhtmlFile}: ${err.message}`);
                errors++;
            }
        }));
    }

    await browser.close();

    const totalTime = ((Date.now() - startTime) / 1000).toFixed(1);
    console.log(`Done: ${rendered} rendered, ${errors} errors, ${totalTime}s total`);

    // Output JSON summary for the PHP caller
    const summary = {
        rendered,
        errors,
        totalSeconds: parseFloat(totalTime),
        pageWidth: pageWidth * SCALE,
        pageHeight: pageHeight * SCALE,
        quality: QUALITY,
    };
    console.log('RENDER_SUMMARY:' + JSON.stringify(summary));

    process.exit(errors > 0 ? 1 : 0);
})();

function findOpsDir(baseDir) {
    for (const candidate of ['OPS', 'OEBPS', 'ops', 'oebps']) {
        const p = path.join(baseDir, candidate);
        if (fs.existsSync(p) && fs.statSync(p).isDirectory()) {
            return p;
        }
    }
    // Maybe the XHTML files are directly in the base dir
    const files = fs.readdirSync(baseDir);
    if (files.some(f => /^page\d+\.xhtml$/i.test(f))) {
        return baseDir;
    }
    return null;
}
