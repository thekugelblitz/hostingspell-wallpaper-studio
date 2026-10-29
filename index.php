<?php
/** HostingSpell Wallpaper Studio — PHP 8.2+, cURL, Fileinfo, GD (PNG/JPEG/WebP).
 * Put gallery images in the child walls/ directory. See README.md before public deployment.
 * Cron: php /absolute/path/index.php --cleanup (every five minutes).
 */
declare(strict_types=1);
// Keep warnings and filesystem paths out of AJAX responses, even on development hosts.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
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
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); exit;
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
function photoID(string $url): string {
    if (strlen($url) > 1000 || preg_match('/[\x00-\x20\\\\]/', $url)) fail('Paste a valid Unsplash photo link.');
    $u = parse_url($url);
    if (!$u || strtolower($u['scheme'] ?? '') !== 'https' || !in_array(strtolower($u['host'] ?? ''), ['unsplash.com', 'www.unsplash.com'], true) || isset($u['port']) || isset($u['user']) || isset($u['pass'])) fail('Only HTTPS links on unsplash.com are allowed.');
    if (!preg_match('~^/(?:[a-z]{2}/)?photos/([A-Za-z0-9_-]+)(?:/download)?/?$~D', $u['path'] ?? '', $m)) fail('Use a photo page link, not a collection or search link.');
    // Current Unsplash IDs are 11 URL-safe characters; take the final 11, preserving hyphens inside IDs.
    $slug = $m[1]; $id = substr($slug, -11);
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/D', $id) || (strlen($slug) > 11 && substr($slug, -12, 1) !== '-')) fail('Could not find an 11-character Unsplash photo ID.');
    return $id;
}
function publicIPv4(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
    $n = unpack('N', inet_pton($ip))[1];
    foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $cidr) {
        [$network, $bits] = explode('/', $cidr); $mask = (0xffffffff << (32 - (int)$bits)) & 0xffffffff;
        if (($n & $mask) === (unpack('N', inet_pton($network))[1] & $mask)) return false;
    }
    return true;
}
function remoteURL(string $url): array {
    if (strlen($url) > 4096 || preg_match('/[\x00-\x20\\\\]/', $url)) fail('Unsplash returned an invalid redirect.', 502);
    $u = parse_url($url); $host = strtolower($u['host'] ?? '');
    if (!$u || ($u['scheme'] ?? '') !== 'https' || isset($u['port']) || isset($u['user']) || isset($u['pass']) || !in_array($host, ['unsplash.com', 'images.unsplash.com', 'plus.unsplash.com'], true)) fail('Unsplash redirected outside the permitted image hosts.', 502);
    // Resolve only IPv4, reject every non-public answer, and pin the selected address into cURL.
    $records = @dns_get_record($host, DNS_A); $ips = [];
    foreach ($records ?: [] as $r) {
        $ip = $r['ip'] ?? '';
        if (!publicIPv4($ip)) fail('Unsafe image host address.', 502);
        $ips[] = $ip;
    }
    if (!$ips) fail('Cannot resolve the Unsplash image server.', 502);
    return [$host, $ips[0]];
}
function fetchPhoto(string $id, string $tmp): void {
    if (!extension_loaded('curl')) fail('PHP cURL is required for Unsplash imports.', 503);
    $url = 'https://unsplash.com/photos/' . $id . '/download?force=true'; $deadline = microtime(true) + 30;
    for ($hop = 0; $hop < 5; $hop++) {
        [$host, $ip] = remoteURL($url); $remaining = (int)ceil($deadline - microtime(true));
        if ($remaining < 1) fail('Unsplash import timed out. Download the photo yourself and upload it instead.', 504);
        $out = fopen($tmp, 'wb'); $received = 0; $tooLarge = false; $headers = []; $headerBytes = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':443:' . $ip], CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(7, $remaining), CURLOPT_TIMEOUT => $remaining,
            CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 10,
            CURLOPT_USERAGENT => 'HostingSpellWallpaperStudio/2.0',
            CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/webp', 'Accept-Encoding: identity'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$headerBytes): int {
                $headerBytes += strlen($line); if ($headerBytes > 32768) return 0;
                if (str_starts_with($line, 'HTTP/')) $headers = [];
                $p = strpos($line, ':'); if ($p !== false) $headers[strtolower(substr($line, 0, $p))] = trim(substr($line, $p + 1));
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($out, &$received, &$tooLarge): int {
                $received += strlen($chunk); if ($received > MAX_BACKGROUND) { $tooLarge = true; return 0; }
                return fwrite($out, $chunk);
            }
        ]);
        try { $ok = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); }
        finally { curl_close($ch); fclose($out); }
        if ($tooLarge) fail('This Unsplash image exceeds the 25 MB import limit.');
        if ($ok === false) fail('Unsplash could not be reached. Download the photo yourself and use Upload wallpaper.', 502);
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $next = $headers['location'] ?? '';
            if (str_starts_with($next, '/') && !str_starts_with($next, '//')) $next = 'https://' . $host . $next;
            remoteURL($next); $url = $next; continue;
        }
        if ($status !== 200) fail('Unsplash did not provide a free image (HTTP ' . $status . '). Open its download link and upload the image instead.', 502);
        pictureInfo($tmp, MAX_BACKGROUND); return;
    }
    fail('Unsplash returned too many redirects.', 502);
}
function safeLayout(array $d): array {
    $out = [];
    foreach (['width' => [320, 7680, 1920], 'height' => [320, 7680, 1080], 'x' => [0, 1, .5], 'y' => [0, 1, .5], 'scale' => [.02, 1, .22], 'opacity' => [.05, 1, 1], 'gridSize' => [8, 256, 64]] as $k => [$min, $max, $default]) {
        $v = $d[$k] ?? $default;
        if (!is_numeric($v) || !is_finite((float)$v)) fail('Invalid editor settings.');
        $out[$k] = max($min, min($max, (float)$v));
    }
    $out['width'] = (int)$out['width']; $out['height'] = (int)$out['height'];
    if ($out['width'] * $out['height'] > 16000000) fail('Export is limited to 16 megapixels.');
    $out['fit'] = ($d['fit'] ?? '') === 'contain' ? 'contain' : 'cover';
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
let layout = {width:1920,height:1080,x:.5,y:.5,scale:.22,opacity:1,fit:'cover',grid:false,snap:false,gridSize:64};
let saveTimer = 0, pendingSave = false, drag = null;
const canvas = $('canvas'), ctx = canvas.getContext('2d', {alpha:false});
function status(message, type = '') { $('status').textContent = message; $('status').className = 'status ' + type; }
function setBusy(value) {
    $('retry-project').hidden = ready || value || expired;
    busy = value;
    ['import-button','background-file','logo-file','delete-project','remove-logo'].forEach(id => $(id).disabled = value || expired);
    $('export').disabled = value || expired || !background;
    $('retry-project').hidden = ready || value || expired;
}
async function request(action, body, isJSON = false) {
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 45000);
    try {
        const headers = {'X-CSRF-Token':BOOT.csrf}; if (isJSON) headers['Content-Type'] = 'application/json';
        const response = await fetch(endpoint(action), {method:'POST',headers,body,credentials:'same-origin',cache:'no-store',signal:controller.signal});
        let data; try { data = await response.json(); } catch { throw new Error('The server did not return JSON. Check PHP limits and server logs.'); }
        if (!response.ok || !data.ok) { if (response.status === 410) expire(); throw new Error(data.error || 'The request could not be completed.'); }
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
    if (data.background?.source) {
        const a = document.createElement('a'); a.href = data.background.source; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = ' · View photo on Unsplash ↗'; $('background-note').append(a);
    }
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
        for (let x=layout.gridSize;x<w;x+=layout.gridSize) {context.moveTo(x,0);context.lineTo(x,h);}
        for (let y=layout.gridSize;y<h;y+=layout.gridSize) {context.moveTo(0,y);context.lineTo(w,y);}
        context.stroke(); context.restore();
    }
    if (guides && logo) { const r=logoRect(); context.save(); context.strokeStyle='#ff842e'; context.lineWidth=Math.max(2,w/900); context.setLineDash([w/160,w/220]); context.strokeRect(r.x,r.y,r.w,r.h); context.restore(); }
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
    if (layout.snap) { x=Math.round(x/layout.gridSize)*layout.gridSize; y=Math.round(y/layout.gridSize)*layout.gridSize; }
    layout.x = clamp(x,r.w/2,layout.width-r.w/2)/layout.width;
    layout.y = clamp(y,r.h/2,layout.height-r.h/2)/layout.height;
    render();
}
function pointerPosition(e) { const r=canvas.getBoundingClientRect(); return {x:(e.clientX-r.left)*layout.width/r.width,y:(e.clientY-r.top)*layout.height/r.height}; }
canvas.addEventListener('pointerdown',e=>{
    if (!logo || busy || expired) return; const p=pointerPosition(e),r=logoRect();
    if(p.x<r.x||p.x>r.x+r.w||p.y<r.y||p.y>r.y+r.h) return;
    drag={id:e.pointerId,dx:p.x-r.x-r.w/2,dy:p.y-r.y-r.h/2}; canvas.setPointerCapture(e.pointerId); canvas.focus(); e.preventDefault();
});
canvas.addEventListener('pointermove',e=>{if(!drag||drag.id!==e.pointerId)return;const p=pointerPosition(e);moveLogo(p.x-drag.dx,p.y-drag.dy);});
function endDrag(e) { if(drag&&drag.id===e.pointerId){drag=null;scheduleSave();} }
canvas.addEventListener('pointerup',endDrag);canvas.addEventListener('pointercancel',endDrag);canvas.addEventListener('lostpointercapture',endDrag);
canvas.addEventListener('keydown',e=>{
    if(!logo||expired||busy||!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(e.key))return;
    e.preventDefault();const r=logoRect(),step=layout.snap?layout.gridSize:e.shiftKey?10:1;
    moveLogo(r.x+r.w/2+(e.key==='ArrowLeft'?-step:e.key==='ArrowRight'?step:0),r.y+r.h/2+(e.key==='ArrowUp'?-step:e.key==='ArrowDown'?step:0));scheduleSave();
});
$('alignment').addEventListener('click',e=>{const b=e.target.closest('[data-align]');if(!b||!logo||expired)return;const [x,y]=b.dataset.align.split(',').map(Number),r=logoRect();layout.x=(r.w/2+x*(layout.width-r.w))/layout.width;layout.y=(r.h/2+y*(layout.height-r.h))/layout.height;changed();});
$('scale').oninput=()=>{layout.scale=Number($('scale').value)/100;changed();};
$('opacity').oninput=()=>{layout.opacity=Number($('opacity').value)/100;changed();};
$('fit').onchange=()=>{layout.fit=$('fit').value;changed();};
$('grid').onchange=()=>{layout.grid=$('grid').checked;changed();};
$('snap').onchange=()=>{layout.snap=$('snap').checked;changed();};
$('grid-size').onchange=()=>{layout.gridSize=Math.round(clamp(Number($('grid-size').value)||64,8,256));changed();};
function resize(w,h) {
    if (!Number.isInteger(w)||!Number.isInteger(h)||w<320||h<320||w>7680||h>7680||w*h>16000000) {status('Choose 320–7680 px per side, with no more than 16 million pixels in total.','error');syncControls();return;}
    layout.width=w;layout.height=h;changed();
}
$('preset').onchange=()=>{if($('preset').value!=='custom')resize(...$('preset').value.split(',').map(Number));};
['width','height'].forEach(id=>$(id).onchange=()=>resize(Number($('width').value),Number($('height').value)));
$('reset-layout').onclick=()=>{layout.x=.5;layout.y=.5;layout.scale=.22;layout.opacity=1;changed();};
function downloadURL() {
    try {const u=new URL($('unsplash').value.trim());if(u.protocol!=='https:'||!['unsplash.com','www.unsplash.com'].includes(u.hostname)||u.port||u.username||u.password)return null;
        const match=u.pathname.match(/^\/(?:[a-z]{2}\/)?photos\/([A-Za-z0-9_-]+)(?:\/download)?\/?$/);if(!match)return null;
        const slug=match[1],id=slug.slice(-11);if(!/^[A-Za-z0-9_-]{11}$/.test(id)||(slug.length>11&&slug.slice(-12,-11)!=='-'))return null;
        return 'https://unsplash.com/photos/'+id+'/download?force=true';
    }catch{return null;}
}
$('unsplash').oninput=()=>{const url=downloadURL();$('unsplash-download').hidden=!url;if(url)$('unsplash-download').href=url;};
$('import-form').onsubmit=async e=>{
    e.preventDefault();if(busy||expired)return;if(!ready){await initialize();if(!ready)return;}
    if(!downloadURL()){status('Paste an HTTPS Unsplash photo page link.','error');return;}
    setBusy(true);status('Importing from Unsplash… This can take up to 30 seconds.');
    const form=new FormData();form.set('url',$('unsplash').value.trim());
    try{const data=await request('import',form);await applyProject(data.project);status('Wallpaper imported. Add your logo or start arranging it.','success');}
    catch(e){status(e.message,'error');}finally{setBusy(false);}
};
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
        if (!in_array($action, ['state', 'import', 'upload', 'save', 'clear', 'remove_logo'], true)) fail('Unknown action.', 404);
        if (!$session) fail('Your temporary project expired. Reload to start again.', 410);
        $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) fail('Cross-site request blocked.', 403);
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($provided) || !hash_equals(csrf($session), $provided)) fail('Security token expired. Reload the page.', 403);
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > MAX_BACKGROUND + 1048576) fail('Request exceeds the 26 MB limit.', 413);
        // One mutation at a time bounds network, memory and disk use on shared hosting.
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
            if (in_array($action, ['import', 'upload'], true)) {
                if ($bytes + MAX_BACKGROUND + MAX_LOGO > MAX_STORAGE) fail('Temporary storage is full. Try again after cleanup.', 507);
                if (disk_free_space($storage) < 100 * 1024 * 1024) fail('Temporary storage is low on disk space.', 507);
                $kind = $action === 'import' ? 'background' : input('kind', $_POST);
                if (!in_array($kind, ['background', 'logo'], true)) fail('Invalid image type.');
                $source = ''; $name = ''; $sourceURL = ''; $tmp = null;
                try {
                    if ($action === 'import') {
                        rate('imports:' . $ip, 6, 600); rate('imports-day:' . $ip, 20, 86400); rate('imports-global', 100, 3600);
                        $id = photoID(input('url', $_POST)); $tmp = tempnam(sys_get_temp_dir(), 'hswp_');
                        if ($tmp === false) fail('Cannot allocate temporary download.', 503);
                        chmod($tmp, 0600); fetchPhoto($id, $tmp); $source = $tmp;
                        $name = 'Unsplash ' . $id; $sourceURL = 'https://unsplash.com/photos/' . $id;
                    } else {
                        rate('uploads:' . $ip, 20, 3600);
                        $file = $_FILES['file'] ?? null;
                        if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) fail('Upload failed. Check the file and PHP upload limits.');
                        $source = $file['tmp_name']; $name = substr(pathinfo(basename(is_string($file['name']) ? $file['name'] : 'Image'), PATHINFO_FILENAME), 0, 160);
                    }
                    $asset = saveAsset($source, $dir . '/' . $kind . '.php', $kind === 'logo');
                    $asset['name'] = $name; $asset['source'] = $sourceURL;
                    $meta[$kind] = $asset; writeData($dir . '/state.php', $meta);
                } finally {
                    if (is_string($tmp) && is_file($tmp)) unlink($tmp);
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
    if ($code === 500) error_log('Wallpaper Studio: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $message . "\n"); exit(1); }
    if (input('action', $_GET) !== '') jsonOut(['ok' => false, 'error' => $message], $code);
    http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); exit($message);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#000000"><meta name="description" content="Download HostingSpell wallpapers or create your own branded wallpaper with a logo and an Unsplash photo.">
<title>Wallpaper Studio · HostingSpell</title>
<style nonce="<?= h($nonce) ?>">
:root{color-scheme:dark;--ink:#fafafa;--muted:#aaa;--line:#282828;--orange:#ff842e;--panel:#000;--round:15px}*{box-sizing:border-box}body{margin:0;background:#000;color:var(--ink);font:14px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}button,input,select{font:inherit}button,a,input,select{touch-action:manipulation}a{color:inherit;text-decoration:none}button{cursor:pointer}button:disabled{opacity:.45;cursor:wait}button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible,canvas:focus-visible{outline:2px solid var(--orange);outline-offset:4px}button,input,select{color:var(--ink)}button{border:1px solid var(--line);border-radius:9px;background:#111;min-height:41px;padding:8px 14px;font-weight:600}button:hover{border-color:#666}.primary{background:var(--orange);border-color:var(--orange);color:#000}.primary:hover{background:#ff9d58;border-color:#ff9d58}.danger{color:#ff9e9e}input:not([type=checkbox]):not([type=range]),select{width:100%;background:#000;border:1px solid #383838;border-radius:8px;padding:10px;min-width:0}input[type=range]{accent-color:var(--orange);width:100%;margin:8px 0}input[type=checkbox]{accent-color:var(--orange);width:17px;height:17px}label{display:block;font-size:12px;color:#ccc;margin-bottom:7px}h1,h2,h3,p{margin-top:0}.wrap{max-width:1500px;margin:auto;padding:0 26px}.header{border-bottom:1px solid var(--line)}.nav{height:88px;display:flex;justify-content:space-between;align-items:center;gap:16px}.brand{display:block;width:185px;max-width:48vw}.tag{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.18em}.intro{padding:44px 0 26px}.eyebrow{color:var(--orange);font-size:11px;letter-spacing:.2em;text-transform:uppercase;margin-bottom:12px}h1{font-size:clamp(30px,4.4vw,56px);line-height:1.1;letter-spacing:-.045em;margin-bottom:15px}.intro p:last-child{max-width:620px;color:var(--muted);font-size:15px}.tabs{display:flex;gap:6px;border-bottom:1px solid var(--line);margin-bottom:26px}.tabs button{background:none;border:0;border-radius:0;padding:14px 22px;color:var(--muted);border-bottom:2px solid transparent}.tabs button[aria-selected=true]{border-color:var(--orange);color:var(--ink)}[hidden]{display:none!important}.collection-bar{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:22px;color:var(--muted);font-size:12px}.collection-bar input{max-width:300px}.gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:22px}.card{border:1px solid var(--line);border-radius:var(--round);overflow:hidden;min-width:0}.preview{display:flex;align-items:center;justify-content:center;width:100%;height:250px;background:#000;border:0;border-bottom:1px solid var(--line);border-radius:0;padding:12px}.preview img{width:100%;height:100%;object-fit:contain}.card-body{padding:17px}.card h2{font-size:15px;line-height:1.4;overflow-wrap:anywhere;margin-bottom:7px}.meta,.hint{font-size:12px;color:var(--muted)}.meta{margin-bottom:15px}.card-actions{display:flex;gap:8px}.card-actions>*{flex:1;font-size:12px;text-align:center}.link-button{border:1px solid var(--line);border-radius:9px;padding:9px 10px}.empty{padding:65px 24px;text-align:center;border:1px dashed var(--line);border-radius:var(--round);color:var(--muted)}.studio-top{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:20px}.studio-top h2{margin:0;font-size:19px}.expiry{font-size:12px;color:var(--muted)}.studio{display:grid;grid-template-columns:310px minmax(0,1fr);gap:22px;align-items:start}.panel{border:1px solid var(--line);border-radius:var(--round);background:var(--panel);padding:20px}.panel h3{font-size:14px;margin-bottom:15px}.step{display:inline-grid;place-items:center;width:23px;height:23px;border:1px solid #454545;border-radius:50%;margin-right:7px;font-size:11px;color:var(--orange)}.field{margin-bottom:15px}.panel+.panel{margin-top:17px}.inline{display:flex;gap:10px;align-items:center}.inline>*{min-width:0}.inline .field{flex:1}.full{width:100%}.source-actions{display:flex;gap:8px;margin-top:10px}.source-actions>*{flex:1}.file-label{border:1px dashed #444;border-radius:9px;padding:10px;font-size:12px;cursor:pointer;text-align:center;color:#eee;margin:0}.file-label:hover{border-color:var(--orange)}.file-label input{display:block;width:100%;font-size:11px;margin-top:7px}.source-note{font-size:11px;overflow-wrap:anywhere;color:var(--muted);margin:10px 0 0}.source-note a{color:var(--orange);text-decoration:underline}.check{display:flex;gap:8px;align-items:center}.align-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:12px 0}.align-grid button{font-size:11px;padding:6px;min-height:35px}.canvas-panel{padding:18px;position:sticky;top:16px}.canvas-toolbar{display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:15px}.canvas-toolbar span{font-size:12px;color:var(--muted)}.stage{min-height:270px;display:flex;justify-content:center;align-items:center;border:1px solid var(--line);border-radius:10px;background:#000;overflow:hidden}.stage canvas{display:block;max-width:100%;width:auto;max-height:64vh;height:auto;touch-action:none;cursor:grab}.stage canvas:active{cursor:grabbing}.stage-empty{color:var(--muted);text-align:center;padding:65px 25px;max-width:420px}.stage-empty strong{display:block;color:var(--ink);font-size:19px;margin-bottom:10px}.canvas-bottom{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-top:16px}.canvas-bottom select{width:100px}.canvas-bottom .inline{flex-wrap:wrap}.status{font-size:12px;min-height:24px;margin:12px 0 0;color:var(--muted);overflow-wrap:anywhere}.status.error{color:#ffaaaa}.status.success{color:#9be7b0}.privacy{font-size:12px;color:var(--muted);margin:20px 0 0}.privacy strong{color:#ddd}.editor-options{margin-top:17px}.editor-options summary{cursor:pointer;font-weight:600;padding:6px 0}.editor-options .field:first-of-type{margin-top:15px}.small{font-size:11px}.help{margin-top:28px;border-top:1px solid var(--line);padding-top:20px;color:var(--muted);font-size:12px}.help summary{cursor:pointer;color:#ddd}footer{border-top:1px solid var(--line);margin-top:65px;padding:24px 0;color:var(--muted);font-size:12px}.viewer{width:calc(100vw - 28px);max-width:1400px;padding:20px;background:#000;border:1px solid #444;border-radius:16px;color:var(--ink);max-height:96dvh}.viewer::backdrop{background:rgba(0,0,0,.94)}.viewer-top,.viewer-bottom{display:flex;justify-content:space-between;align-items:center;gap:12px}.viewer-top h2{font-size:16px;overflow-wrap:anywhere;margin:0}.viewer-stage{margin:16px 0;overflow:auto;max-height:70dvh;text-align:center}.viewer-stage img{display:block;width:100%;height:65dvh;object-fit:contain}.viewer-stage.original img{width:auto;height:auto;max-width:none;max-height:none}.viewer-bottom{flex-wrap:wrap}.viewer-bottom .inline{flex-wrap:wrap}.viewer .meta{margin:0}.viewer button{font-size:12px}noscript p{padding:20px;background:#241406}.sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:980px){.studio{grid-template-columns:270px minmax(0,1fr)}.canvas-panel{padding:13px}.canvas-bottom{align-items:start;flex-direction:column}}@media(max-width:740px){.wrap{padding:0 16px}.nav{height:76px}.brand{width:160px}.tag{font-size:9px}.intro{padding-top:32px}.studio{display:flex;flex-direction:column}.studio>aside,.canvas-panel{width:100%}.canvas-panel{position:static;order:-1}.stage{min-height:200px}.stage canvas{max-height:55vh}.studio-top{align-items:start;flex-direction:column}.gallery{grid-template-columns:repeat(auto-fill,minmax(240px,1fr))}.preview{height:290px}.collection-bar{align-items:start;flex-direction:column}.collection-bar input{max-width:none}.canvas-bottom{flex-direction:row;flex-wrap:wrap}.viewer-bottom{align-items:start;flex-direction:column}.tabs button{flex:1;padding:13px 8px}.panel{padding:17px}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}

.tabs{display:grid;grid-template-columns:1fr 1fr;gap:16px;border:0;margin:10px 0 32px}
.tab-card{display:flex;align-items:center;gap:15px;min-height:100px;padding:20px 22px;border:2px solid #383838;border-radius:15px;background:#000;transition:border-color .15s,background .15s}
.tab-card:hover{border-color:#999}.tab-card[aria-selected=true]{border-color:var(--orange);background:#140b04;box-shadow:inset 0 0 0 1px #ff842e22}
.tab-icon{display:grid;place-items:center;flex:none;width:43px;height:43px;border:1px solid #555;border-radius:11px;color:var(--orange);font-size:25px}
.tab-card strong{display:block;font-size:18px;line-height:1.4}.tab-card small{display:block;color:#bcbcbc;font-size:12px;margin-top:5px}.tab-arrow{margin-left:auto;color:var(--orange);font-size:24px}.tab-card em{display:inline-block;font-size:9px;font-weight:800;letter-spacing:.07em;font-style:normal;padding:3px 6px;border-radius:4px;background:var(--orange);color:#000;vertical-align:middle;margin-left:6px}
.create-tab{border-color:#8c4b21}.background-drop{display:flex;flex-direction:column;gap:8px;align-items:center;border:2px dashed #995723;padding:20px 12px;background:#000}.background-drop strong{font-size:16px;color:#fff}.background-drop span:not(.upload-icon),.background-drop small{color:#aaa;font-size:11px}.background-drop .upload-icon{font-size:26px;line-height:1;color:var(--orange)}.background-drop input{border:0!important;padding:6px!important;max-width:100%}.background-drop.drag-over{background:#201104;border-color:var(--orange)}.unsplash-options{margin-top:17px;border-top:1px solid var(--line);padding-top:15px}.unsplash-options summary{cursor:pointer;font-size:12px;color:#ddd;margin-bottom:12px}
@media(max-width:740px){.tabs{gap:10px;margin-bottom:24px}.tab-card{padding:16px 12px;min-height:118px;gap:10px;align-items:flex-start}.tab-card strong{font-size:15px}.tab-card small{font-size:11px;line-height:1.5}.tab-icon{width:30px;height:30px;font-size:20px;border-radius:8px}.tab-arrow{display:none}.tab-card em{margin-left:0;margin-top:5px}}
@media(max-width:740px){.studio>aside{display:contents}.studio>aside>.panel{width:100%;margin:0}.studio>aside>.panel:nth-child(1){order:0}.canvas-panel{order:1}.studio>aside>.panel:nth-child(2){order:2}.studio>aside>.panel:nth-child(3){order:3}}
@media(max-width:460px){.tabs{grid-template-columns:1fr}.tab-card{min-height:85px;align-items:center}.tab-card strong{font-size:17px}.tab-card em{margin:0 0 0 6px}.tab-icon{width:38px;height:38px}.tab-arrow{display:block}}
</style>
</head>
<body>
<header class="header"><div class="wrap nav"><a href="<?= h($script) ?>" aria-label="HostingSpell Wallpaper Studio"><img class="brand" src="<?= h(BRAND_LOGO) ?>" alt="HostingSpell" width="185"></a><span class="tag">Wallpaper Studio</span></div></header>
<main class="wrap">
<section class="intro"><p class="eyebrow">Your screen. Your signature.</p><h1>A fresh spell for your screen.</h1><p>Explore our collection, or turn a wallpaper and your company logo into something that feels like you.</p></section>
<nav class="tabs" role="tablist" aria-label="Wallpaper tools">
<a class="tab-card" href="?tab=collection" id="tab-collection" role="tab" aria-selected="<?= $initialTab === 'collection' ? 'true' : 'false' ?>" aria-controls="collection"><span class="tab-icon" aria-hidden="true">▦</span><span><strong>Our Collection</strong><small>Browse &amp; download HostingSpell wallpapers</small></span><span class="tab-arrow" aria-hidden="true">→</span></a>
<a class="tab-card create-tab" href="?tab=create" id="tab-create" role="tab" aria-selected="<?= $initialTab === 'create' ? 'true' : 'false' ?>" aria-controls="create"><span class="tab-icon" aria-hidden="true">✦</span><span><strong>Create a wallpaper <em>FREE TOOL</em></strong><small>Upload your photo, add a logo &amp; make it yours</small></span><span class="tab-arrow" aria-hidden="true">→</span></a>
</nav>
<noscript><p>The collection downloads work without JavaScript. Enable JavaScript to use the viewer and wallpaper editor.</p></noscript>
<section id="collection" role="tabpanel" aria-labelledby="tab-collection" <?= $initialTab !== 'collection' ? 'hidden' : '' ?>>
<div class="collection-bar"><span><?= count($items) ?> wallpapers · Newest first · Original downloads</span><label class="sr" for="search">Search wallpapers</label><input id="search" type="search" maxlength="120" placeholder="Find a wallpaper…"></div>
<div class="gallery" id="gallery">
<?php foreach ($items as $index => $item): ?>
<article class="card" data-name="<?= h($item['name']) ?>">
<button class="preview" data-view="<?= $index ?>" aria-label="<?= h('View ' . $item['name']) ?>"><img src="<?= h('./walls/' . rawurlencode($item['file'])) ?>" alt="<?= h($item['name']) ?>" width="<?= $item['width'] ?>" height="<?= $item['height'] ?>" loading="lazy" decoding="async"></button>
<div class="card-body"><h2><?= h($item['name']) ?></h2><p class="meta"><?= $item['width'] ?> × <?= $item['height'] ?> px · <?= number_format($item['bytes'] / 1048576, 1) ?> MB</p><div class="card-actions"><button data-view="<?= $index ?>">View wallpaper</button><a class="link-button" href="?download=<?= h(rawurlencode($item['file'])) ?>">Download</a></div></div>
</article>
<?php endforeach; ?>
</div><p id="collection-empty" class="empty" <?= $items ? 'hidden' : '' ?>>No wallpapers here yet.</p>
</section>
<section id="create" role="tabpanel" aria-labelledby="tab-create" <?= $initialTab !== 'create' ? 'hidden' : '' ?>>
<div class="studio-top"><h2>Your private workspace</h2><span id="expiry" class="expiry">Temporary project · 24-hour lifetime</span></div>
<div class="studio">
<aside>
<section class="panel"><h3><span class="step">1</span>Choose a wallpaper</h3>
<label class="file-label background-drop" id="background-drop"><span class="upload-icon" aria-hidden="true">↑</span><strong>Upload your wallpaper</strong><span>Drop an image here or choose a file</span><input id="background-file" type="file" accept="image/png,image/jpeg,image/webp"><small>PNG, JPG or WebP · Up to 25 MB · 24 megapixels</small></label>
<p id="background-note" class="source-note">Your photo stays private and expires with this project.</p>
<details class="unsplash-options"><summary>Or import a free photo from Unsplash</summary>
<form id="import-form"><label for="unsplash">Unsplash photo link</label><input id="unsplash" type="url" maxlength="1000" placeholder="https://unsplash.com/photos/…" required><div class="source-actions"><button class="primary" type="submit" id="import-button">Import photo</button><a id="unsplash-download" class="link-button" href="https://unsplash.com" target="_blank" rel="noopener noreferrer" hidden>Open download ↗</a></div></form>
<p class="hint" style="margin:10px 0">Free photos only · Up to 25 MB / 24 MP</p>
</details>
</section>
<section class="panel"><h3><span class="step">2</span>Add your logo</h3>
<label class="file-label">Upload company logo<input id="logo-file" type="file" accept="image/png,image/jpeg,image/webp"></label>
<p class="hint" style="margin:10px 0">Transparent PNG recommended · Up to 4 MB / 4 MP. SVG is not accepted.</p>
<button id="remove-logo" class="full" style="margin-bottom:14px">Remove logo</button>
<div class="field"><label for="scale">Logo width <output id="scale-value">22%</output></label><input id="scale" type="range" min="2" max="100" value="22"></div>
<div class="field"><label for="opacity">Opacity <output id="opacity-value">100%</output></label><input id="opacity" type="range" min="5" max="100" value="100"></div>
<label>Quick placement</label><div class="align-grid" id="alignment">
<button data-align="0,0" aria-label="Top left">↖</button><button data-align="0.5,0" aria-label="Top center">↑</button><button data-align="1,0" aria-label="Top right">↗</button>
<button data-align="0,0.5" aria-label="Middle left">←</button><button data-align="0.5,0.5" aria-label="Center">•</button><button data-align="1,0.5" aria-label="Middle right">→</button>
<button data-align="0,1" aria-label="Bottom left">↙</button><button data-align="0.5,1" aria-label="Bottom center">↓</button><button data-align="1,1" aria-label="Bottom right">↘</button></div>
<p class="hint">Drag your logo, or focus the canvas and use arrow keys. Shift + arrow moves 10 pixels.</p>
</section>
<section class="panel"><h3><span class="step">3</span>Make it yours</h3>
<div class="field"><label for="preset">Canvas size</label><select id="preset"><option value="1920,1080">Desktop · 1920 × 1080</option><option value="2560,1440">QHD · 2560 × 1440</option><option value="3840,2160">4K · 3840 × 2160</option><option value="1080,1920">Mobile · 1080 × 1920</option><option value="1440,2560">Mobile QHD · 1440 × 2560</option><option value="2560,1080">Ultrawide · 2560 × 1080</option><option value="custom">Custom dimensions</option></select></div>
<div class="inline"><div class="field"><label for="width">Width (px)</label><input id="width" type="number" min="320" max="7680" value="1920" step="1"></div><div class="field"><label for="height">Height (px)</label><input id="height" type="number" min="320" max="7680" value="1080" step="1"></div></div>
<p class="hint">Maximum 16 MP export. Smaller canvases work better on phones.</p>
<div class="field"><label for="fit">Wallpaper fit</label><select id="fit"><option value="cover">Fill canvas (center crop)</option><option value="contain">Fit entire photo (black borders)</option></select></div>
<div class="inline"><label class="check"><input id="grid" type="checkbox">Show grid</label><label class="check"><input id="snap" type="checkbox">Snap to grid</label></div>
<div class="field"><label for="grid-size">Grid spacing (px)</label><input id="grid-size" type="number" min="8" max="256" step="1" value="64"></div>
<button id="reset-layout" class="full">Reset logo position</button>
</section>
</aside>
<section class="panel canvas-panel" aria-label="Wallpaper editor">
<div class="canvas-toolbar"><strong>Live preview</strong><span id="dimensions">1920 × 1080</span></div>
<div class="stage"><div class="stage-empty" id="stage-empty"><strong>Start with a wallpaper.</strong>Paste an Unsplash link or upload an image, then add your logo.</div><canvas id="canvas" width="1920" height="1080" tabindex="0" role="img" aria-label="Wallpaper preview. Drag the logo or use arrow keys to move it." hidden></canvas></div>
<div class="canvas-bottom"><span class="hint">Grid and selection guides are never exported.</span><div class="inline"><label for="format" class="sr">Export format</label><select id="format"><option value="png">PNG</option><option value="jpeg">JPG</option><option value="webp">WebP</option></select><button id="export" class="primary" disabled>Download wallpaper ↓</button></div></div>
<button id="retry-project" class="full" hidden>Retry opening workspace</button>
<p id="status" class="status" role="status" aria-live="polite">Open this tab to restore your temporary project.</p>
<p class="privacy"><strong>Private by default.</strong> One wallpaper and one logo per project. Assets expire after 24 hours. Download your finished wallpaper before the countdown ends. Exports are created in your browser.</p>
<div class="help"><details><summary>Privacy, limits and photo permissions</summary><p>A necessary HttpOnly cookie identifies your temporary workspace. There are no accounts or tracking cookies. Changing files replaces your previous uploads. Deleting cookies does not immediately delete server files; use the button below or wait for scheduled expiry cleanup.</p><p>Import only photos and logos you have permission to use. Unsplash imports use its public download link and can fail if the service blocks access. In that case, download the image from Unsplash and upload it here. Private imports are never added to Our Collection.</p><p>Import limits: 6 per 10 minutes and 20 per day per network address. Uploads: 20 per hour. Temporary storage: 100 projects / 1 GB total. Shared networks may share limits.</p></details><button id="delete-project" class="danger" style="margin-top:18px">Delete my project</button></div>
</section>
</div>
</section>
</main>
<footer><div class="wrap">© <?= date('Y') ?> HostingSpell. A little magic for every screen.</div></footer>
<dialog id="viewer" class="viewer" aria-labelledby="viewer-title"><div class="viewer-top"><h2 id="viewer-title">Wallpaper</h2><button id="viewer-close" aria-label="Close wallpaper viewer">Close ✕</button></div><div id="viewer-stage" class="viewer-stage"><img id="viewer-image" alt=""></div><div class="viewer-bottom"><p class="meta" id="viewer-meta"></p><div class="inline"><button id="viewer-prev" aria-label="Previous wallpaper">← Previous</button><button id="viewer-next" aria-label="Next wallpaper">Next →</button><button id="viewer-fit">View original size</button><a id="viewer-download" class="link-button">Download original ↓</a></div></div></dialog>
<script id="studio-config" data-cfasync="false" type="application/json"><?= json_encode(['script' => $script, 'initialTab' => $initialTab, 'csrf' => csrf($session), 'expires' => $session['expires'], 'items' => $items], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script data-cfasync="false" nonce="<?= h($nonce) ?>" src="<?= h($script) ?>?action=app&amp;v=1.1.0" defer></script>
</body></html>
