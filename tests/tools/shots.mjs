// Headless screenshots of the local app, independent of any visible browser window.
// A development tool only: it needs Node 22+ and Chrome, and no package.
//
// Usage, from the project root, with the app on http://localhost:8000:
//   node tests/tools/shots.mjs <out dir> <jobs.json>
//   LOGIN_FILE=storage/app/private/local-preview-manager-login.txt node tests/tools/shots.mjs <out dir> <jobs.json>
//   GUEST=1 node tests/tools/shots.mjs <out dir> <jobs.json>      (signed out: sign-in, error and invitation pages)
//
// A job: { name, url, theme?: 'dark' | 'light', width?, height?, js?, wait?, full? }
//   js runs in the page (inside an async function); what it returns is printed. Do not navigate from it.
//   full captures the whole page, which catches charts mid-animation: prefer a normal shot after scrolling.
//
// The sign-in comes from a git-ignored file: three lines (a note, the email, the password).
// Keep the out dir outside the repository, and never print the password.
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const [, , outDir, jobsFile] = process.argv;
const base = process.env.BASE_URL ?? 'http://localhost:8000';
const port = 9333;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const [, email, password] = readFileSync(process.env.LOGIN_FILE ?? 'storage/app/private/local-preview-login.txt', 'utf8').trim().split(/\r?\n/);
const jobs = JSON.parse(readFileSync(jobsFile, 'utf8'));

mkdirSync(outDir, { recursive: true });

const chrome = spawn(process.env.CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', [
    '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${outDir}/../chrome-profile${process.env.GUEST ? '-guest' : ''}${process.env.LOGIN_FILE ? '-other' : ''}`,
    '--window-size=1440,900', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check', 'about:blank',
], { stdio: 'ignore' });

let target = null;

for (let attempt = 0; attempt < 60 && ! target; attempt++) {
    try {
        target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((item) => item.type === 'page');
    } catch {
        await sleep(250);
    }
}

if (! target) {
    chrome.kill();
    throw new Error('Chrome did not start');
}

const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve) => socket.addEventListener('open', resolve));

let counter = 0;
const pending = new Map();
const waiters = [];

socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);

    if (message.id && pending.has(message.id)) {
        const { resolve, reject } = pending.get(message.id);
        pending.delete(message.id);
        message.error ? reject(new Error(message.error.message)) : resolve(message.result);
    } else if (message.method) {
        for (const waiter of [...waiters]) {
            if (waiter.method === message.method) {
                waiters.splice(waiters.indexOf(waiter), 1);
                waiter.resolve(message.params);
            }
        }
    }
});

const send = (method, params = {}) => new Promise((resolve, reject) => {
    pending.set(++counter, { resolve, reject });
    socket.send(JSON.stringify({ id: counter, method, params }));
});

const once = (method, ms = 20000) => new Promise((resolve, reject) => {
    waiters.push({ method, resolve });
    setTimeout(() => reject(new Error('timed out waiting for ' + method)), ms);
});

const evaluate = async (code) => {
    const result = await send('Runtime.evaluate', { expression: `(async () => { ${code} })()`, awaitPromise: true, returnByValue: true });

    if (result.exceptionDetails) {
        throw new Error(result.exceptionDetails.exception?.description ?? result.exceptionDetails.text);
    }

    return result.result.value;
};

const goto = async (url) => {
    const loaded = once('Page.loadEventFired');
    await send('Page.navigate', { url: url.startsWith('http') ? url : base + url });
    await loaded;
    await sleep(900);
};

await send('Page.enable');
await send('Runtime.enable');

const errors = [];
socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);

    if (message.method === 'Runtime.exceptionThrown') {
        errors.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
    }
});

try {
    await goto('/login');

    if (! process.env.GUEST && await evaluate(`return location.pathname`) === '/login') {
        const submitted = once('Page.loadEventFired');
        await evaluate(`
            document.querySelector('input[name=email]').value = ${JSON.stringify(email)};
            document.querySelector('input[name=password]').value = ${JSON.stringify(password)};
            document.querySelector('form[action$="/login"]').requestSubmit();
        `);
        await submitted;
        await sleep(600);
        console.log('signed in, now at', await evaluate(`return location.pathname`));
    }

    for (const job of jobs) {
        const width = job.width ?? 1440;
        const height = job.height ?? 900;

        await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
        await evaluate(`localStorage.setItem('theme', ${JSON.stringify(job.theme ?? 'dark')})`);
        await goto(job.url);

        if (job.js) {
            const said = await evaluate(job.js);

            if (said !== undefined) {
                console.log(`${job.name}:`, said);
            }
        }

        await sleep(job.wait ?? 400);

        let clip;

        if (job.full) {
            const { cssContentSize } = await send('Page.getLayoutMetrics');
            clip = { x: 0, y: 0, width, height: Math.min(Math.ceil(cssContentSize.height), 4000), scale: 1 };
        }

        const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: Boolean(job.full), clip });
        writeFileSync(`${outDir}/${job.name}.png`, Buffer.from(shot.data, 'base64'));
        console.log('saved', job.name, await evaluate(`return location.pathname + location.search`));
    }
} finally {
    if (errors.length) {
        console.log('page errors:', [...new Set(errors)].join('\n'));
    }

    socket.close();
    chrome.kill();
}
