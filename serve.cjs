const { execSync, spawnSync } = require('child_process');

function getLocalIP() {
  const output = execSync('ifconfig').toString();
  const regex = /inet (?!127)(\d+\.\d+\.\d+\.\d+)/g;
  let match;
  let ip;
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

function clearCache() {
  execSync('php artisan config:clear');
  execSync('php artisan cache:clear');
  execSync('php artisan route:clear');
  execSync('php artisan view:clear');
  console.log('Cache cleared');
}

function startServer(host, port) {
  console.log(`🚀 Starting Laravel server at http://${host === '0.0.0.0' ? 'localhost' : host}:${port}`);
  if (host === '0.0.0.0') {
    const lanIp = getLocalIP();
    if (lanIp) {
      console.log(`   LAN access: http://${lanIp}:${port} (Google OAuth requires localhost)`);
    }
  }
  // display_errors=0: artisan serve's server.php can emit Notices (broken pipe) that
  // flush Content-Type: text/html before Laravel adds CORS headers — browsers then
  // report a CORS failure even though config/cors.php allows localhost.
  execSync(
    `php -d display_errors=0 -d log_errors=1 artisan serve --host=${host} --port=${port}`,
    { stdio: 'inherit' },
  );
}

try {
  // Google OAuth only accepts localhost or public domains — not LAN IPs like 192.168.x.x
  const host = process.env.SERVE_HOST || '127.0.0.1';

  const port = findAvailablePort(8000, 10);
  if (!port) throw new Error('Could not find an open port after 10 attempts');

  clearCache();
  startServer(host, port);
} catch (err) {
  console.error(`❌ ${err.message}`);
  process.exit(1);
}
