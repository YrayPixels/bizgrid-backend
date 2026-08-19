#!/usr/bin/env node
import { writeFileSync } from 'node:fs';
import puppeteer from 'puppeteer';

function readArg(name, fallback = '') {
  const index = process.argv.indexOf(name);
  if (index === -1 || index + 1 >= process.argv.length) {
    return fallback;
  }

  return process.argv[index + 1];
}

const url = readArg('--url');
const output = readArg('--output');
const width = Number.parseInt(readArg('--width', '390'), 10) || 390;
const height = Number.parseInt(readArg('--height', '720'), 10) || 720;

if (!url || !output) {
  console.error('Usage: node capture-storefront-screenshot.mjs --url <url> --output <path> [--width 390] [--height 720]');
  process.exit(1);
}

const executablePath = process.env.PUPPETEER_EXECUTABLE_PATH || undefined;

const browser = await puppeteer.launch({
  headless: true,
  executablePath,
  args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
});

try {
  const page = await browser.newPage();
  await page.setViewport({
    width,
    height,
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
  });

  await page.goto(url, {
    waitUntil: 'networkidle2',
    timeout: 90_000,
  });

  await page.waitForFunction(
    () => document.body && document.body.innerText.trim().length > 20,
    { timeout: 30_000 },
  ).catch(() => {});

  const screenshot = await page.screenshot({
    type: 'jpeg',
    quality: 85,
    fullPage: false,
  });

  writeFileSync(output, screenshot);
  process.stdout.write(output);
} finally {
  await browser.close();
}
