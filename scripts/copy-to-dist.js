import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Colors for console output
const colors = {
  reset: '\x1b[0m',
  green: '\x1b[32m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
  red: '\x1b[31m'
};

function log(message, color = 'reset') {
  console.log(`${colors[color]}${message}${colors.reset}`);
}

function copyRecursiveSync(src, dest) {
  const exists = fs.existsSync(src);
  const stats = exists && fs.statSync(src);
  const isDirectory = exists && stats.isDirectory();
  
  if (isDirectory) {
    if (!fs.existsSync(dest)) {
      fs.mkdirSync(dest, { recursive: true });
    }
    fs.readdirSync(src).forEach(childItemName => {
      copyRecursiveSync(
        path.join(src, childItemName),
        path.join(dest, childItemName)
      );
    });
  } else {
    if (!fs.existsSync(path.dirname(dest))) {
      fs.mkdirSync(path.dirname(dest), { recursive: true });
    }
    fs.copyFileSync(src, dest);
  }
}

function copyFileOrDir(src, dest, description) {
  try {
    if (fs.existsSync(src)) {
      copyRecursiveSync(src, dest);
      log(`✓ ${description}`, 'green');
      return true;
    } else {
      log(`✗ ${description} - NOT FOUND: ${src}`, 'yellow');
      return false;
    }
  } catch (error) {
    log(`✗ Error copying ${description}: ${error.message}`, 'red');
    return false;
  }
}

// Main function
function copyToDist() {
  const distPath = path.join(__dirname, '..', 'dist');
  
  log('\n📦 Copying files to dist/ folder...\n', 'blue');
  
  // Ensure dist directory exists
  if (!fs.existsSync(distPath)) {
    fs.mkdirSync(distPath, { recursive: true });
    log('✓ Created dist/ directory', 'green');
  }
  
  // Copy API folder
  copyFileOrDir(
    path.join(__dirname, '..', 'api'),
    path.join(distPath, 'api'),
    'API folder'
  );
  
  // Copy config folder
  copyFileOrDir(
    path.join(__dirname, '..', 'config'),
    path.join(distPath, 'config'),
    'Config folder'
  );
  
  // Copy telegram-bot folder (excluding vendor if exists)
  const telegramBotSrc = path.join(__dirname, '..', 'telegram-bot');
  const telegramBotDest = path.join(distPath, 'telegram-bot');
  
  // Copy telegram-bot files, but skip vendor folder
  if (fs.existsSync(telegramBotSrc)) {
    if (!fs.existsSync(telegramBotDest)) {
      fs.mkdirSync(telegramBotDest, { recursive: true });
    }
    
    fs.readdirSync(telegramBotSrc).forEach(item => {
      // Skip vendor folder (not needed - we use pure HTTP)
      if (item === 'vendor') {
        log('ℹ Skipping telegram-bot/vendor (not needed)', 'blue');
        return;
      }
      
      // Skip node_modules if exists
      if (item === 'node_modules') {
        log('ℹ Skipping telegram-bot/node_modules (not needed)', 'blue');
        return;
      }
      
      const srcPath = path.join(telegramBotSrc, item);
      const destPath = path.join(telegramBotDest, item);
      copyRecursiveSync(srcPath, destPath);
    });
    log('✓ Telegram bot folder (no vendor - using pure HTTP)', 'green');
  } else {
    log('✗ Telegram bot folder - NOT FOUND', 'yellow');
  }
  
  // Copy .htaccess
  copyFileOrDir(
    path.join(__dirname, '..', '.htaccess'),
    path.join(distPath, '.htaccess'),
    '.htaccess file'
  );
  
  // Create database folder (empty, database will be created automatically)
  const dbPath = path.join(distPath, 'database');
  if (!fs.existsSync(dbPath)) {
    fs.mkdirSync(dbPath, { recursive: true });
    log('✓ Created database/ folder (empty)', 'green');
  }
  
  // Create .gitkeep in database folder to ensure it's tracked
  const gitkeepPath = path.join(dbPath, '.gitkeep');
  if (!fs.existsSync(gitkeepPath)) {
    fs.writeFileSync(gitkeepPath, '');
    log('✓ Created database/.gitkeep', 'green');
  }
  
  // Note: Public files (tonconnect-manifest.json, vite.svg) are already copied by Vite
  log('ℹ Public files (tonconnect-manifest.json, vite.svg) are handled by Vite', 'blue');
  
  log('\n✅ All files copied to dist/ folder!\n', 'green');
  log('📋 Files ready for deployment:', 'blue');
  log('   - dist/ → Upload to parkiraj.info root', 'blue');
  log('   - Contains: api/, config/, telegram-bot/, database/, .htaccess\n', 'blue');
}

// Run the script
copyToDist();
