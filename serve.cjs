const { execSync, spawnSync } = require('child_process');

function getLocalIP() {
  const output = execSync('ifconfig').toString();
  const regex = /inet (?!127)(\d+\.\d+\.\d+\.\d+)/g;
  let match, ip;
  while ((match = regex.exec(output)) !== null) {
    ip = match[1];
    break;
  }
  return ip || null;
}

function isPortInUse(port) {
  const result = spawnSync('lsof', ['-i', `:${port}`]);
  return result.status === 0;
}

function killPort(port) {
  try {
    execSync(`lsof -ti:${port} | xargs kill -9`);
    console.log(`🛑 Killed process on port ${port}`);
    return true;
  } catch {
    return false;
  }
}

function findAvailablePort(startPort = 8000, maxAttempts = 10) {
  for (let port = startPort; port < startPort + maxAttempts; port++) {
    if (!isPortInUse(port)) return port;

    console.log(`⚠️ Port ${port} in use. Attempting to kill...`);
    if (killPort(port)) return port;
  }
  return null;
}

function startServer(ip, port) {
  console.log(`🚀 Starting Laravel server at http://${ip}:${port}`);
  execSync(`php artisan serve --host=${ip} --port=${port}`, { stdio: 'inherit' });
}

try {
  const ip = getLocalIP();
  if (!ip) throw new Error('Could not determine local IP address');

  const port = findAvailablePort(8000, 10);
  if (!port) throw new Error('Could not find an open port after 10 attempts');

  startServer(ip, port);
} catch (err) {
  console.error(`❌ ${err.message}`);
  process.exit(1);
}
