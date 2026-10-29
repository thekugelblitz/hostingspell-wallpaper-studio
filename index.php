<?php
/** HostingSpell Wallpaper Studio — PHP 8.2+, Fileinfo, GD (PNG/JPEG/WebP).
 * Put gallery images in the child walls/ directory. See README.md before public deployment.
 * Cron: php /absolute/path/index.php --cleanup (every five minutes).
 */
declare(strict_types=1);
// Keep warnings and filesystem paths out of AJAX responses, even on development hosts.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Buffer API output so PHP warnings cannot corrupt a JSON response.
$apiRequest = PHP_SAPI !== 'cli' && isset($_GET['action']) && is_string($_GET['action']) && $_GET['action'] !== 'app' && $_GET['action'] !== 'asset';
if ($apiRequest) ob_start();
$requestID = bin2hex(random_bytes(8));
$reserveMemory = str_repeat('x', 65536);
register_shutdown_function(static function () use ($apiRequest, &$reserveMemory, $requestID): void {
    $last = error_get_last();
    if (!$apiRequest || !$last || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $reserveMemory = null;
    diagnostic('fatal', $last['message'], $requestID);
    jsonOut(['ok' => false, 'error' => 'PHP stopped this request. Ask the administrator to check diagnostics. Reference ' . $requestID, 'reference' => $requestID], 500);
});
const TTL = 86400;
const MAX_BACKGROUND = 25 * 1024 * 1024;
const MAX_LOGO = 4 * 1024 * 1024;
const MAX_STORAGE = 1024 * 1024 * 1024;
const MAX_SESSIONS = 100;
const GUARD = "<?php http_response_code(404); exit; __halt_compiler();\n";
const BRAND_LOGO = 'https://manage.hostingspell.com/templates/lagom2/assets/img/logo/logo_big_inverse.1320928435.png';
// An absolute non-public path is also supported; default meets the process/ requirement.
$storage = getenv('WPS_STORAGE') ?: __DIR__ . '/process';
$cookieName = 'hs_wallpaper_' . substr(hash('sha256', __DIR__), 0, 10);
$script = basename(__FILE__);
$nonce = base64_encode(random_bytes(18));
function fail(string $message, int $code = 400): never { throw new RuntimeException($message, $code); }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function input(string $key, array $source): string { return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : ''; }
function jsonOut(array $data, int $status = 200): never {
    global $apiRequest, $requestID;
    if ($apiRequest) { while (ob_get_level() > 0) ob_end_clean(); }
    header('X-Studio-Request-ID: ' . $requestID);
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
// Guarded, bounded application log; no cookies, tokens, URLs or request bodies.
function diagnostic(string $event, string $detail, string $reference): void {
    global $storage;
    error_log('Wallpaper Studio [' . $reference . '] ' . $event . ': ' . $detail);
    if (!is_dir($storage) || is_link($storage)) return;
    $path = $storage . '/diagnostics.php';
    if (is_link($path)) return;
    $f = @fopen($path, 'c+b'); if (!$f) return;
    if (flock($f, LOCK_EX)) {
        if (fstat($f)['size'] > 524288) ftruncate($f, 0);
        if (fstat($f)['size'] === 0) fwrite($f, GUARD);
        fseek($f, 0, SEEK_END);
        fwrite($f, json_encode(['time' => gmdate('c'), 'reference' => $reference, 'event' => $event, 'detail' => substr($detail, 0, 1600)], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        fflush($f); flock($f, LOCK_UN);
    }
    fclose($f); @chmod($path, 0600);
}
function readData(string $path): array {
    if (!is_file($path) || is_link($path)) return [];
    $raw = file_get_contents($path);
    return is_string($raw) && str_starts_with($raw, GUARD) ? (json_decode(substr($raw, strlen(GUARD)), true) ?: []) : [];
}
function writeData(string $path, array $data): void {
    $tmp = $path . '.new.php';
    if (is_link($path) || is_link($tmp)) fail('Storage is not safe.', 503);
    if (file_put_contents($tmp, GUARD . json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($tmp, $path)) fail('Cannot write temporary project.', 503);
    chmod($path, 0600);
}
function lockFile(string $name, bool $wait = true) {
    global $storage;
    $path = $storage . '/' . $name . '.php';
    if (is_link($path)) fail('Storage is not safe.', 503);
    $f = fopen($path, 'c+b');
    if (!$f || !flock($f, LOCK_EX | ($wait ? 0 : LOCK_NB))) { if ($f) fclose($f); fail('Studio busy. Try again in a few seconds.', 429); }
    if (fstat($f)['size'] === 0) { fwrite($f, GUARD); fflush($f); }
    chmod($path, 0600);
    return $f;
}
function boot(): void {
    global $storage;
    if (is_link($storage)) fail('Storage is not safe.', 503);
    if (!is_dir($storage) && !mkdir($storage, 0700, true)) fail('The process folder must be writable by PHP.', 503);
    $lock = lockFile('setup');
    try {
        $deny = "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        if (!is_file($storage . '/.htaccess') && file_put_contents($storage . '/.htaccess', $deny) === false) fail('Cannot protect storage.', 503);
        if (!is_file($storage . '/index.html')) file_put_contents($storage . '/index.html', '');
        if (!is_file($storage . '/secret.php')) writeData($storage . '/secret.php', ['key' => bin2hex(random_bytes(32))]);
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function secret(): string {
    global $storage; $key = readData($storage . '/secret.php')['key'] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) fail('Storage key unavailable.', 503);
    return $key;
}
function sessionToken(?string $token): ?array {
    if (!$token || !preg_match('/^([a-f0-9]{64})\.(\d{10})\.([a-f0-9]{64})$/D', $token, $m)) return null;
    if ((int)$m[2] <= time() || (int)$m[2] > time() + TTL + 60) return null;
    if (!hash_equals(hash_hmac('sha256', $m[1] . '.' . $m[2], secret()), $m[3])) return null;
    return ['id' => $m[1], 'expires' => (int)$m[2], 'token' => $token];
}
function projectPath(array $s): string { global $storage; return $storage . '/' . hash_hmac('sha256', $s['id'], secret()); }
function csrf(array $s): string { return hash_hmac('sha256', 'csrf:' . $s['token'], secret()); }
function removeProject(string $dir): void {
    if (is_link($dir) || !is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $f) {
        if (!$f->isDot() && !$f->isDir() && !$f->isLink()) @unlink($f->getPathname());
    }
    @rmdir($dir);
}
// Call while holding the global mutation lock. No cookies or web traffic required in CLI mode.
function cleanup(): array {
    global $storage; $count = 0; $bytes = 0;
    foreach (new DirectoryIterator($storage) as $f) {
        if ($f->isLink() || !$f->isDir() || !preg_match('/^[a-f0-9]{64}$/D', $f->getFilename())) continue;
        $dir = $f->getPathname(); $meta = readData($dir . '/state.php');
        if (($meta['expires'] ?? 0) <= time()) { removeProject($dir); continue; }
        $count++;
        foreach (new DirectoryIterator($dir) as $asset) if ($asset->isFile() && !$asset->isLink()) $bytes += $asset->getSize();
    }
    return [$count, $bytes];
}
function rate(string $bucket, int $max, int $window, int $weight = 1): void {
    global $storage;
    $trafficLock = lockFile('traffic');
    try {
    $path = $storage . '/rates.php'; $data = readData($path); $now = time();
    foreach ($data as $k => $v) if (($v['until'] ?? 0) <= $now) unset($data[$k]);
    $key = hash_hmac('sha256', $bucket, secret());
    if (!isset($data[$key])) {
        if (count($data) >= 4096) fail('Studio traffic limit reached. Please try later.', 429);
        $data[$key] = ['n' => 0, 'until' => $now + $window];
    }
    if ($data[$key]['n'] + $weight > $max) { header('Retry-After: ' . max(1, $data[$key]['until'] - $now)); fail('Rate limit reached. Please try later.', 429); }
    $data[$key]['n'] += $weight; writeData($path, $data);
    } finally { flock($trafficLock, LOCK_UN); fclose($trafficLock); }
}
function pictureInfo(string $file, int $limit, bool $logo = false): array {
    if (!is_file($file) || filesize($file) < 12 || filesize($file) > $limit) fail('Image file is empty or exceeds its size limit.');
    $i = @getimagesize($file); $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!$i || !in_array($mime, $allowed, true) || $mime !== ($i['mime'] ?? '')) fail('Only genuine PNG, JPG and WebP images are accepted. SVG and animated GIF are not supported.');
    if ($i[0] < 1 || $i[1] < 1 || $i[0] > 12000 || $i[1] > 12000 || $i[0] * $i[1] > ($logo ? 4000000 : 24000000)) fail($logo ? 'Logo must be at most 4 megapixels.' : 'Wallpaper must be at most 24 megapixels and 12,000 px per side.');
    return ['width' => $i[0], 'height' => $i[1], 'mime' => $mime, 'bytes' => filesize($file)];
}
function saveAsset(string $source, string $target, bool $logo): array {
    $meta = pictureInfo($source, $logo ? MAX_LOGO : MAX_BACKGROUND, $logo);
    $out = fopen($target . '.new.php', 'wb'); if (!$out) fail('Cannot store image.', 503);
    fwrite($out, GUARD);
    try {
        if ($logo) {
            if (!function_exists('imagecreatefromstring')) fail('PHP GD is required for logo uploads.', 503);
            $im = @imagecreatefromstring(file_get_contents($source));
            if (!$im) fail('This logo cannot be decoded. Try a PNG file.');
            imagealphablending($im, false); imagesavealpha($im, true);
            $ok = imagepng($im, $out, 6); imagedestroy($im);
            if (!$ok) fail('Could not prepare logo.', 503);
            $meta['mime'] = 'image/png';
        } else {
            $in = fopen($source, 'rb'); if (!$in) fail('Cannot read image.', 503);
            try { if (stream_copy_to_stream($in, $out) === false) fail('Could not store wallpaper.', 503); } finally { fclose($in); }
        }
        fflush($out); $meta['bytes'] = ftell($out) - strlen(GUARD);
    } finally { fclose($out); }
    if ($logo && $meta['bytes'] > MAX_LOGO) { unlink($target . '.new.php'); fail('Normalized logo exceeds 4 MB. Resize it and try again.'); }
    chmod($target . '.new.php', 0600);
    if (!rename($target . '.new.php', $target)) fail('Cannot commit image.', 503);
    $meta['rev'] = bin2hex(random_bytes(6)); return $meta;
}
function safeLayout(array $d): array {
    $out = [];
    foreach (['width' => [320, 7680, 1920], 'height' => [320, 7680, 1080], 'x' => [0, 1, .5], 'y' => [0, 1, .5], 'scale' => [.02, 1, .22], 'opacity' => [.05, 1, 1], 'gridSize' => [8, 256, 64], 'gridRows' => [1, 24, 3], 'gridCols' => [1, 24, 3], 'gridSub' => [1, 4, 1]] as $k => [$min, $max, $default]) {
        $v = $d[$k] ?? $default;
        if (!is_numeric($v) || !is_finite((float)$v)) fail('Invalid editor settings.');
        $out[$k] = max($min, min($max, (float)$v));
    }
    $out['width'] = (int)$out['width']; $out['height'] = (int)$out['height'];
    if ($out['width'] * $out['height'] > 16000000) fail('Export is limited to 16 megapixels.');
    $out['fit'] = ($d['fit'] ?? '') === 'contain' ? 'contain' : 'cover';
    $out['gridMode'] = ($d['gridMode'] ?? '') === 'divisions' ? 'divisions' : 'pixels';
    foreach (['gridRows','gridCols','gridSub'] as $key) $out[$key] = (int)$out[$key];
    $out['grid'] = (bool)($d['grid'] ?? false); $out['snap'] = (bool)($d['snap'] ?? false);
    return $out;
}
function sendAsset(string $path, array $m): never {
    if (is_link($path) || !is_file($path)) fail('Image expired or unavailable.', 404);
    $f = fopen($path, 'rb'); if (!$f) fail('Image unavailable.', 404);
    if (fread($f, strlen(GUARD)) !== GUARD) { fclose($f); fail('Invalid asset.', 404); }
    header('Content-Type: ' . $m['mime']); header('Content-Length: ' . (string)(filesize($path) - strlen(GUARD)));
    header('Content-Disposition: inline'); fpassthru($f); fclose($f); exit;
}
function collection(): array {
    $items = [];
    $collectionDir = __DIR__ . '/walls';
    if (!is_dir($collectionDir) || is_link($collectionDir)) return [];
    foreach (new DirectoryIterator($collectionDir) as $f) {
        if ($f->isDot() || $f->isLink() || !$f->isFile() || str_starts_with($f->getFilename(), '.') || !preg_match('/\.(png|jpe?g|webp|avif|gif|img)$/iD', $f->getFilename())) continue;
        $i = @getimagesize($f->getPathname());
        if (!$i || !in_array($i['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'], true)) continue;
        $items[] = ['file' => $f->getFilename(), 'name' => pathinfo($f->getFilename(), PATHINFO_FILENAME), 'width' => $i[0], 'height' => $i[1], 'mime' => $i['mime'], 'bytes' => $f->getSize(), 'time' => $f->getMTime()];
    }
    usort($items, static fn($a, $b) => ($b['time'] <=> $a['time']) ?: strnatcasecmp($a['name'], $b['name']));
    return $items;
}

function applicationJS(): string {
    return <<<'WALLPAPER_APP_JS'
(() => {
'use strict';
const BOOT = JSON.parse(document.getElementById('studio-config').textContent);
const $ = id => document.getElementById(id);
const endpoint = action => BOOT.script + '?action=' + encodeURIComponent(action);
const clamp = (v, min, max) => Math.max(min, Math.min(max, v));
const fmtBytes = b => (b / 1048576).toFixed(1) + ' MB';
let activeView = 0, viewerReturn = null;
function viewWallpaper(index, opener) {
    const item = BOOT.items[index]; if (!item) return;
    activeView = index;
    $('viewer-title').textContent = item.name;
    $('viewer-image').src = './walls/' + encodeURIComponent(item.file);
    $('viewer-image').alt = item.name;
    $('viewer-meta').textContent = `${item.width} × ${item.height} px · ${fmtBytes(item.bytes)} · ${index + 1} of ${BOOT.items.length}`;
    $('viewer-download').href = BOOT.script + '?download=' + encodeURIComponent(item.file);
    $('viewer-stage').classList.remove('original'); $('viewer-stage').scrollTo(0, 0);
    $('viewer-fit').textContent = 'View original size';
    $('viewer-prev').disabled = $('viewer-next').disabled = BOOT.items.length < 2;
    if (!$('viewer').open) { viewerReturn = opener; $('viewer').showModal(); }
}
document.querySelectorAll('[data-view]').forEach(b => b.addEventListener('click', () => viewWallpaper(Number(b.dataset.view), b)));
$('viewer-close').onclick = () => $('viewer').close();
$('viewer').addEventListener('close', () => { $('viewer-image').removeAttribute('src'); viewerReturn?.focus(); });
$('viewer-prev').onclick = () => viewWallpaper((activeView - 1 + BOOT.items.length) % BOOT.items.length);
$('viewer-next').onclick = () => viewWallpaper((activeView + 1) % BOOT.items.length);
$('viewer-fit').onclick = () => { const original = $('viewer-stage').classList.toggle('original'); $('viewer-fit').textContent = original ? 'Fit to screen' : 'View original size'; };
$('viewer').addEventListener('keydown', e => { if (e.key === 'ArrowLeft') { e.preventDefault(); $('viewer-prev').click(); } if (e.key === 'ArrowRight') { e.preventDefault(); $('viewer-next').click(); } });
$('search').addEventListener('input', () => {
    const query = $('search').value.toLocaleLowerCase().trim(); let count = 0;
    document.querySelectorAll('.card').forEach(card => { card.hidden = !card.dataset.name.toLocaleLowerCase().includes(query); if (!card.hidden) count++; });
    $('collection-empty').hidden = count !== 0; $('collection-empty').textContent = query ? 'No wallpapers match your search.' : 'No wallpapers here yet.';
});
let ready = false, busy = false, expired = false, project = null, background = null, logo = null;
let layout = {width:1920,height:1080,x:.5,y:.5,scale:.22,opacity:1,fit:'cover',grid:false,snap:false,gridSize:64,gridMode:'pixels',gridRows:3,gridCols:3,gridSub:1};
let saveTimer = 0, pendingSave = false, drag = null;
const canvas = $('canvas'), ctx = canvas.getContext('2d', {alpha:false});
function status(message, type = '') { $('status').textContent = message; $('status').className = 'status ' + type; }
function setBusy(value) {
    $('retry-project').hidden = ready || value || expired;
    busy = value;
    ['background-file','logo-file','delete-project','remove-logo'].forEach(id => $(id).disabled = value || expired);
    $('export').disabled = value || expired || !background;
    $('retry-project').hidden = ready || value || expired;
}
async function request(action, body, isJSON = false) {
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 45000);
    try {
        const headers = {'X-CSRF-Token':BOOT.csrf}; if (isJSON) headers['Content-Type'] = 'application/json';
        const response = await fetch(endpoint(action), {method:'POST',headers,body,credentials:'same-origin',cache:'no-store',signal:controller.signal});
        const text = await response.text();
        let data; try { data = JSON.parse(text); } catch {
            const code = response.status, ref = response.headers.get('X-Studio-Request-ID') || response.headers.get('CF-Ray');
            const hint = [403,406,429].includes(code) ? 'The web server or firewall blocked the request.' : code === 413 ? 'The web server rejected the upload size.' : [502,503,504,524].includes(code) ? 'The server or upstream connection timed out or is unavailable.' : code === 404 ? 'The API path was not found. Check directory rewrite rules.' : 'PHP or the web server returned an HTML or empty response.';
            throw new Error(`HTTP ${code}: ${hint}${ref ? ' Reference '+ref+'.' : ''} Try Upload wallpaper.`);
        }
        if (!response.ok || !data.ok) { if (response.status === 410) expire(); throw new Error((data.error || 'The request could not be completed.') + (data.reference ? ' Reference ' + data.reference + '.' : '')); }
        return data;
    } catch (e) { if (e.name === 'AbortError') throw new Error('The request timed out. Try again, or upload the photo from your device.'); throw e; }
    finally { clearTimeout(timeout); }
}
async function loadImage(kind, meta) {
    if (!meta) return null;
    const image = new Image();
    image.src = endpoint('asset') + '&kind=' + kind + '&v=' + encodeURIComponent(meta.rev);
    await image.decode(); return image;
}
function syncControls() {
    $('width').value = layout.width; $('height').value = layout.height;
    const key = layout.width + ',' + layout.height;
    $('preset').value = [...$('preset').options].some(o => o.value === key) ? key : 'custom';
    $('scale').value = Math.round(layout.scale * 100); $('opacity').value = Math.round(layout.opacity * 100);
    $('scale-value').textContent = Math.round(layout.scale * 100) + '%'; $('opacity-value').textContent = Math.round(layout.opacity * 100) + '%';
    $('fit').value = layout.fit; $('grid').checked = layout.grid; $('snap').checked = layout.snap; $('grid-size').value = layout.gridSize;
    $('grid-mode').value=layout.gridMode; $('grid-rows').value=layout.gridRows; $('grid-cols').value=layout.gridCols; $('grid-sub').value=layout.gridSub;
    $('pixel-grid').hidden=layout.gridMode!=='pixels'; $('division-grid').hidden=layout.gridMode!=='divisions';
    $('dimensions').textContent = `${layout.width} × ${layout.height}`;
}
async function applyProject(data, restoreLayout = false) {
    const nextBG = data.background?.rev !== project?.background?.rev;
    const nextLogo = data.logo?.rev !== project?.logo?.rev;
    // Commit new image references only after all image decoding succeeds.
    const [bg, lg] = await Promise.all([nextBG ? loadImage('background', data.background) : background, nextLogo ? loadImage('logo', data.logo) : logo]);
    background = bg; logo = lg; project = data;
    if (restoreLayout) layout = {...layout,...data.layout};
    $('background-note').textContent = data.background ? `${data.background.name} · ${data.background.width} × ${data.background.height} · ${fmtBytes(data.background.bytes)}` : 'PNG, JPG or WebP. Uploaded wallpapers stay private.';
    syncControls(); render(); setBusy(busy);
}
async function initialize() {
    if (ready || busy || expired) return;
    setBusy(true); status('Restoring your private workspace…');
    try { const data = await request('state'); await applyProject(data.project, true); ready = true; status('Ready. Add a wallpaper and your logo. Changes to the layout save automatically.'); }
    catch (e) { status(e.message, 'error'); }
    finally { setBusy(false); }
}
$('retry-project').onclick = () => initialize();
function selectTab(name) {
    ['collection','create'].forEach(key => {
        $('tab-' + key).setAttribute('aria-selected', String(name === key)); $('tab-' + key).tabIndex = name === key ? 0 : -1; $(key).hidden = name !== key;
    });
    if (name === 'create') initialize();
}
['collection','create'].forEach(name => {
    $('tab-' + name).onclick = e => { e.preventDefault(); selectTab(name); history.replaceState(null, '', '?tab=' + name); };
    $('tab-' + name).onkeydown = e => {
        if (['ArrowLeft','ArrowRight','Home','End'].includes(e.key)) { e.preventDefault(); const next = e.key === 'Home' ? 'collection' : e.key === 'End' ? 'create' : name === 'collection' ? 'create' : 'collection'; selectTab(next); $('tab-' + next).focus(); }
    };
});
function logoRect() {
    if (!logo) return null;
    const w = Math.min(layout.width * layout.scale, layout.width, layout.height * logo.naturalWidth / logo.naturalHeight);
    const height = w * logo.naturalHeight / logo.naturalWidth;
    const x = clamp(layout.x * layout.width - w / 2, 0, layout.width - w);
    const y = clamp(layout.y * layout.height - height / 2, 0, layout.height - height);
    return {x,y,w,h:height};
}
function gridSteps() { return layout.gridMode==='divisions' ? {x:layout.width/(layout.gridCols*layout.gridSub),y:layout.height/(layout.gridRows*layout.gridSub)} : {x:layout.gridSize,y:layout.gridSize}; }
function paint(context, guides) {
    const {width:w,height:h} = layout; context.fillStyle = '#000'; context.fillRect(0,0,w,h);
    if (background) {
        const ratio = layout.fit === 'contain' ? Math.min(w/background.naturalWidth,h/background.naturalHeight) : Math.max(w/background.naturalWidth,h/background.naturalHeight);
        const bw = background.naturalWidth * ratio, bh = background.naturalHeight * ratio;
        context.drawImage(background,(w-bw)/2,(h-bh)/2,bw,bh);
    }
    if (logo) { const r = logoRect(); context.save(); context.globalAlpha = layout.opacity; context.drawImage(logo,r.x,r.y,r.w,r.h); context.restore(); }
    if (guides && layout.grid) {
        context.save(); context.strokeStyle = 'rgba(255,255,255,.27)'; context.lineWidth = Math.max(1,w/1800); context.beginPath();
        const step=gridSteps();
        for (let x=step.x;x<w-.01;x+=step.x) {context.moveTo(x,0);context.lineTo(x,h);}
        for (let y=step.y;y<h-.01;y+=step.y) {context.moveTo(0,y);context.lineTo(w,y);}
        context.stroke();
        if(layout.gridMode==='divisions' && layout.gridSub>1) { context.strokeStyle='rgba(255,132,46,.65)';context.lineWidth=Math.max(2,w/1000);context.beginPath();for(let c=1;c<layout.gridCols;c++){const x=c*w/layout.gridCols;context.moveTo(x,0);context.lineTo(x,h);}for(let r=1;r<layout.gridRows;r++){const y=r*h/layout.gridRows;context.moveTo(0,y);context.lineTo(w,y);}context.stroke(); }
        context.restore();
    }
    if (guides && logo) { const r=logoRect(); context.save(); context.strokeStyle='#ff842e'; context.lineWidth=Math.max(2,w/900); context.setLineDash([w/160,w/220]); context.strokeRect(r.x,r.y,r.w,r.h); context.setLineDash([]);context.fillStyle='#ff842e';const handle=layout.width/80;context.fillRect(r.x+r.w-handle/2,r.y+r.h-handle/2,handle,handle); context.restore(); }
}
function render() {
    $('stage-empty').hidden = !!background; canvas.hidden = !background;
    if (canvas.width !== layout.width || canvas.height !== layout.height) { canvas.width = layout.width; canvas.height = layout.height; }
    paint(ctx,true);
}
function scheduleSave() { pendingSave = true; clearTimeout(saveTimer); saveTimer = setTimeout(saveLayout, 1800); }
async function saveLayout() {
    if (!ready || expired || !pendingSave) return;
    if (busy || drag) { saveTimer = setTimeout(saveLayout,2000); return; }
    const snapshot = JSON.stringify(layout); pendingSave = false; setBusy(true);
    try { await request('save',snapshot,true); if (!pendingSave) status('Layout saved. Your project will still expire at the original time.'); }
    catch (e) { status('Layout is still available in this tab, but could not be saved: ' + e.message, 'error'); }
    finally { setBusy(false); if (pendingSave) scheduleSave(); }
}
function changed() { syncControls(); render(); scheduleSave(); }
function moveLogo(x,y) {
    if (!logo) return;
    const r=logoRect();
    if (layout.snap) { const step=gridSteps(); x=Math.round(x/step.x)*step.x; y=Math.round(y/step.y)*step.y; }
    layout.x = clamp(x,r.w/2,layout.width-r.w/2)/layout.width;
    layout.y = clamp(y,r.h/2,layout.height-r.h/2)/layout.height;
    render();
}
function pointerPosition(e) { const r=canvas.getBoundingClientRect(); return {x:(e.clientX-r.left)*layout.width/r.width,y:(e.clientY-r.top)*layout.height/r.height}; }
canvas.addEventListener('pointerdown',e=>{
    if (!logo || busy || expired) return; const p=pointerPosition(e),r=logoRect();
    const hit=layout.width/50;
    if(Math.abs(p.x-r.x-r.w)<hit && Math.abs(p.y-r.y-r.h)<hit){drag={id:e.pointerId,resize:true,left:r.x,top:r.y};canvas.setPointerCapture(e.pointerId);canvas.focus();e.preventDefault();return;}
    if(p.x<r.x||p.x>r.x+r.w||p.y<r.y||p.y>r.y+r.h) return;
    drag={id:e.pointerId,dx:p.x-r.x-r.w/2,dy:p.y-r.y-r.h/2}; canvas.setPointerCapture(e.pointerId); canvas.focus(); e.preventDefault();
});
canvas.addEventListener('pointermove',e=>{if(!drag||drag.id!==e.pointerId)return;const p=pointerPosition(e);if(drag.resize){const ratio=logo.naturalHeight/logo.naturalWidth;const width=clamp(Math.max(p.x-drag.left,(p.y-drag.top)/ratio),layout.width*.02,Math.min(layout.width-drag.left,(layout.height-drag.top)/ratio));layout.scale=width/layout.width;layout.x=(drag.left+width/2)/layout.width;layout.y=(drag.top+width*ratio/2)/layout.height;syncControls();render();}else moveLogo(p.x-drag.dx,p.y-drag.dy);});
function endDrag(e) { if(drag&&drag.id===e.pointerId){drag=null;scheduleSave();} }
canvas.addEventListener('pointerup',endDrag);canvas.addEventListener('pointercancel',endDrag);canvas.addEventListener('lostpointercapture',endDrag);
canvas.addEventListener('keydown',e=>{
    if(!logo||expired||busy||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;
    e.preventDefault();const r=logoRect(),step=layout.snap?(['ArrowLeft','ArrowRight'].includes(e.key)?gridSteps().x:gridSteps().y):e.shiftKey?10:1;
    moveLogo(r.x+r.w/2+(e.key==='ArrowLeft'?-step:e.key==='ArrowRight'?step:0),r.y+r.h/2+(e.key==='ArrowUp'?-step:e.key==='ArrowDown'?step:0));scheduleSave();
});
$('alignment').addEventListener('click',e=>{const b=e.target.closest('[data-align]');if(!b||!logo||expired)return;const [x,y]=b.dataset.align.split(',').map(Number),r=logoRect();layout.x=(r.w/2+x*(layout.width-r.w))/layout.width;layout.y=(r.h/2+y*(layout.height-r.h))/layout.height;changed();});
$('scale').oninput=()=>{layout.scale=Number($('scale').value)/100;changed();};
$('opacity').oninput=()=>{layout.opacity=Number($('opacity').value)/100;changed();};
$('fit').onchange=()=>{layout.fit=$('fit').value;changed();};
$('grid').onchange=()=>{layout.grid=$('grid').checked;changed();};
$('snap').onchange=()=>{layout.snap=$('snap').checked;changed();};
$('grid-mode').onchange=()=>{layout.gridMode=$('grid-mode').value;changed();};
[['grid-rows','gridRows',24],['grid-cols','gridCols',24],['grid-sub','gridSub',4]].forEach(([id,key,max])=>$(id).onchange=()=>{layout[key]=Math.round(clamp(Number($(id).value)||1,1,max));changed();});
$('grid-size').onchange=()=>{layout.gridSize=Math.round(clamp(Number($('grid-size').value)||64,8,256));changed();};
function resize(w,h) {
    if (!Number.isInteger(w)||!Number.isInteger(h)||w<320||h<320||w>7680||h>7680||w*h>16000000) {status('Choose 320–7680 px per side, with no more than 16 million pixels in total.','error');syncControls();return;}
    layout.width=w;layout.height=h;changed();
}
$('preset').onchange=()=>{if($('preset').value!=='custom')resize(...$('preset').value.split(',').map(Number));};
['width','height'].forEach(id=>$(id).onchange=()=>resize(Number($('width').value),Number($('height').value)));
$('reset-layout').onclick=()=>{layout.x=.5;layout.y=.5;layout.scale=.22;layout.opacity=1;changed();};
async function upload(kind,input) {
    const file=input.files[0];if(!file||busy||expired)return;
    if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>(kind==='logo'?4:25)*1048576){status(`Choose PNG, JPG or WebP up to ${kind==='logo'?4:25} MB.`,'error');input.value='';return;}
    if(!ready){await initialize();if(!ready)return;}
    try {
        const check = new Image(), localURL = URL.createObjectURL(file);
        try { check.src = localURL; await check.decode(); if(check.naturalWidth > 12000 || check.naturalHeight > 12000 || check.naturalWidth * check.naturalHeight > (kind==='logo'?4000000:24000000)) throw new Error(kind==='logo'?'Logo must be at most 4 megapixels.':'Wallpaper must be at most 24 megapixels and 12,000 px per side.'); }
        finally { URL.revokeObjectURL(localURL); }
    } catch(e) { status(e.message || 'Image could not be read. Try a PNG or JPG file.', 'error'); input.value=''; return; }
    setBusy(true);status(`Uploading ${kind==='logo'?'your logo':'wallpaper'}…`);
    const form=new FormData();form.set('kind',kind);form.set('file',file);
    try{const data=await request('upload',form);await applyProject(data.project);status(kind==='logo'?'Logo ready. Drag it into place, or use the placement buttons.':'Wallpaper ready. Add your company logo.','success');}
    catch(e){status(e.message,'error');}finally{input.value='';setBusy(false);}
}
$('remove-logo').onclick=async()=>{if(busy||expired||!logo)return;setBusy(true);try{const data=await request('remove_logo');await applyProject(data.project);status('Logo removed.');}catch(e){status(e.message,'error');}finally{setBusy(false);}};
$('background-file').onchange=()=>upload('background',$('background-file'));
const dropZone = $('background-drop');
['dragenter','dragover'].forEach(name => dropZone.addEventListener(name,e=>{e.preventDefault();dropZone.classList.add('drag-over');}));
['dragleave','drop'].forEach(name => dropZone.addEventListener(name,e=>{e.preventDefault();dropZone.classList.remove('drag-over');}));
dropZone.addEventListener('drop',e=>{if(busy||expired)return;const files=e.dataTransfer.files;if(files.length!==1){status('Choose one wallpaper at a time.','error');return;}upload('background',{files,value:''});});
$('logo-file').onchange=()=>upload('logo',$('logo-file'));
$('export').onclick=async()=>{
    if(!background||busy||expired)return;setBusy(true);status('Preparing your wallpaper…');
    let out=null;
    try{
        out=document.createElement('canvas');out.width=layout.width;out.height=layout.height;
        const context=out.getContext('2d',{alpha:false});if(!context)throw new Error('Your browser cannot create this canvas. Try a smaller size.');paint(context,false);
        const type=$('format').value,blob=await new Promise((resolve,reject)=>out.toBlob(b=>b?resolve(b):reject(new Error('Export failed. Try a smaller canvas or PNG.')),'image/'+type,.94));
        const actual=blob.type==='image/jpeg'?'jpg':blob.type==='image/webp'?'webp':'png';
        const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=`my-wallpaper-${layout.width}x${layout.height}.${actual}`;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),30000);
        status('Download prepared. Your finished wallpaper stays on your device.','success');
    }catch(e){status(e.message,'error');}finally{if(out){out.width=1;out.height=1;}setBusy(false);}
};
$('delete-project').onclick=async()=>{
    if(busy||expired)return;if(!confirm('Delete your temporary wallpaper, logo and saved layout now? This cannot be undone.'))return;
    clearTimeout(saveTimer);setBusy(true);try{await request('clear');location.reload();}catch(e){status(e.message,'error');setBusy(false);}
};
function expire(){if(expired)return;expired=true;clearTimeout(saveTimer);background=logo=null;canvas.width=canvas.height=1;canvas.hidden=true;$('stage-empty').hidden=false;$('stage-empty').textContent='Your temporary project has expired. Reload to start a new project.';setBusy(false);status('Project expired. Reload to start again.','error');}
function countdown(){const seconds=Math.max(0,BOOT.expires-Math.floor(Date.now()/1000));$('expiry').textContent=seconds?`Expires in ${Math.floor(seconds/3600)}h ${Math.floor(seconds%3600/60)}m · fixed 24-hour lifetime`:'Project expired';if(!seconds)expire();}
countdown();setInterval(countdown,15000);syncControls();selectTab(BOOT.initialTab || 'collection');

})();
WALLPAPER_APP_JS;
}

try {
    if (PHP_SAPI !== 'cli' && input('action', $_GET) === 'app') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Method not allowed.', 405);
        header('Content-Type: application/javascript; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        echo applicationJS(); exit;
    }
    boot();
    if (PHP_SAPI === 'cli') {
        if (($argv[1] ?? '') === '--diagnostics') {
            echo 'PHP ' . PHP_VERSION . ' | handler ' . PHP_SAPI . "\n";
            foreach (['memory_limit','max_execution_time','upload_max_filesize','post_max_size','error_log'] as $key) echo $key . '=' . ini_get($key) . "\n";
            foreach (['gd','fileinfo'] as $ext) echo $ext . '=' . (extension_loaded($ext) ? 'enabled' : 'MISSING') . "\n";
            $log = $storage . '/diagnostics.php'; if (is_file($log)) { $lines = explode("\n", file_get_contents($log)); echo implode("\n", array_slice($lines, max(1,count($lines)-20))) . "\n"; }
            exit;
        }
        if (($argv[1] ?? '') !== '--cleanup') { fwrite(STDERR, "Usage: php index.php --cleanup\n"); exit(1); }
        $lock = lockFile('mutate'); $stats = cleanup(); flock($lock, LOCK_UN); fclose($lock);
        echo "Cleanup complete; {$stats[0]} active projects, {$stats[1]} asset bytes.\n"; exit;
    }
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY'); header('Cache-Control: no-store, private, no-transform');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('CDN-Cache-Control: no-store');
    header('Cloudflare-CDN-Cache-Control: no-store');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce'; style-src 'nonce-$nonce'; style-src-attr 'unsafe-inline'; img-src 'self' blob: data: https://manage.hostingspell.com; connect-src 'self'; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    $session = sessionToken(input($cookieName, $_COOKIE));
    $action = input('action', $_GET);
    if ($action === 'asset') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !$session) fail('Project expired. Start a new project.', 410);
        $dir = projectPath($session); $meta = readData($dir . '/state.php'); $kind = input('kind', $_GET);
        if (($meta['expires'] ?? 0) <= time() || !in_array($kind, ['background', 'logo'], true) || !isset($meta[$kind])) fail('Image expired or unavailable.', 404);
        rate('assets:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 120, 600);
        rate('asset-bytes:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 512 * 1024 * 1024, 3600, (int)$meta[$kind]['bytes']);
        sendAsset($dir . '/' . $kind . '.php', $meta[$kind]);
    }
    if ($action !== '') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST for this action.', 405);
        if (!in_array($action, ['state', 'upload', 'save', 'clear', 'remove_logo'], true)) fail('Unknown action.', 404);
        if (!$session) fail('Your temporary project expired. Reload to start again.', 410);
        $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) fail('Cross-site request blocked.', 403);
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($provided) || !hash_equals(csrf($session), $provided)) fail('Security token expired. Reload the page.', 403);
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > MAX_BACKGROUND + 1048576) fail('Request exceeds the 26 MB limit.', 413);
        // One mutation at a time bounds memory and disk use on shared hosting.
        $lock = lockFile('mutate', false);
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown'; // Do not trust visitor-supplied forwarding headers.
            rate('api:' . $ip, 120, 600); rate('session:' . $session['id'], 120, 600);
            [$count, $bytes] = cleanup(); $dir = projectPath($session); $meta = readData($dir . '/state.php');
            if ($action === 'clear') {
                removeProject($dir);
                setcookie($cookieName, '', ['expires' => time() - 3600, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('WPS_HTTPS') === '1', 'httponly' => true, 'samesite' => 'Strict']);
                jsonOut(['ok' => true]);
            }
            if (!$meta) {
                rate('new:' . $ip, 6, 3600);
                if ($count >= MAX_SESSIONS) fail('All temporary project slots are occupied. Try again later.', 503);
                if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700))) fail('Cannot create project.', 503);
                $meta = ['expires' => $session['expires'], 'layout' => safeLayout([])]; writeData($dir . '/state.php', $meta);
            }
            if ($action === 'remove_logo') {
                @unlink($dir . '/logo.php'); unset($meta['logo']); writeData($dir . '/state.php', $meta);
            }
            if ($action === 'save') {
                rate('save:' . $ip, 40, 600);
                $raw = file_get_contents('php://input', false, null, 0, 8193);
                if (strlen($raw) > 8192) fail('Settings are too large.', 413);
                $layout = json_decode($raw, true); if (!is_array($layout)) fail('Invalid settings.');
                $meta['layout'] = safeLayout($layout); writeData($dir . '/state.php', $meta);
            }
            if ($action === 'upload') {
                if ($bytes + MAX_BACKGROUND + MAX_LOGO > MAX_STORAGE) fail('Temporary storage is full. Try again after cleanup.', 507);
                if (disk_free_space($storage) < 100 * 1024 * 1024) fail('Temporary storage is low on disk space.', 507);
                $kind = input('kind', $_POST);
                if (!in_array($kind, ['background', 'logo'], true)) fail('Invalid image type.');
                $source = ''; $name = '';
                try {
                        rate('uploads:' . $ip, 20, 3600);
                        $file = $_FILES['file'] ?? null;
                        if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) fail('Upload failed. Check the file and PHP upload limits.');
                        $source = $file['tmp_name']; $name = substr(pathinfo(basename(is_string($file['name']) ? $file['name'] : 'Image'), PATHINFO_FILENAME), 0, 160);
                    $asset = saveAsset($source, $dir . '/' . $kind . '.php', $kind === 'logo');
                    $asset['name'] = $name;
                    $meta[$kind] = $asset; writeData($dir . '/state.php', $meta);
                } finally {
                    if (is_file($dir . '/' . $kind . '.php.new.php')) unlink($dir . '/' . $kind . '.php.new.php');
                }
            }
            jsonOut(['ok' => true, 'project' => $meta]);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Method not allowed.', 405);
    // No server-side project is allocated until Create is opened.
    if (!$session) {
        $id = bin2hex(random_bytes(32)); $expires = time() + TTL;
        $token = $id . '.' . $expires . '.' . hash_hmac('sha256', $id . '.' . $expires, secret());
        $session = ['id' => $id, 'expires' => $expires, 'token' => $token];
        setcookie($cookieName, $token, ['expires' => $expires, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/', 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('WPS_HTTPS') === '1', 'httponly' => true, 'samesite' => 'Strict']);
    }
    $initialTab = input('tab', $_GET) === 'create' ? 'create' : 'collection';
    $items = collection();
    $download = input('download', $_GET);
    if ($download !== '') {
        foreach ($items as $item) if ($item['file'] === $download) {
            $ext = pathinfo($download, PATHINFO_EXTENSION);
            header('Content-Type: ' . $item['mime']); header('Content-Length: ' . $item['bytes']);
            header("Content-Disposition: attachment; filename=\"wallpaper.$ext\"; filename*=UTF-8''" . rawurlencode($download));
            readfile(__DIR__ . '/walls/' . $item['file']); exit;
        }
        fail('Wallpaper not found.', 404);
    }
} catch (Throwable $e) {
    $code = $e instanceof RuntimeException && $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;
    $message = $code === 500 ? 'The studio encountered a server error. Please try again.' : $e->getMessage();
    diagnostic('http_' . $code, $e->getMessage(), $requestID);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $message . "\n"); exit(1); }
    if (input('action', $_GET) !== '') jsonOut(['ok' => false, 'error' => $message, 'reference' => $requestID], $code);
    http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); exit($message);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#000000"><meta name="description" content="Free online wallpaper maker: add your logo to desktop and mobile wallpapers. Download AMOLED backgrounds or create custom branded wallpapers in PNG, JPG and WebP.">
<title>Free Wallpaper Maker &amp; AMOLED Wallpapers | HostingSpell</title>
<link rel="canonical" href="https://manage.hostingspell.com/walls/<?= $initialTab === 'create' ? '?tab=create' : '' ?>">
<meta name="robots" content="index,follow,max-image-preview:large">
<meta property="og:type" content="website"><meta property="og:title" content="Free Wallpaper Maker &amp; AMOLED Wallpapers | HostingSpell"><meta property="og:description" content="Create a custom wallpaper with your logo. Free desktop and phone backgrounds."><meta property="og:url" content="https://manage.hostingspell.com/walls/<?= $initialTab === 'create' ? '?tab=create' : '' ?>"><meta property="og:image" content="<?= h(BRAND_LOGO) ?>">
<meta name="twitter:card" content="summary"><meta name="twitter:title" content="HostingSpell Wallpaper Studio"><meta name="twitter:description" content="Free logo wallpaper maker and AMOLED backgrounds.">
<script type="application/ld+json" nonce="<?= h($nonce) ?>">{"@context":"https://schema.org","@type":"WebApplication","name":"HostingSpell Wallpaper Studio","url":"https://manage.hostingspell.com/walls/","applicationCategory":"DesignApplication","operatingSystem":"Any","browserRequirements":"Requires JavaScript for editing","offers":{"@type":"Offer","price":"0","priceCurrency":"USD"},"description":"Free online logo wallpaper maker for desktop and mobile, with an AMOLED wallpaper collection."}</script>
<style nonce="<?= h($nonce) ?>">
:root{color-scheme:dark;--ink:#fafafa;--muted:#aaa;--line:#282828;--orange:#ff842e;--panel:#000;--round:15px}*{box-sizing:border-box}body{margin:0;background:#000;color:var(--ink);font:14px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}button,input,select{font:inherit}button,a,input,select{touch-action:manipulation}a{color:inherit;text-decoration:none}button{cursor:pointer}button:disabled{opacity:.45;cursor:wait}button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,canvas:focus-visible{outline:2px solid var(--orange);outline-offset:4px}button,input,select{color:var(--ink)}button{border:1px solid var(--line);border-radius:9px;background:#111;min-height:41px;padding:8px 14px;font-weight:600}button:hover{border-color:#666}.primary{background:var(--orange);border-color:var(--orange);color:#000}.primary:hover{background:#ff9d58;border-color:#ff9d58}.danger{color:#ff9e9e}input:not([type=checkbox]):not([type=range]),select{width:100%;background:#000;border:1px solid #383838;border-radius:8px;padding:10px;min-width:0}input[type=range]{accent-color:var(--orange);width:100%;margin:8px 0}input[type=checkbox]{accent-color:var(--orange);width:17px;height:17px}label{display:block;font-size:12px;color:#ccc;margin-bottom:7px}h1,h2,h3,p{margin-top:0}.wrap{max-width:1500px;margin:auto;padding:0 26px}.header{border-bottom:1px solid var(--line)}.nav{height:88px;display:flex;justify-content:space-between;align-items:center;gap:16px}.brand{display:block;width:185px;max-width:48vw}.tag{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.18em}.intro{padding:44px 0 26px}.eyebrow{color:var(--orange);font-size:11px;letter-spacing:.2em;text-transform:uppercase;margin-bottom:12px}h1{font-size:clamp(30px,4.4vw,56px);line-height:1.1;letter-spacing:-.045em;margin-bottom:15px}.intro p:last-child{max-width:620px;color:var(--muted);font-size:15px}.tabs{display:flex;gap:6px;border-bottom:1px solid var(--line);margin-bottom:26px}.tabs button{background:none;border:0;border-radius:0;padding:14px 22px;color:var(--muted);border-bottom:2px solid transparent}.tabs button[aria-selected=true]{border-color:var(--orange);color:var(--ink)}[hidden]{display:none!important}.collection-bar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:22px;color:var(--muted);font-size:12px}.collection-bar input{max-width:300px}.gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:22px}.card{border:1px solid var(--line);border-radius:var(--round);overflow:hidden;min-width:0}.preview{display:flex;align-items:center;justify-content:center;width:100%;height:250px;background:#000;border:0;border-bottom:1px solid var(--line);border-radius:0;padding:12px}.preview img{width:100%;height:100%;object-fit:contain}.card-body{padding:17px}.card h2{font-size:15px;line-height:1.4;overflow-wrap:anywhere;margin-bottom:7px}.meta,.hint{font-size:12px;color:var(--muted)}.meta{margin-bottom:15px}.card-actions{display:flex;gap:8px}.card-actions>*{flex:1;font-size:12px;text-align:center}.link-button{border:1px solid var(--line);border-radius:9px;padding:9px 10px}.empty{padding:65px 24px;text-align:center;border:1px dashed var(--line);border-radius:var(--round);color:var(--muted)}.studio-top{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:20px}.studio-top h2{margin:0;font-size:19px}.expiry{font-size:12px;color:var(--muted)}.studio{display:grid;grid-template-columns:310px minmax(0,1fr);gap:22px;align-items:start}.panel{border:1px solid var(--line);border-radius:var(--round);background:var(--panel);padding:20px}.panel h3{font-size:14px;margin-bottom:15px}.step{display:inline-grid;place-items:center;width:23px;height:23px;border:1px solid #454545;border-radius:50%;margin-right:7px;font-size:11px;color:var(--orange)}.field{margin-bottom:15px}.panel+.panel{margin-top:17px}.inline{display:flex;gap:10px;align-items:center}.inline>*{min-width:0}.inline .field{flex:1}.full{width:100%}.source-actions{display:flex;gap:8px;margin-top:10px}.source-actions>*{flex:1}.file-label{border:1px dashed #444;border-radius:9px;padding:10px;font-size:12px;cursor:pointer;text-align:center;color:#eee;margin:0}.file-label:hover{border-color:var(--orange)}.file-label input{display:block;width:100%;font-size:11px;margin-top:7px}.source-note{font-size:11px;overflow-wrap:anywhere;color:var(--muted);margin:10px 0 0}.source-note a{color:var(--orange);text-decoration:underline}.check{display:flex;gap:8px;align-items:center}.align-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:12px 0}.align-grid button{font-size:11px;padding:6px;min-height:35px}.canvas-panel{padding:18px;position:sticky;top:16px}.canvas-toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:15px}.canvas-toolbar span{font-size:12px;color:var(--muted)}.stage{min-height:270px;display:flex;justify-content:center;align-items:center;border:1px solid var(--line);border-radius:10px;background:#000;overflow:hidden}.stage canvas{display:block;max-width:100%;width:auto;max-height:64vh;height:auto;touch-action:none;cursor:grab}.stage canvas:active{cursor:grabbing}.stage-empty{color:var(--muted);text-align:center;padding:65px 25px;max-width:420px}.stage-empty strong{display:block;color:var(--ink);font-size:19px;margin-bottom:10px}.canvas-bottom{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-top:16px}.canvas-bottom select{width:100px}.canvas-bottom .inline{flex-wrap:wrap}.status{font-size:12px;min-height:24px;margin:12px 0 0;color:var(--muted);overflow-wrap:anywhere}.status.error{color:#ffaaaa}.status.success{color:#9be7b0}.privacy{font-size:12px;color:var(--muted);margin:20px 0 0}.privacy strong{color:#ddd}.editor-options{margin-top:17px}.editor-options summary{cursor:pointer;font-weight:600;padding:6px 0}.editor-options .field:first-of-type{margin-top:15px}.small{font-size:11px}.help{margin-top:28px;border-top:1px solid var(--line);padding-top:20px;color:var(--muted);font-size:12px}.help summary{cursor:pointer;color:#ddd}footer{border-top:1px solid var(--line);margin-top:65px;padding:24px 0;color:var(--muted);font-size:12px}.viewer{width:calc(100vw - 28px);max-width:1400px;padding:20px;background:#000;border:1px solid #444;border-radius:16px;color:var(--ink);max-height:96dvh}.viewer::backdrop{background:rgba(0,0,0,.94)}.viewer-top,.viewer-bottom{display:flex;justify-content:space-between;align-items:center;gap:12px}.viewer-top h2{font-size:16px;overflow-wrap:anywhere;margin:0}.viewer-stage{margin:16px 0;overflow:auto;max-height:70dvh;text-align:center}.viewer-stage img{display:block;width:100%;height:65dvh;object-fit:contain}.viewer-stage.original img{width:auto;height:auto;max-width:none;max-height:none}.viewer-bottom{flex-wrap:wrap}.viewer-bottom .inline{flex-wrap:wrap}.viewer .meta{margin:0}.viewer button{font-size:12px}noscript p{padding:20px;background:#241406}.sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:980px){.studio{grid-template-columns:270px minmax(0,1fr)}.canvas-panel{padding:13px}.canvas-bottom{align-items:start;flex-direction:column}}@media(max-width:740px){.wrap{padding:0 16px}.nav{height:76px}.brand{width:160px}.tag{font-size:9px}.intro{padding-top:32px}.studio{display:flex;flex-direction:column}.studio>aside,.canvas-panel{width:100%}.canvas-panel{position:static;order:-1}.stage{min-height:200px}.stage canvas{max-height:55vh}.studio-top{align-items:start;flex-direction:column}.gallery{grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}.preview{height:290px}.collection-bar{align-items:start;flex-direction:column}.collection-bar input{max-width:none}.canvas-bottom{flex-direction:row;flex-wrap:wrap}.viewer-bottom{align-items:start;flex-direction:column}.tabs button{flex:1;padding:13px 8px}.panel{padding:17px}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}

.tabs{display:grid;grid-template-columns:1fr 1fr;gap:16px;border:0;margin:10px 0 32px}
.tab-card{display:flex;align-items:center;gap:15px;min-height:100px;padding:20px 22px;border:2px solid #383838;border-radius:15px;background:#000;transition:border-color .15s,background .15s}
.tab-card:hover{border-color:#999}.tab-card[aria-selected=true]{border-color:var(--orange);background:#140b04;box-shadow:inset 0 0 0 1px #ff842e22}
.tab-icon{display:grid;place-items:center;flex:none;width:43px;height:43px;border:1px solid #555;border-radius:11px;color:var(--orange);font-size:25px}
.tab-card strong{display:block;font-size:18px;line-height:1.4}.tab-card small{display:block;color:#bcbcbc;font-size:12px;margin-top:5px}.tab-arrow{margin-left:auto;color:var(--orange);font-size:24px}.tab-card em{display:inline-block;font-size:9px;font-weight:800;letter-spacing:.07em;font-style:normal;padding:3px 6px;border-radius:4px;background:var(--orange);color:#000;vertical-align:middle;margin-left:6px}
.create-tab{border-color:#8c4b21}.background-drop{display:flex;flex-direction:column;gap:8px;align-items:center;border:2px dashed #995723;padding:20px 12px;background:#000}.background-drop strong{font-size:16px;color:#fff}.background-drop span:not(.upload-icon),.background-drop small{color:#aaa;font-size:11px}.background-drop .upload-icon{font-size:26px;line-height:1;color:var(--orange)}.background-drop input{border:0!important;padding:6px!important;max-width:100%}.background-drop.drag-over{background:#201104;border-color:var(--orange)}
@media(max-width:740px){.tabs{gap:10px;margin-bottom:24px}.tab-card{padding:16px 12px;min-height:118px;gap:10px;align-items:flex-start}.tab-card strong{font-size:15px}.tab-card small{font-size:11px;line-height:1.5}.tab-icon{width:30px;height:30px;font-size:20px;border-radius:8px}.tab-arrow{display:none}.tab-card em{margin-left:0;margin-top:5px}}
@media(max-width:740px){.studio>aside{display:contents}.studio>aside>.panel{width:100%;margin:0}.studio>aside>.panel:nth-child(1){order:0}.canvas-panel{order:1}.studio>aside>.panel:nth-child(2){order:2}.studio>aside>.panel:nth-child(3){order:3}}
@media(max-width:460px){.tabs{grid-template-columns:1fr}.tab-card{min-height:85px;align-items:center}.tab-card strong{font-size:17px}.tab-card em{margin:0 0 0 6px}.tab-icon{width:38px;height:38px}.tab-arrow{display:block}}

/* Compact AMOLED layout and image-first collection. */
.intro{padding:30px 0 12px}.intro .eyebrow{display:none}h1{font-size:clamp(28px,4vw,44px)}
.tabs{gap:12px;margin:12px 0 26px}.tab-card{min-height:84px;padding:16px 20px}.tab-card strong{font-size:17px}.tab-card small{margin-top:2px}
.gallery{grid-template-columns:repeat(auto-fill,minmax(285px,1fr));gap:20px}.card{border-color:#252525;border-radius:20px;background:#050505;transition:transform .18s,border-color .18s}.card:hover{border-color:#9a5427;transform:translateY(-3px)}
.preview{height:auto;aspect-ratio:16/11;padding:0;background:radial-gradient(ellipse at center,#161616,#050505);overflow:hidden}.preview img{object-fit:contain;transition:transform .25s}.card:hover .preview img{transform:scale(1.025)}.card-body{padding:18px}.card h2{font-size:16px;font-weight:650;margin-bottom:4px}.card .meta{font-size:11px;margin-bottom:16px;color:#999}.card-actions{gap:10px}.card-actions button{background:transparent}.card-actions a{background:#ff842e;color:#090909;border-color:#ff842e;font-weight:700}.card-actions>*{border-radius:10px}
.help{margin-top:14px;padding-top:14px}.privacy{margin-top:12px}.align-grid{margin-bottom:0}.canvas-panel{padding:18px}.stage{border-style:dashed}.check{white-space:nowrap}.studio-top{margin-bottom:12px}
@media(max-width:740px){.gallery{grid-template-columns:repeat(auto-fill,minmax(260px,1fr))}.intro{padding-top:24px}.preview{height:auto}.tabs .tab-card{min-height:86px}.canvas-panel{position:static}}
@media(prefers-reduced-motion:reduce){.card,.preview img{transition:none}.card:hover,.card:hover .preview img{transform:none}}
</style>
</head>
<body>
<header class="header"><div class="wrap nav"><a href="<?= h($script) ?>" aria-label="HostingSpell Wallpaper Studio"><img class="brand" src="<?= h(BRAND_LOGO) ?>" alt="HostingSpell" width="185"></a><span class="tag">Wallpaper Studio</span></div></header>
<main class="wrap">
<section class="intro"><p class="eyebrow">Your screen. Your signature.</p><h1>Your wallpaper. Your signature.</h1><p>Free desktop &amp; mobile wallpapers. Add your logo. Make it yours.</p></section>
<nav class="tabs" role="tablist" aria-label="Wallpaper tools">
<a class="tab-card" href="?tab=collection" id="tab-collection" role="tab" aria-selected="<?= $initialTab === 'collection' ? 'true' : 'false' ?>" aria-controls="collection"><span class="tab-icon" aria-hidden="true">▦</span><span><strong>Our Collection</strong><small>Browse &amp; download HostingSpell wallpapers</small></span><span class="tab-arrow" aria-hidden="true">→</span></a>
<a class="tab-card create-tab" href="?tab=create" id="tab-create" role="tab" aria-selected="<?= $initialTab === 'create' ? 'true' : 'false' ?>" aria-controls="create"><span class="tab-icon" aria-hidden="true">✦</span><span><strong>Create a wallpaper <em>FREE TOOL</em></strong><small>Upload your photo, add a logo &amp; make it yours</small></span><span class="tab-arrow" aria-hidden="true">→</span></a>
</nav>
<noscript><p>The collection downloads work without JavaScript. Enable JavaScript to use the viewer and wallpaper editor.</p></noscript>
<section id="collection" role="tabpanel" aria-labelledby="tab-collection" <?= $initialTab !== 'collection' ? 'hidden' : '' ?>>
<div class="collection-bar"><span><?= count($items) ?> wallpapers</span><label class="sr" for="search">Search wallpapers</label><input id="search" type="search" maxlength="120" placeholder="Find a wallpaper…"></div>
<div class="gallery" id="gallery">
<?php foreach ($items as $index => $item): ?>
<article class="card" data-name="<?= h($item['name']) ?>">
<button class="preview" data-view="<?= $index ?>" aria-label="<?= h('View ' . $item['name']) ?>"><img src="<?= h('./walls/' . rawurlencode($item['file'])) ?>" alt="<?= h($item['name']) ?>" width="<?= $item['width'] ?>" height="<?= $item['height'] ?>" loading="lazy" decoding="async"></button>
<div class="card-body"><h2><?= h($item['name']) ?></h2><p class="meta"><?= $item['width'] ?> × <?= $item['height'] ?> px · <?= number_format($item['bytes'] / 1048576, 1) ?> MB</p><div class="card-actions"><button data-view="<?= $index ?>">Preview ↗</button><a class="link-button" href="?download=<?= h(rawurlencode($item['file'])) ?>">Download</a></div></div>
</article>
<?php endforeach; ?>
</div><p id="collection-empty" class="empty" <?= $items ? 'hidden' : '' ?>>No wallpapers here yet.</p>
</section>
<section id="create" role="tabpanel" aria-labelledby="tab-create" <?= $initialTab !== 'create' ? 'hidden' : '' ?>>
<div class="studio-top"><h2>Wallpaper maker</h2><span id="expiry" class="expiry">Private · 24 hours</span></div>
<div class="studio">
<aside>
<section class="panel"><h3><span class="step">1</span>Choose a wallpaper</h3>
<label class="file-label background-drop" id="background-drop"><span class="upload-icon" aria-hidden="true">↑</span><strong>Upload your wallpaper</strong><span>Drop an image here or choose a file</span><input id="background-file" type="file" accept="image/png,image/jpeg,image/webp"><small>PNG, JPG or WebP · Up to 25 MB · 24 megapixels</small></label>
<p id="background-note" class="source-note"></p>
</section>
<section class="panel"><h3><span class="step">2</span>Add your logo</h3>
<label class="file-label">Upload company logo<input id="logo-file" type="file" accept="image/png,image/jpeg,image/webp"></label>
<p class="hint" style="margin:10px 0">PNG, JPG, WebP · 4 MB / 4 MP</p>
<button id="remove-logo" class="full" style="margin-bottom:14px">Remove logo</button>
<div class="field"><label for="scale">Logo width <output id="scale-value">22%</output></label><input id="scale" type="range" min="2" max="100" value="22"></div>
<div class="field"><label for="opacity">Opacity <output id="opacity-value">100%</output></label><input id="opacity" type="range" min="5" max="100" value="100"></div>
<details class="editor-options"><summary>Quick placement</summary><div class="align-grid" id="alignment">
<button data-align="0,0" aria-label="Top left">↖</button><button data-align="0.5,0" aria-label="Top center">↑</button><button data-align="1,0" aria-label="Top right">↗</button>
<button data-align="0,0.5" aria-label="Middle left">←</button><button data-align="0.5,0.5" aria-label="Center">•</button><button data-align="1,0.5" aria-label="Middle right">→</button>
<button data-align="0,1" aria-label="Bottom left">↙</button><button data-align="0.5,1" aria-label="Bottom center">↓</button><button data-align="1,1" aria-label="Bottom right">↘</button></div></details>
</section>
<section class="panel"><h3><span class="step">3</span>Make it yours</h3>
<div class="field"><label for="preset">Canvas size</label><select id="preset"><option value="1920,1080">Desktop · 1920 × 1080</option><option value="2560,1440">QHD · 2560 × 1440</option><option value="3840,2160">4K · 3840 × 2160</option><option value="1080,1920">Mobile · 1080 × 1920</option><option value="1440,2560">Mobile QHD · 1440 × 2560</option><option value="2560,1080">Ultrawide · 2560 × 1080</option><option value="custom">Custom dimensions</option></select></div>
<div class="inline"><div class="field"><label for="width">Width (px)</label><input id="width" type="number" min="320" max="7680" value="1920" step="1"></div><div class="field"><label for="height">Height (px)</label><input id="height" type="number" min="320" max="7680" value="1080" step="1"></div></div>
<p class="hint">Up to 16 MP</p>
<div class="field"><label for="fit">Wallpaper fit</label><select id="fit"><option value="cover">Fill canvas (center crop)</option><option value="contain">Fit entire photo (black borders)</option></select></div>
<div class="inline"><label class="check"><input id="grid" type="checkbox">Show grid</label><label class="check"><input id="snap" type="checkbox">Snap to grid</label></div>
<div class="field"><label for="grid-mode">Grid layout</label><select id="grid-mode"><option value="pixels">Pixel spacing</option><option value="divisions">Rows &amp; columns</option></select></div>
<div id="pixel-grid" class="field"><label for="grid-size">Spacing (px)</label><input id="grid-size" type="number" min="8" max="256" step="1" value="64"></div>
<div id="division-grid" hidden><div class="inline"><div class="field"><label for="grid-rows">Rows</label><input id="grid-rows" type="number" min="1" max="24" value="3"></div><div class="field"><label for="grid-cols">Columns</label><input id="grid-cols" type="number" min="1" max="24" value="3"></div></div><div class="field"><label for="grid-sub">Subdivisions</label><select id="grid-sub"><option value="1">None</option><option value="2">2 × 2</option><option value="3">3 × 3</option><option value="4">4 × 4</option></select></div></div>
<button id="reset-layout" class="full">Reset logo position</button>
</section>
</aside>
<section class="panel canvas-panel" aria-label="Wallpaper editor">
<div class="canvas-toolbar"><strong>Live preview</strong><span id="dimensions">1920 × 1080</span></div>
<div class="stage"><div class="stage-empty" id="stage-empty"><strong>Start with a wallpaper.</strong>Drop in a photo, then add your logo.</div><canvas id="canvas" width="1920" height="1080" tabindex="0" role="img" aria-label="Wallpaper preview. Drag the logo or use arrow keys to move it." hidden></canvas></div>
<div class="canvas-bottom"><span class="hint">Drag to move · Corner to resize</span><div class="inline"><label for="format" class="sr">Export format</label><select id="format"><option value="png">PNG</option><option value="jpeg">JPG</option><option value="webp">WebP</option></select><button id="export" class="primary" disabled>Download wallpaper ↓</button></div></div>
<button id="retry-project" class="full" hidden>Retry opening workspace</button>
<p id="status" class="status" role="status" aria-live="polite">Open this tab to restore your temporary project.</p>
<p class="privacy">Private uploads · Auto-delete after 24 hours</p>
<div class="help"><details><summary>Privacy, limits and photo permissions</summary><p>A necessary HttpOnly cookie identifies your temporary workspace. There are no accounts or tracking cookies. Changing files replaces your previous uploads. Deleting cookies does not immediately delete server files; use the button below or wait for scheduled expiry cleanup.</p><p>Upload only photos and logos you have permission to use. Private uploads are never added to Our Collection.</p><p>Uploads: 20 per hour per network address. Temporary storage: 100 projects / 1 GB total. Shared networks may share limits.</p></details><button id="delete-project" class="danger" style="margin-top:18px">Delete my project</button></div>
</section>
</div>
</section>
</main>
<footer><div class="wrap">© <?= date('Y') ?> HostingSpell. A little magic for every screen.</div></footer>
<dialog id="viewer" class="viewer" aria-labelledby="viewer-title"><div class="viewer-top"><h2 id="viewer-title">Wallpaper</h2><button id="viewer-close" aria-label="Close wallpaper viewer">Close ✕</button></div><div id="viewer-stage" class="viewer-stage"><img id="viewer-image" alt=""></div><div class="viewer-bottom"><p class="meta" id="viewer-meta"></p><div class="inline"><button id="viewer-prev" aria-label="Previous wallpaper">← Previous</button><button id="viewer-next" aria-label="Next wallpaper">Next →</button><button id="viewer-fit">View original size</button><a id="viewer-download" class="link-button">Download original ↓</a></div></div></dialog>
<script id="studio-config" data-cfasync="false" type="application/json"><?= json_encode(['script' => $script, 'initialTab' => $initialTab, 'csrf' => csrf($session), 'expires' => $session['expires'], 'items' => $items], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script data-cfasync="false" nonce="<?= h($nonce) ?>" src="<?= h($script) ?>?action=app&amp;v=1.3.0" defer></script>
</body></html>
