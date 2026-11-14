// dalang - browser test. Started by deploy/e2e.sh inside the Playwright container.
// A real Chromium logs in, creates links through the form and follows the short links.
const { chromium } = require('playwright');

const BASE = process.env.BASE;                 // the test server, as the browser sees it
const SHORT = 'https://short.example.org';     // base_url in deploy/test-config.php
const PASSWORD = 'dalang-test-password';       // public test password, see deploy/test-config.php
let checks = 0, failed = 0;
const is = (name, got, want) => {
  checks++;
  if (got === want) console.log('OK    ' + name);
  else { failed++; console.log(`FAIL  ${name}, want '${want}' (got: '${got}')`); }
};

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const newPage = async (admin = true) => {
    const context = await browser.newContext();
    // The targets are on example.org. Answer them here, so the test needs no internet.
    await context.route(/^https:\/\/(www\.)?example\.org\//, (route) => route.fulfill({ contentType: 'text/html', body: '<title>target</title>' }));
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    if (admin) {   // errors on the admin pages count: a broken script or a blocked style would show up here
      page.on('pageerror', (e) => errors.push(e.message));
      page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
    }
    return page;
  };
  const login = async (page) => {
    await page.fill('#password', PASSWORD);
    await Promise.all([page.waitForURL(/\/admin\//), page.click('button')]);
    await page.waitForSelector('#url');
  };
  const count = async (page) => (await page.locator('tbody tr').count());
  // Clicks "Create", reads the short link from the page and calls it: once without following
  // (status and Location), once in the browser (where do I land).
  const createAndFollow = async (page, name, target) => {
    await page.click('form:has(#url) button');
    const shown = await page.locator('#short').getAttribute('href');
    is(`${name}: link text is the link`, await page.locator('#short').innerText(), shown);
    const code = shown.startsWith(SHORT + '/') ? shown.slice(SHORT.length + 1) : '';
    const answer = await page.request.get(`${BASE}/${code}`, { maxRedirects: 0 });
    is(`${name}: /${code} -> 301 ${target}`, `${answer.status()} ${answer.headers()['location']}`, `301 ${target}`);
    const visitor = await newPage(false);
    await visitor.goto(`${BASE}/${code}`);
    is(`${name}: the browser lands at the target`, visitor.url(), target);
    await visitor.context().close();
    return code;
  };

  const page = await newPage();
  await page.goto(`${BASE}/admin/`);
  is('login page shows no link form', await page.locator('#url').count(), 0);
  await login(page);
  is('after login the link form is there', await page.locator('#url').count(), 1);
  is('the list is empty', await count(page), 0);

  await page.fill('#url', 'https://example.org/own?a=1&b=2');
  await page.fill('#code', 'e2e-own');
  await page.fill('#title', 'With my own code');
  is('own code: short link uses it', await createAndFollow(page, 'own code', 'https://example.org/own?a=1&b=2'), 'e2e-own');
  is('own code: one link in the list', await count(page), 1);

  await page.fill('#url', 'https://example.org/random');
  const random = await createAndFollow(page, 'no code', 'https://example.org/random');
  is('no code: 5 random characters', /^[a-z0-9]{5}$/.test(random), true);
  is('no code: two links in the list', await count(page), 2);

  // Like the bookmarklet: ?url=&title= fills the form, nothing is created before the click.
  const bm = 'https://example.org/bookmarklet?x=1';
  const query = `?url=${encodeURIComponent(bm)}&title=${encodeURIComponent('From the "bookmarklet"')}`;
  await page.goto(`${BASE}/admin/${query}`);
  is('prefill: address is filled in', await page.inputValue('#url'), bm);
  is('prefill: title is filled in', await page.inputValue('#title'), 'From the "bookmarklet"');
  is('prefill: code is empty', await page.inputValue('#code'), '');
  is('prefill: nothing created yet', await count(page), 2);
  await page.fill('#code', 'e2e-bm');
  is('prefill: short link uses the code', await createAndFollow(page, 'prefill', bm), 'e2e-bm');
  is('prefill: three links in the list', await count(page), 3);
  is('prefill: title is in the list', await page.locator('tbody tr', { hasText: 'e2e-bm' }).locator('td').nth(1).innerText(), 'From the "bookmarklet"');

  // The bookmarklet without a session: log in first, the address must survive the login.
  const fresh = await newPage();
  await fresh.goto(`${BASE}/admin/${query}`);
  await login(fresh);
  is('prefill without session: address survives the login', await fresh.inputValue('#url'), bm);
  is('prefill without session: nothing created yet', await count(fresh), 3);
  is('prefill without session: created', /^[a-z0-9]{5}$/.test(await createAndFollow(fresh, 'prefill without session', bm)), true);

  is('no errors in the browser console of the admin', errors.join(' | '), '');
  await browser.close();
  console.log('');
  console.log(failed ? `${failed} of ${checks} checks FAILED` : `ALL OK (${checks} checks)`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.log('FAIL  the test stopped: ' + e.message); process.exit(1); });
