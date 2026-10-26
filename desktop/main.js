/**
 * Desktop shell for labelmaker: starts PHP's built-in web server on a free local port
 * (127.0.0.1 only) and shows examples/index.html in a window. Everything stays on this
 * computer, so it works offline. Bluetooth printing is the page's own Web Bluetooth code;
 * this file only supplies the printer chooser that a browser would normally show.
 *
 * PHP is found in: $LABELMAKER_PHP, then desktop/php/ (bundled when packaged), then PATH.
 */

const { app, BrowserWindow, dialog } = require('electron');
const { spawn } = require('child_process');
const fs = require('fs');
const http = require('http');
const net = require('net');
const path = require('path');

const packaged = app.isPackaged;
const siteDir = packaged ? path.join(process.resourcesPath, 'site') : path.join(__dirname, '..');
const bundledPhp = path.join(packaged ? process.resourcesPath : __dirname, 'php', process.platform === 'win32' ? 'php.exe' : 'php');

let php = null;
let win = null;

function phpCommand() {
  if (process.env.LABELMAKER_PHP) return process.env.LABELMAKER_PHP;
  return fs.existsSync(bundledPhp) ? bundledPhp : 'php';
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      server.close(() => resolve(port));
    });
  });
}

function waitForServer(port, child) {
  return new Promise((resolve, reject) => {
    let exited = false;
    child.once('exit', () => { exited = true; });
    const started = Date.now();
    const poll = () => {
      if (exited) return reject(new Error('PHP stopped right after starting.'));
      http.get({ host: '127.0.0.1', port, path: '/index.html' }, res => {
        res.resume();
        resolve();
      }).on('error', () => {
        if (Date.now() - started > 10000) reject(new Error('PHP did not start within 10 seconds.'));
        else setTimeout(poll, 150);
      });
    };
    poll();
  });
}

async function startServer() {
  const port = await freePort();
  const docRoot = path.join(siteDir, 'examples');
  let stderr = '';
  php = spawn(phpCommand(), ['-S', `127.0.0.1:${port}`, '-t', docRoot], { cwd: docRoot, windowsHide: true });
  php.stderr.on('data', d => { stderr = (stderr + d).slice(-2000); });
  const failed = new Promise((_, reject) => php.once('error', e => {
    reject(new Error(`Could not run PHP (${phpCommand()}): ${e.message}\nInstall PHP 7.4+ with GD, or set LABELMAKER_PHP.`));
  }));
  try {
    await Promise.race([waitForServer(port, php), failed]);
  } catch (e) {
    throw new Error(`${e.message}\n${stderr}`.trim());
  }
  return port;
}

/**
 * Electron has no built-in Bluetooth chooser. It calls this repeatedly while scanning, so wait a
 * moment for the printers to show up, then ask which one (no question if there is only one).
 */
function bluetoothChooser(window) {
  let pending = null;
  window.webContents.on('select-bluetooth-device', (event, devices, callback) => {
    event.preventDefault();
    if (pending) {
      pending.devices = devices;
      return;
    }
    pending = { devices, callback };
    setTimeout(async () => {
      const { devices: found, callback: done } = pending;
      pending = null;
      if (found.length === 0) return done('');
      if (found.length === 1) return done(found[0].deviceId);
      const { response } = await dialog.showMessageBox(window, {
        type: 'question',
        message: 'Which printer?',
        buttons: [...found.map(d => d.deviceName || d.deviceId), 'Cancel'],
        cancelId: found.length,
      });
      done(response < found.length ? found[response].deviceId : '');
    }, 2500);
  });
}

async function createWindow() {
  win = new BrowserWindow({
    width: 1200,
    height: 850,
    autoHideMenuBar: true,
    title: 'Label Maker',
  });
  bluetoothChooser(win);
  try {
    const port = await startServer();
    await win.loadURL(`http://127.0.0.1:${port}/index.html`);
  } catch (e) {
    dialog.showErrorBox('Label Maker could not start', e.message);
    app.quit();
  }
}

// Web Bluetooth is behind a flag on Linux.
app.commandLine.appendSwitch('enable-experimental-web-platform-features');

app.whenReady().then(createWindow);
app.on('window-all-closed', () => app.quit());
app.on('quit', () => { if (php) php.kill(); });
