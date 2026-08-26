#!/usr/bin/env node
import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';

const argumentsMap = Object.fromEntries(process.argv.slice(2).map((argument) => {
  const match = argument.match(/^--([^=]+)=(.*)$/s);
  return match ? [match[1], match[2]] : [argument.replace(/^--/, ''), true];
}));
const input = path.resolve(String(argumentsMap.input || ''));
const outputDirectory = path.resolve(String(argumentsMap['output-dir'] || 'output/ai-daily-presentation-qa/html-rendered'));

assert.ok(input.toLowerCase().endsWith('.html'), '--input must point to an HTML artifact');
assert.ok(existsSync(input), `HTML artifact does not exist: ${input}`);
const source = readFileSync(input, 'utf8');
assert.match(source, /Content-Security-Policy/);
assert.match(source, /default-src 'none'/);
assert.doesNotMatch(source, /<(?:img|script|link|iframe)[^>]+(?:src|href)=["']https?:/i);
assert.doesNotMatch(source, /url\(\s*["']?https?:/i);

mkdirSync(outputDirectory, { recursive: true });
let browser;
try {
  try {
    browser = await chromium.launch({ channel: 'chrome', headless: true });
  } catch {
    browser = await chromium.launch({ headless: true });
  }
  const context = await browser.newContext({
    offline: true,
    viewport: { width: 1600, height: 900 },
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();
  const externalRequests = [];
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error?.message || error)));
  await page.route('**/*', async (route) => {
    const url = route.request().url();
    if (url.startsWith('file:')) {
      await route.continue();
      return;
    }
    externalRequests.push(url.replace(/[?#].*$/, ''));
    await route.abort('blockedbyclient');
  });

  await page.goto(pathToFileURL(input).href, { waitUntil: 'load' });
  const runtime = await page.evaluate(() => {
    const slides = [...document.querySelectorAll('.slide')];
    const overflow = slides.flatMap((slide, index) => {
      const inner = slide.querySelector('.slide-inner');
      if (!inner) return [`slide_${index + 1}_inner_missing`];
      const failures = [];
      if (inner.scrollWidth > inner.clientWidth + 1) failures.push(`slide_${index + 1}_horizontal_overflow`);
      if (inner.scrollHeight > inner.clientHeight + 1) failures.push(`slide_${index + 1}_vertical_overflow`);
      return failures;
    });
    return {
      slideCount: slides.length,
      position: document.getElementById('position')?.textContent || '',
      overflow,
      fingerprint: document.querySelector('meta[name="suxios-spec-fingerprint"]')?.content || '',
    };
  });

  assert.ok(runtime.slideCount > 0, 'HTML deck has no slides');
  assert.equal(runtime.position, `01/${String(runtime.slideCount).padStart(2, '0')}`);
  assert.match(runtime.fingerprint, /^[a-f0-9]{64}$/);
  assert.deepEqual(runtime.overflow, []);
  assert.deepEqual(externalRequests, []);
  assert.deepEqual(pageErrors, []);

  await page.keyboard.press('ArrowRight');
  await page.waitForFunction(() => document.getElementById('position')?.textContent?.startsWith('02/'));
  await page.evaluate(() => {
    const deck = document.querySelector('.deck');
    if (deck instanceof HTMLElement) deck.style.scrollBehavior = 'auto';
  });
  for (let index = 0; index < runtime.slideCount; index += 1) {
    await page.evaluate((slideIndex) => {
      const deck = document.querySelector('.deck');
      if (deck instanceof HTMLElement) deck.scrollLeft = slideIndex * window.innerWidth;
    }, index);
    await page.waitForFunction(
      slideIndex => Math.abs((document.querySelector('.deck')?.scrollLeft || 0) - (slideIndex * window.innerWidth)) <= 2,
      index,
    );
    await page.waitForTimeout(30);
    await page.screenshot({
      path: path.join(outputDirectory, `slide-${String(index + 1).padStart(2, '0')}.png`),
      fullPage: false,
    });
  }

  console.log(JSON.stringify({
    status: 'pass',
    input,
    output_directory: outputDirectory,
    slide_count: runtime.slideCount,
    spec_fingerprint: runtime.fingerprint,
    external_request_count: externalRequests.length,
    page_error_count: pageErrors.length,
    overflow_count: runtime.overflow.length,
    keyboard_navigation: 'pass',
    network_mode: 'offline_and_non_file_requests_aborted',
  }, null, 2));
} finally {
  await browser?.close();
}
