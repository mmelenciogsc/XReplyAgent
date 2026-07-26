#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const jobId = Number(process.env.XRA_JOB_ID || 0);
const targetUrl = String(process.env.XRA_TARGET_URL || '').trim();
const replyText = String(process.env.XRA_REPLY_TEXT || '').trim();
const outputDir = String(process.env.XRA_BROWSER_OUTPUT_DIR || '').trim();
const controlFile = String(process.env.XRA_CONTROL_FILE || '').trim();
const monitorCycles = Math.max(1, Number(process.env.XRA_MONITOR_CYCLES || 3));
const monitorIntervalSeconds = Math.max(1, Number(process.env.XRA_MONITOR_INTERVAL_SECONDS || 20));
const executablePath = String(process.env.XRA_CHROMIUM_PATH || '/usr/bin/chromium').trim();
const storageState = String(process.env.XRA_BROWSER_STORAGE_STATE || '').trim();
const profileDir = String(process.env.XRA_BROWSER_PROFILE_DIR || '').trim();

const selectorConfig = (() => {
  try {
    return JSON.parse(String(process.env.XRA_BROWSER_SELECTORS || '{}'));
  } catch (error) {
    return {};
  }
})();

const selectors = {
  compose: Array.isArray(selectorConfig.compose) ? selectorConfig.compose : [
    '[data-testid="tweetTextarea_0"]',
    '[data-testid="tweetTextarea_0"] div[contenteditable="true"]',
    'div[role="textbox"][contenteditable="true"]',
    'textarea',
  ],
  publish: Array.isArray(selectorConfig.publish) ? selectorConfig.publish : [
    '[data-testid="tweetButton"]',
    '[data-testid="tweetButtonInline"]',
    'button[type="submit"]',
    'button[aria-label*="Post"]',
  ],
  reply: Array.isArray(selectorConfig.reply) ? selectorConfig.reply : [
    '[data-testid="tweetTextarea_0"]',
    'div[role="textbox"][contenteditable="true"]',
    'textarea',
  ],
};

function ensureOutputDir() {
  if (!outputDir) {
    return;
  }

  fs.mkdirSync(outputDir, { recursive: true });
}

function readControl() {
  if (!controlFile || !fs.existsSync(controlFile)) {
    return { status: 'running' };
  }

  try {
    return JSON.parse(fs.readFileSync(controlFile, 'utf8'));
  } catch (error) {
    return { status: 'running' };
  }
}

function isPaused() {
  return String(readControl().status || '').toLowerCase() === 'paused';
}

function isStopped() {
  return String(readControl().status || '').toLowerCase() === 'stopped';
}

async function waitForResume(page) {
  while (isPaused()) {
    await page.waitForTimeout(2000);
    if (isStopped()) {
      return false;
    }
  }

  return !isStopped();
}

async function pickLocator(page, list) {
  for (const selector of list) {
    try {
      const locator = page.locator(selector).first();
      if (await locator.count()) {
        return locator;
      }
    } catch (error) {
      continue;
    }
  }

  return null;
}

async function typeInto(page, locator, text) {
  try {
    await locator.fill(text);
    return;
  } catch (error) {
    // Continue to keyboard typing for contenteditable controls.
  }

  await locator.click({ timeout: 5000 });
  await page.keyboard.type(text, { delay: 8 });
}

async function captureShot(page, name) {
  if (!outputDir) {
    return '';
  }

  ensureOutputDir();
  const filePath = path.join(outputDir, name);
  await page.screenshot({ path: filePath, fullPage: true });
  return filePath;
}

async function bodyText(page) {
  try {
    return await page.locator('body').innerText({ timeout: 5000 });
  } catch (error) {
    return '';
  }
}

function parseMetrics(text) {
  const metrics = {};
  const patterns = {
    impressions: /([\d,]+)\s+(?:views?|impressions?)/i,
    likes: /([\d,]+)\s+likes?/i,
    replies_received: /([\d,]+)\s+replies?/i,
    reposts: /([\d,]+)\s+reposts?/i,
    bookmarks: /([\d,]+)\s+bookmarks?/i,
    profile_visits: /([\d,]+)\s+profile visits?/i,
    follows: /([\d,]+)\s+follows?/i,
  };

  Object.keys(patterns).forEach((key) => {
    const match = text.match(patterns[key]);
    if (match) {
      metrics[key] = Number(String(match[1]).replace(/,/g, ''));
    }
  });

  return metrics;
}

async function launchContext() {
  const launchOptions = {
    executablePath,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  };

  const contextOptions = {
    viewport: { width: 1440, height: 900 },
    ignoreHTTPSErrors: true,
  };

  if (storageState && fs.existsSync(storageState)) {
    contextOptions.storageState = storageState;
  }

  if (profileDir) {
    fs.mkdirSync(profileDir, { recursive: true });
    return chromium.launchPersistentContext(profileDir, {
      ...launchOptions,
      ...contextOptions,
    });
  }

  const browser = await chromium.launch(launchOptions);
  const context = await browser.newContext(contextOptions);
  context.__xra_browser = browser;
  return context;
}

async function publishReply(page) {
  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2500);

  const compose = await pickLocator(page, selectors.compose);
  if (!compose) {
    throw new Error('Compose box not found.');
  }

  await typeInto(page, compose, replyText);
  await page.waitForTimeout(1000);

  const publishButton = await pickLocator(page, selectors.publish);
  if (!publishButton) {
    throw new Error('Publish button not found.');
  }

  await publishButton.click({ timeout: 10000 });
  await page.waitForTimeout(4000);

  const publishShot = await captureShot(page, `job-${jobId}-publish.png`);
  const text = await bodyText(page);
  return {
    url: page.url(),
    screenshot_path: publishShot,
    text_excerpt: text.slice(0, 1200),
    metrics: parseMetrics(text),
  };
}

async function monitorCycle(page, cycle) {
  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);
  const shot = await captureShot(page, `job-${jobId}-monitor-${cycle}.png`);
  const text = await bodyText(page);
  return {
    cycle,
    url: page.url(),
    screenshot_path: shot,
    text_excerpt: text.slice(0, 1200),
    metrics: parseMetrics(text),
  };
}

async function main() {
  ensureOutputDir();

  const result = {
    ok: false,
    job_id: jobId,
    published_url: '',
    publish: {},
    monitoring: [],
    status: 'running',
  };

  let context = null;
  let page = null;

  try {
    if (!targetUrl) {
      throw new Error('Target URL missing.');
    }

    if (isStopped()) {
      result.status = 'stopped';
      result.ok = true;
      console.log(JSON.stringify(result));
      return;
    }

    context = await launchContext();
    page = context.pages()[0] || await context.newPage();

    const publish = await publishReply(page);
    result.publish = publish;
    result.published_url = publish.url || targetUrl;

    for (let cycle = 1; cycle <= monitorCycles; cycle++) {
      if (!await waitForResume(page)) {
        result.status = 'stopped';
        result.ok = true;
        break;
      }

      const sample = await monitorCycle(page, cycle);
      result.monitoring.push(sample);

      if (cycle < monitorCycles) {
        for (let elapsed = 0; elapsed < monitorIntervalSeconds; elapsed += 1) {
          if (!await waitForResume(page)) {
            result.status = 'stopped';
            result.ok = true;
            break;
          }
          await page.waitForTimeout(1000);
        }
      }

      if (result.status === 'stopped') {
        break;
      }
    }

    result.ok = true;
    result.status = result.status === 'stopped' ? 'stopped' : 'completed';
    console.log(JSON.stringify(result));
  } catch (error) {
    result.ok = false;
    result.status = 'failed';
    result.error = String(error && error.message ? error.message : error);
    console.log(JSON.stringify(result));
  } finally {
    if (context) {
      if (context.__xra_browser) {
        await context.close().catch(() => {});
        await context.__xra_browser.close().catch(() => {});
      } else {
        await context.close().catch(() => {});
      }
    }
  }
}

main().catch((error) => {
  console.log(JSON.stringify({
    ok: false,
    status: 'failed',
    error: String(error && error.message ? error.message : error),
  }));
  process.exitCode = 1;
});
