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
$apiRequest = PHP_SAPI !== 'cli' && isset($_GET['action']) && is_string($_GET['action']) && $_GET['action'] !== 'app' && $_GET['action'] !== 'asset' && $_GET['action'] !== 'thumbnail';
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
const SITE_URL = 'https://manage.hostingspell.com/tools/walls/index.php';
const REPOSITORY_URL = 'https://github.com/thekugelblitz/hostingspell-wallpaper-studio';
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
    $thumbs=$storage . '/thumbnails'; if(is_dir($thumbs)&&!is_link($thumbs))foreach(new DirectoryIterator($thumbs) as $f)if($f->isFile()&&!$f->isLink()&&$f->getMTime()<time()-604800)@unlink($f->getPathname());
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
    $out['filter'] = in_array($d['filter'] ?? '', ['none','grayscale','vintage','warm','cool'], true) ? $d['filter'] : 'none';
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
const SORT_OPTIONS = ['name-asc' => 'Name: A–Z', 'name-desc' => 'Name: Z–A', 'newest' => 'Newest first', 'oldest' => 'Oldest first', 'size-desc' => 'Largest file', 'size-asc' => 'Smallest file', 'resolution-desc' => 'Highest resolution', 'resolution-asc' => 'Lowest resolution'];
function collection(string $sort = 'name-asc'): array {
    $items = [];
    $collectionDir = __DIR__ . '/walls';
    if (!is_dir($collectionDir) || is_link($collectionDir)) return [];
    foreach (new DirectoryIterator($collectionDir) as $f) {
        if ($f->isDot() || $f->isLink() || !$f->isFile() || str_starts_with($f->getFilename(), '.') || !preg_match('/\.(png|jpe?g|webp|avif|gif|img)$/iD', $f->getFilename())) continue;
        $i = @getimagesize($f->getPathname());
        if (!$i || !in_array($i['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'], true)) continue;
        $items[] = ['file' => $f->getFilename(), 'name' => pathinfo($f->getFilename(), PATHINFO_FILENAME), 'width' => $i[0], 'height' => $i[1], 'mime' => $i['mime'], 'bytes' => $f->getSize(), 'time' => $f->getMTime()];
    }
    usort($items, static function ($a, $b) use ($sort): int {
        $name = strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['file'], $b['file']);
        $pixelsA = $a['width'] * $a['height']; $pixelsB = $b['width'] * $b['height'];
        return (match ($sort) {
            'name-desc' => -$name,
            'newest' => $b['time'] <=> $a['time'],
            'oldest' => $a['time'] <=> $b['time'],
            'size-desc' => $b['bytes'] <=> $a['bytes'],
            'size-asc' => $a['bytes'] <=> $b['bytes'],
            'resolution-desc' => $pixelsB <=> $pixelsA,
            'resolution-asc' => $pixelsA <=> $pixelsB,
            default => $name,
        }) ?: $name;
    });
    return $items;
}

function applicationJS(): string {
    return <<<'WALLPAPER_APP_JS'
(() => {
'use strict';
const $=id=>document.getElementById(id),BOOT=JSON.parse($('studio-config').textContent);
const clamp=(n,a,b)=>Math.max(a,Math.min(b,n)), clone=o=>JSON.parse(JSON.stringify(o));
const LIMIT=16000000,MAX_LAYERS=40,MAX_BYTES=32*1048576;
let doc={width:1920,height:1080,fit:'cover',filter:'none',strength:100,brightness:100,contrast:100,saturation:100,grid:false,snap:false,smart:true,gridMode:'pixels',gridSize:64,gridRows:3,gridCols:3,gridSub:1,layers:[],background:null,variants:[]};
let selected=null,images=new Map(),assets=new Map(),undo=[],redo=[],busy=false,gesture=null,zoom=1,preview='none',guides=[],draftTimer,dirty=false;
const canvas=$('canvas'),ctx=canvas.getContext('2d',{alpha:false}),stage=$('stage');
const uid=()=>crypto.randomUUID(), current=()=>doc.layers.find(l=>l.id===selected);
function status(s,error=false){$('status').textContent=s;$('status').className='status'+(error?' error':'');}
function checkpoint(){undo.push(clone(doc));if(undo.length>40)undo.shift();redo=[];dirty=true;}
function commit(){render();controls();scheduleDraft();}
function historyMove(from,to){if(busy||!from.length)return;to.push(clone(doc));doc=from.pop();if(!current())selected=null;commit();}
function blobDownload(blob,name){const u=URL.createObjectURL(blob),a=document.createElement('a');a.href=u;a.download=name;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(u),30000);}
async function decode(blob){const u=URL.createObjectURL(blob);try{const im=new Image();im.src=u;await im.decode();return im;}finally{URL.revokeObjectURL(u);}}
async function importImage(file,kind){if(busy)return;busy=true;controls();try{
 if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>MAX_BYTES)throw Error('Choose a PNG, JPG or WebP up to 32 MB.');
 status('Opening image…');let im=await decode(file);if(im.naturalWidth>16000||im.naturalHeight>16000||im.naturalWidth*im.naturalHeight>64000000)throw Error('Choose an image under 64 megapixels and 16,000 px per side.');
 let blob=file,resized=false;const budget=kind==='background'?24000000:4000000;
 if(im.naturalWidth*im.naturalHeight>budget){const ratio=Math.sqrt(budget/(im.naturalWidth*im.naturalHeight)),c=document.createElement('canvas');c.width=Math.floor(im.naturalWidth*ratio);c.height=Math.floor(im.naturalHeight*ratio);c.getContext('2d').drawImage(im,0,0,c.width,c.height);resized=true;blob=await toBlob(c,'image/png');im=await decode(blob);c.width=c.height=1;status('Large image resized to fit the editing memory limit.');}
 if(kind!=='background'&&doc.layers.length>=MAX_LAYERS)throw Error('This project supports up to 40 layers.');
 const total=[...images.values()].reduce((n,i)=>n+i.naturalWidth*i.naturalHeight,0);if(total+im.naturalWidth*im.naturalHeight>64000000)throw Error('This editing session has reached its image memory budget. Save the project and reload before adding more images.');
 const id=uid();checkpoint();images.set(id,im);assets.set(id,blob);
 if(kind==='background')doc.background=id;else{if(doc.layers.length>=MAX_LAYERS)throw Error('This project supports up to 40 layers.');const w=Math.min(.22,.9*doc.height/doc.width*im.naturalWidth/im.naturalHeight),h=w*doc.width/doc.height*im.naturalHeight/im.naturalWidth;const l=baseLayer('image',file.name||'Logo');Object.assign(l,{asset:id,w,h:Math.min(.9,h)});doc.layers.push(l);selected=l.id;}
 commit();status((kind==='background'?'Wallpaper ready.':'Logo ready.')+(resized?' Large image resized to fit the memory limit.':''));
 }catch(e){status(e.message,true);}finally{busy=false;controls();}}
function baseLayer(type,name){return{id:uid(),type,name,x:.5,y:.5,w:.25,h:.25,rotation:0,opacity:1,hidden:false,locked:false,fill:($('brand-colour')?.value||'#ff842e'),stroke:'#ffffff',strokeWidth:0,hollow:false,radius:0,sides:6,points:5,inner:.45,shadow:0,outline:0,plate:false,text:'Your text',font:'system-ui',fontSize:72,bold:true,align:'center'};}
function addLayer(type){if(!doc.background)return status('Choose a wallpaper first.',true);if(doc.layers.length>=MAX_LAYERS)return status('This project supports up to 40 layers.',true);checkpoint();const l=baseLayer(type,type==='text'?'Text':$('shape-type').value);if(type==='text'){l.fill='#ffffff';l.h=.12;l.w=.4;}if(type==='shape'){l.shape=$('shape-type').value;if(l.shape==='rounded')l.radius=15;if(l.shape==='square'||l.shape==='circle')l.h=l.w*doc.width/doc.height;}doc.layers.push(l);selected=l.id;commit();}
function rect(l){return{x:l.x*doc.width,y:l.y*doc.height,w:l.w*doc.width,h:l.h*doc.height};}
function filterValue(){const s=doc.strength/100;return({none:'',grayscale:`grayscale(${s})`,vintage:`sepia(${s*.8})`,warm:`sepia(${s*.35}) saturate(${1+s*.25})`,cool:`hue-rotate(${s*20}deg) saturate(${1-s*.15})`,noir:`grayscale(${s}) contrast(${1+s*.5})`,fade:`contrast(${1-s*.2}) brightness(${1+s*.1})`})[doc.filter]+` brightness(${doc.brightness}%) contrast(${doc.contrast}%) saturate(${doc.saturation}%)`;}
function path(c,l,w,h){c.beginPath();if(l.shape==='circle'||l.shape==='ellipse'){c.ellipse(0,0,w/2,h/2,0,0,Math.PI*2);return;}
 if(['rectangle','square','rounded'].includes(l.shape)){c.roundRect(-w/2,-h/2,w,h,Math.min(w,h)*l.radius/100);return;}
 if(l.shape==='line'){c.moveTo(-w/2,0);c.lineTo(w/2,0);return;}
 if(l.shape==='arrow'){c.moveTo(-w/2,-h/6);c.lineTo(w/8,-h/6);c.lineTo(w/8,-h/2);c.lineTo(w/2,0);c.lineTo(w/8,h/2);c.lineTo(w/8,h/6);c.lineTo(-w/2,h/6);c.closePath();return;}
 const sides=l.shape==='triangle'?3:l.shape==='hexagon'?6:l.shape==='pentagon'?5:l.shape==='diamond'?4:l.shape==='star'?l.points*2:l.sides;
 for(let i=0;i<sides;i++){const a=-Math.PI/2+i*Math.PI*2/sides,r=l.shape==='star'&&i%2?l.inner:1;const x=Math.cos(a)*w/2*r,y=Math.sin(a)*h/2*r;i?c.lineTo(x,y):c.moveTo(x,y);}c.closePath();}
function paint(c,selection=false){const w=doc.width,h=doc.height;c.save();c.fillStyle='#000';c.fillRect(0,0,w,h);const bg=images.get(doc.background);if(bg){const ratio=doc.fit==='contain'?Math.min(w/bg.naturalWidth,h/bg.naturalHeight):Math.max(w/bg.naturalWidth,h/bg.naturalHeight);c.save();c.filter=filterValue();c.drawImage(bg,(w-bg.naturalWidth*ratio)/2,(h-bg.naturalHeight*ratio)/2,bg.naturalWidth*ratio,bg.naturalHeight*ratio);c.restore();}
 for(const l of doc.layers){if(l.hidden)continue;const r=rect(l);c.save();c.translate(r.x,r.y);c.rotate(l.rotation*Math.PI/180);c.globalAlpha=l.opacity;c.fillStyle=l.fill;c.strokeStyle=l.stroke;c.lineWidth=l.strokeWidth;c.lineJoin='round';if(l.shadow){c.shadowColor='rgba(0,0,0,.8)';c.shadowBlur=l.shadow;c.shadowOffsetY=l.shadow/4;}
 if(l.type==='image'){const im=images.get(l.asset);if(im){if(l.plate){c.save();c.fillStyle='rgba(0,0,0,.65)';c.fillRect(-r.w/2-16,-r.h/2-16,r.w+32,r.h+32);c.restore();}if(l.outline){c.save();c.filter='brightness(0) invert(1)';for(const [x,y] of [[-1,0],[1,0],[0,-1],[0,1]])c.drawImage(im,-r.w/2+x*l.outline,-r.h/2+y*l.outline,r.w,r.h);c.restore();}c.drawImage(im,-r.w/2,-r.h/2,r.w,r.h);}}
 else if(l.type==='text'){c.font=`${l.bold?'700':'400'} ${l.fontSize}px ${l.font}`;c.textAlign=l.align;c.textBaseline='middle';const lines=l.text.split('\n');lines.forEach((t,i)=>{const y=(i-(lines.length-1)/2)*l.fontSize*1.2;const x=l.align==='left'?-r.w/2:l.align==='right'?r.w/2:0;if(l.strokeWidth)c.strokeText(t,x,y,r.w);c.fillText(t,x,y,r.w);});}
 else{path(c,l,r.w,r.h);if(!l.hollow&&l.shape!=='line')c.fill();if(l.strokeWidth||l.hollow||l.shape==='line'){c.lineWidth=Math.max(1,l.strokeWidth);c.stroke();}}c.restore();}
 if(selection){if(doc.grid){const st=steps();c.save();c.lineWidth=Math.max(1,w/1800);for(let x=st.x;x<w;x+=st.x){c.strokeStyle=doc.gridMode==='divisions'&&Math.round(x/st.x)%doc.gridSub===0?'#ff842e66':'#ffffff33';c.beginPath();c.moveTo(x,0);c.lineTo(x,h);c.stroke();}for(let y=st.y;y<h;y+=st.y){c.beginPath();c.moveTo(0,y);c.lineTo(w,y);c.stroke();}c.restore();}
 const l=current();if(l&&!l.hidden){const r=rect(l),hs=handleSize();c.save();c.translate(r.x,r.y);c.rotate(l.rotation*Math.PI/180);c.strokeStyle='#ff842e';c.lineWidth=Math.max(2,w/800);c.strokeRect(-r.w/2,-r.h/2,r.w,r.h);c.fillStyle='#ff842e';for(const x of [-1,1])for(const y of [-1,1])c.fillRect(x*r.w/2-hs/2,y*r.h/2-hs/2,hs,hs);c.restore();}
 c.save();c.strokeStyle='#38d9ff';c.lineWidth=Math.max(2,w/900);for(const g of guides){c.beginPath();if(g.axis==='x'){c.moveTo(g.v,0);c.lineTo(g.v,h);}else{c.moveTo(0,g.v);c.lineTo(w,g.v);}c.stroke();}c.restore();
 if(preview!=='none'){c.save();c.fillStyle='#ffffff';if(preview==='phone'){c.font=`600 ${w*.09}px system-ui`;c.textAlign='center';c.fillText('09:41',w/2,h*.16);c.font=`${w*.025}px system-ui`;c.fillText('Monday, 29 October',w/2,h*.2);c.fillStyle='#0008';c.fillRect(w*.3,h*.96,w*.4,h*.008);}else{c.fillStyle='#111b';c.fillRect(0,h*.95,w,h*.05);c.fillStyle='#fff';c.font=`${h*.02}px system-ui`;c.fillText('▦  Search',w*.02,h*.982);for(let i=0;i<4;i++){c.fillStyle='#ffffff99';c.fillRect(w*.025,h*(.05+i*.12),w*.025,w*.025);}}c.restore();}}
 c.restore();}
function handleSize(){return Math.max(8,12*doc.width/Math.max(100,canvas.getBoundingClientRect().width));}
function render(){canvas.hidden=!doc.background;$('stage-empty').hidden=!!doc.background;if(canvas.width!==doc.width||canvas.height!==doc.height){canvas.width=doc.width;canvas.height=doc.height;}canvas.style.transform=`scale(${zoom})`;if(![...$('zoom').options].some(o=>Number(o.value)===zoom)){let live=$('zoom-live');if(!live){live=document.createElement('option');live.id='zoom-live';$('zoom').append(live);}live.value=String(zoom);live.textContent=Math.round(zoom*100)+'%';}$('zoom').value=String(zoom);canvas.style.transformOrigin='center';paint(ctx,true);$('dimensions').textContent=`${doc.width} × ${doc.height}`;}
function steps(){return doc.gridMode==='divisions'?{x:doc.width/(doc.gridCols*doc.gridSub),y:doc.height/(doc.gridRows*doc.gridSub)}:{x:doc.gridSize,y:doc.gridSize};}
function localPoint(p,l){const r=rect(l),a=-l.rotation*Math.PI/180,dx=p.x-r.x,dy=p.y-r.y;return{x:dx*Math.cos(a)-dy*Math.sin(a),y:dx*Math.sin(a)+dy*Math.cos(a)};}
function pos(e){const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*doc.width/r.width,y:(e.clientY-r.top)*doc.height/r.height};}
const pointers=new Map();
canvas.addEventListener('pointerdown',e=>{if(busy||!doc.background)return;const p=pos(e);pointers.set(e.pointerId,{x:e.clientX,y:e.clientY});canvas.setPointerCapture(e.pointerId);if(pointers.size===2){gesture={pinch:true,distance:distance(),zoom};return;}const l=current();if(l&&!l.locked&&!l.hidden){const q=localPoint(p,l),r=rect(l),hs=handleSize()*2;for(const sx of [-1,1])for(const sy of [-1,1])if(Math.abs(q.x-sx*r.w/2)<hs&&Math.abs(q.y-sy*r.h/2)<hs){checkpoint();gesture={id:e.pointerId,resize:true,layer:l.id,start:p,original:clone(l),sx,sy};canvas.focus();e.preventDefault();return;}}
 const hit=[...doc.layers].reverse().find(l=>{if(l.hidden||l.locked)return false;const q=localPoint(p,l),r=rect(l);return Math.abs(q.x)<=r.w/2&&Math.abs(q.y)<=r.h/2;});selected=hit?.id||null;if(hit){checkpoint();gesture={id:e.pointerId,start:p,original:clone(hit),layer:hit.id};}commit();canvas.focus();e.preventDefault();});
function distance(){const a=[...pointers.values()];return a.length===2?Math.hypot(a[0].x-a[1].x,a[0].y-a[1].y):1;}
canvas.addEventListener('pointermove',e=>{if(pointers.has(e.pointerId))pointers.set(e.pointerId,{x:e.clientX,y:e.clientY});if(gesture?.pinch){zoom=clamp(gesture.zoom*distance()/gesture.distance,.5,3);render();return;}if(!gesture||gesture.id!==e.pointerId)return;const l=current();if(!l)return;const p=pos(e),o=gesture.original;if(gesture.resize){const q=localPoint(p,o),r=rect(o);let w=clamp(r.w+(q.x-gesture.sx*r.w/2)*gesture.sx,20,doc.width*2),h=clamp(r.h+(q.y-gesture.sy*r.h/2)*gesture.sy,20,doc.height*2);if(l.type==='image'||['circle','square'].includes(l.shape)||e.shiftKey)h=w*r.h/r.w;l.w=w/doc.width;l.h=h/doc.height;}else{let x=o.x*doc.width+p.x-gesture.start.x,y=o.y*doc.height+p.y-gesture.start.y;guides=[];if(doc.snap){const s=steps();x=Math.round(x/s.x)*s.x;y=Math.round(y/s.y)*s.y;}if(doc.smart){const r=rect(l),threshold=10*doc.width/canvas.getBoundingClientRect().width;for(const [axis,v,size] of [['x',x,r.w],['y',y,r.h]]){const extent=axis==='x'?doc.width:doc.height;const targets=[0,extent/2,extent,extent*.05,extent*.95];for(const other of doc.layers){if(other.id===l.id||other.hidden)continue;const t=rect(other),c=axis==='x'?t.x:t.y,sz=axis==='x'?t.w:t.h;targets.push(c-sz/2,c,c+sz/2);}let best=threshold,delta=0,target=null;for(const t of targets)for(const offset of [-size/2,0,size/2]){const d=t-(v+offset);if(Math.abs(d)<best){best=Math.abs(d);delta=d;target=t;}}if(target!==null){if(axis==='x')x+=delta;else y+=delta;guides.push({axis,v:target});}}}l.x=clamp(x/doc.width,-1,2);l.y=clamp(y/doc.height,-1,2);}render();properties();});
function end(e){pointers.delete(e.pointerId);if(gesture){gesture=null;guides=[];commit();}}
canvas.addEventListener('pointerup',end);canvas.addEventListener('pointercancel',end);canvas.addEventListener('lostpointercapture',end);
function controls(){const l=current();$('tools-panel').inert=busy;$('editor-toolbar').inert=busy;$('undo').disabled=!undo.length||busy;$('redo').disabled=!redo.length||busy;$('export').disabled=!doc.background||busy;$('save-project').disabled=!doc.background||busy;$('add-shape').disabled=$('add-text').disabled=!doc.background||busy;
 $('width').value=doc.width;$('height').value=doc.height;const presetKey=doc.width+','+doc.height;$('preset').value=[...$('preset').options].some(o=>o.value===presetKey)?presetKey:'custom';$('fit').value=doc.fit;for(const key of ['filter','strength','brightness','contrast','saturation','gridMode','gridSize','gridRows','gridCols','gridSub'])$(key).value=doc[key];for(const key of ['grid','snap','smart'])$(key).checked=doc[key];$('pixel-grid').hidden=doc.gridMode!=='pixels';$('division-grid').hidden=doc.gridMode!=='divisions';$('object-properties').hidden=!l;$('layers').replaceChildren();for(const layer of [...doc.layers].reverse()){const row=document.createElement('div');row.className='layer-row';const b=document.createElement('button');b.textContent=(layer.hidden?'◌ ':layer.locked?'🔒 ':'')+layer.name;b.setAttribute('aria-pressed',String(layer.id===selected));b.onclick=()=>{selected=layer.id;commit();};row.append(b);$('layers').append(row);}properties();}
function properties(){const l=current();if(!l)return;$('object-name').value=l.name;for(const key of ['x','y','w','h','opacity'])$('object-'+key).value=Math.round(l[key]*10000)/100;for(const key of ['rotation','fill','stroke','strokeWidth','radius','sides','points','inner','shadow','outline','text','font','fontSize','align'])$('object-'+key).value=l[key];for(const key of ['hollow','locked','hidden','bold','plate'])$('object-'+key).checked=l[key];document.querySelectorAll('[data-kind]').forEach(el=>el.hidden=!el.dataset.kind.split(' ').includes(l.type));}
function editObject(key,value){const l=current();if(!l||(l.locked&&!['locked','hidden'].includes(key)))return;if(['sides','points','fontSize','strokeWidth','radius','shadow','outline'].includes(key))value=Math.round(value);checkpoint();l[key]=value;commit();}
$('object-properties').addEventListener('change',e=>{const key=e.target.dataset.prop;if(!key||!current())return;let v=e.target.type==='checkbox'?e.target.checked:e.target.value;if(e.target.type==='number'||e.target.type==='range'){v=Number(v);if(!Number.isFinite(v))return;v=clamp(v,Number(e.target.min),Number(e.target.max));if(['x','y','w','h','opacity'].includes(key))v/=100;}editObject(key,v);});
$('object-name').onchange=()=>editObject('name',$('object-name').value.slice(0,80));
$('undo').onclick=()=>historyMove(undo,redo);$('redo').onclick=()=>historyMove(redo,undo);
function layerAction(action){const l=current();if(!l||busy)return;checkpoint();const i=doc.layers.indexOf(l);if(action==='delete'){doc.layers.splice(i,1);selected=null;}if(action==='duplicate'){if(doc.layers.length>=MAX_LAYERS){undo.pop();return status('Layer limit reached.',true);}const n=clone(l);n.id=uid();n.x+=.02;n.y+=.02;n.name+=' copy';doc.layers.splice(i+1,0,n);selected=n.id;}if(action==='forward'&&i<doc.layers.length-1)[doc.layers[i],doc.layers[i+1]]=[doc.layers[i+1],doc.layers[i]];if(action==='backward'&&i>0)[doc.layers[i],doc.layers[i-1]]=[doc.layers[i-1],doc.layers[i]];commit();}
for(const a of ['delete','duplicate','forward','backward'])$('layer-'+a).onclick=()=>layerAction(a);
$('add-shape').onclick=()=>addLayer('shape');$('add-text').onclick=()=>addLayer('text');
$('alignment').onclick=e=>{const b=e.target.closest('[data-align]'),l=current();if(!b||!l||l.locked)return;checkpoint();const [x,y]=b.dataset.align.split(',').map(Number);l.x=l.w/2+x*(1-l.w);l.y=l.h/2+y*(1-l.h);commit();};
for(const key of ['fit','filter','strength','brightness','contrast','saturation','gridMode','gridSize','gridRows','gridCols','gridSub','grid','snap','smart'])$(key).onchange=()=>{checkpoint();doc[key]=$(key).type==='checkbox'?$(key).checked:['fit','filter','gridMode'].includes(key)?$(key).value:clamp(Number($(key).value)||1,Number($(key).min)||1,Number($(key).max)||256);if(['gridSize','gridRows','gridCols','gridSub'].includes(key))doc[key]=Math.round(doc[key]);commit();};
function resize(w,h){if(!Number.isInteger(w)||!Number.isInteger(h)||w<320||h<320||w>7680||h>7680||w*h>LIMIT){controls();return status('Use 320–7680 px per side, up to 16 MP.',true);}checkpoint();doc.width=w;doc.height=h;commit();}
$('preset').onchange=()=>{if($('preset').value!=='custom')resize(...$('preset').value.split(',').map(Number));};for(const k of ['width','height'])$(k).onchange=()=>resize(Number($('width').value),Number($('height').value));
$('zoom').onchange=()=>{zoom=Number($('zoom').value);render();};$('device-preview').onchange=()=>{preview=$('device-preview').value;render();};
$('background-file').onchange=e=>{if(e.target.files[0])importImage(e.target.files[0],'background');e.target.value='';};$('logo-file').onchange=e=>{if(e.target.files[0])importImage(e.target.files[0],'image');e.target.value='';};
for(const area of [$('background-drop'),stage]){area.addEventListener('dragover',e=>{e.preventDefault();area.classList.add('drag-over');});area.addEventListener('dragleave',()=>area.classList.remove('drag-over'));area.addEventListener('drop',e=>{e.preventDefault();area.classList.remove('drag-over');if(e.dataTransfer.files.length!==1)return status('Drop one image at a time.',true);importImage(e.dataTransfer.files[0],area===stage&&doc.background?'image':'background');});}
document.addEventListener('paste',e=>{if($('create').hidden||/INPUT|TEXTAREA/.test(e.target.tagName))return;const f=[...e.clipboardData.items].find(i=>i.kind==='file'&&i.type.startsWith('image/'))?.getAsFile();if(f){e.preventDefault();importImage(f,doc.background?'image':'background');}});
document.addEventListener('keydown',e=>{if($('create').hidden||/INPUT|TEXTAREA|SELECT/.test(e.target.tagName))return;if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='y'){e.preventDefault();historyMove(redo,undo);return;}if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='z'){e.preventDefault();e.shiftKey?historyMove(redo,undo):historyMove(undo,redo);return;}const l=current();if(!l||l.locked||busy)return;if(e.key==='Delete'||e.key==='Backspace'){e.preventDefault();layerAction('delete');}if(e.key.startsWith('Arrow')){e.preventDefault();checkpoint();const st=e.shiftKey?10:1,s=steps();l.x+=(e.key==='ArrowRight'?1:e.key==='ArrowLeft'?-1:0)*(doc.snap?s.x:st)/doc.width;l.y+=(e.key==='ArrowDown'?1:e.key==='ArrowUp'?-1:0)*(doc.snap?s.y:st)/doc.height;l.x=clamp(l.x,-1,2);l.y=clamp(l.y,-1,2);commit();}});
function toBlob(c,type='image/png'){return new Promise((resolve,reject)=>c.toBlob(b=>b?resolve(b):reject(Error('Export failed. Try a smaller canvas.')),type,.94));}
async function exportImage(){if(busy||!doc.background)return;busy=true;controls();status('Preparing download…');const c=document.createElement('canvas');try{c.width=doc.width;c.height=doc.height;paint(c.getContext('2d',{alpha:false}));const b=await toBlob(c,'image/'+$('format').value);blobDownload(b,`wallpaper-${doc.width}x${doc.height}.${b.type==='image/jpeg'?'jpg':b.type.split('/')[1]}`);status('Download ready.');}catch(e){status(e.message,true);}finally{c.width=c.height=1;busy=false;controls();}}
$('export').onclick=exportImage;
function captureVariant(){if(busy||!doc.background)return;const key=doc.width+'x'+doc.height;checkpoint();doc.variants=doc.variants.filter(v=>v.key!==key);if(doc.variants.length>=8)doc.variants.shift();doc.variants.push({key,width:doc.width,height:doc.height,layers:clone(doc.layers)});commit();status(`${doc.variants.length} sizes saved for ZIP. Adjust another canvas size, then save its layout.`);}
$('capture-variant').onclick=captureVariant;
// ZIP store format: no dependency, one canvas at a time, CRC32 integrity fields.
const crcTable=Array.from({length:256},(_,n)=>{for(let k=0;k<8;k++)n=(n&1)?0xedb88320^(n>>>1):n>>>1;return n>>>0;});
function crc32(b){let c=0xffffffff;for(const v of b)c=crcTable[(c^v)&255]^(c>>>8);return(c^0xffffffff)>>>0;}
function zip(files){let offset=0,parts=[],central=[];for(const f of files){const name=new TextEncoder().encode(f.name),b=f.bytes,crc=crc32(b),a=new Uint8Array(30+name.length),v=new DataView(a.buffer);v.setUint32(0,0x04034b50,true);v.setUint16(4,20,true);v.setUint32(14,crc,true);v.setUint32(18,b.length,true);v.setUint32(22,b.length,true);v.setUint16(26,name.length,true);a.set(name,30);parts.push(a,b);const c=new Uint8Array(46+name.length),d=new DataView(c.buffer);d.setUint32(0,0x02014b50,true);d.setUint16(4,20,true);d.setUint16(6,20,true);d.setUint32(16,crc,true);d.setUint32(20,b.length,true);d.setUint32(24,b.length,true);d.setUint16(28,name.length,true);d.setUint32(42,offset,true);c.set(name,46);central.push(c);offset+=a.length+b.length;}const size=central.reduce((n,b)=>n+b.length,0),end=new Uint8Array(22),v=new DataView(end.buffer);v.setUint32(0,0x06054b50,true);v.setUint16(8,files.length,true);v.setUint16(10,files.length,true);v.setUint32(12,size,true);v.setUint32(16,offset,true);return new Blob([...parts,...central,end],{type:'application/zip'});}
$('export-zip').onclick=async()=>{if(busy||!doc.background)return;busy=true;controls();const original=clone(doc),c=document.createElement('canvas');try{const variants=doc.variants.length?clone(doc.variants):[{width:1920,height:1080,layers:clone(doc.layers)},{width:1080,height:1920,layers:clone(doc.layers)}],files=[];for(const v of variants){doc.width=v.width;doc.height=v.height;doc.layers=v.layers;status(`Exporting ${v.width} × ${v.height}…`);c.width=doc.width;c.height=doc.height;paint(c.getContext('2d',{alpha:false}));files.push({name:`wallpaper-${v.width}x${v.height}.png`,bytes:new Uint8Array(await(await toBlob(c)).arrayBuffer())});}blobDownload(zip(files),'wallpaper-sizes.zip');status('ZIP ready.');}catch(e){status(e.message,true);}finally{doc=original;c.width=c.height=1;busy=false;commit();}};
async function assetData(){const used=new Set([doc.background,...doc.layers.filter(l=>l.type==='image').map(l=>l.asset),...doc.variants.flatMap(v=>v.layers.filter(l=>l.type==='image').map(l=>l.asset))].filter(Boolean)),out={};for(const id of used){const b=assets.get(id);if(!b)throw Error('A project image is unavailable.');out[id]=await new Promise((resolve,reject)=>{const r=new FileReader();r.onload=()=>resolve(r.result);r.onerror=()=>reject(Error('Could not save image.'));r.readAsDataURL(b);});}return out;}
async function packageProject(){return{format:'hostingspell-wallpaper',version:2,document:clone(doc),assets:await assetData()};}
$('save-project').onclick=async()=>{try{blobDownload(new Blob([JSON.stringify(await packageProject())],{type:'application/json'}),'wallpaper-project.hswall');dirty=false;status('Editable project saved.');}catch(e){status(e.message,true);}};
function validateProject(p){if(!p||p.format!=='hostingspell-wallpaper'||p.version!==2||!p.document||!p.assets||typeof p.assets!=='object')throw Error('Not a supported Wallpaper Studio project.');const d=p.document;for(const key of ['grid','snap','smart'])if(typeof d[key]!=='boolean')throw Error('Invalid canvas settings.');for(const [key,min,max] of [['strength',0,100],['brightness',25,175],['contrast',25,175],['saturation',0,200],['gridSize',8,256],['gridRows',1,24],['gridCols',1,24],['gridSub',1,4]])if(typeof d[key]!=='number'||!Number.isFinite(d[key])||d[key]<min||d[key]>max)throw Error('Invalid canvas settings.');if(!['cover','contain'].includes(d.fit)||!['none','grayscale','vintage','warm','cool','noir','fade'].includes(d.filter)||!['pixels','divisions'].includes(d.gridMode))throw Error('Invalid canvas settings.');if(!Number.isInteger(d.width)||!Number.isInteger(d.height)||d.width<320||d.height<320||d.width>7680||d.height>7680||d.width*d.height>LIMIT||!Array.isArray(d.layers)||d.layers.length>MAX_LAYERS||!Array.isArray(d.variants)||d.variants.length>8)throw Error('Invalid project dimensions or layers.');const validLayers=ls=>{if(!Array.isArray(ls)||ls.length>MAX_LAYERS)throw Error('Invalid layers.');if(new Set(ls.map(l=>l.id)).size!==ls.length)throw Error('Duplicate layer IDs.');for(const l of ls){for(const key of ['hidden','locked','hollow','bold','plate'])if(typeof l[key]!=='boolean')throw Error('Invalid layer flags.');if(!['shape','image','text'].includes(l.type)||typeof l.id!=='string'||l.id.length>80||typeof l.name!=='string'||l.name.length>80)throw Error('Invalid layer.');for(const k of ['x','y','w','h','rotation','opacity','strokeWidth','radius','sides','points','inner','shadow','outline','fontSize'])if(typeof l[k]!=='number'||!Number.isFinite(l[k]))throw Error('Invalid layer values.');if(!['center','left','right'].includes(l.align)||l.strokeWidth<0||l.strokeWidth>80||l.radius<0||l.radius>50||l.shadow<0||l.shadow>80||l.outline<0||l.outline>20||l.rotation < -180||l.rotation>180||l.inner<.1||l.inner>.9||l.x < -1||l.x>2||l.y < -1||l.y>2||!Number.isInteger(l.sides)||!Number.isInteger(l.points)|| (l.type==='shape'&&!['rectangle','square','rounded','circle','ellipse','triangle','diamond','pentagon','hexagon','polygon','star','arrow','line'].includes(l.shape))||l.w<=0||l.h<=0||l.w>2||l.h>2||l.opacity<0||l.opacity>1||l.fontSize<8||l.fontSize>600||l.sides<3||l.sides>16||l.points<3||l.points>16||!/^#[\da-f]{6}$/i.test(l.fill)||!/^#[\da-f]{6}$/i.test(l.stroke)||typeof l.text!=='string'||l.text.length>1000||!['system-ui','serif','monospace','Arial','Georgia','Verdana'].includes(l.font))throw Error('Invalid layer properties.');}};validLayers(d.layers);for(const v of d.variants){if(!Number.isInteger(v.width)||!Number.isInteger(v.height)||v.width<320||v.height<320||v.width>7680||v.height>7680||v.width*v.height>LIMIT)throw Error('Invalid export size.');validLayers(v.layers);}if(Object.values(p.assets).reduce((n,v)=>n+(typeof v==='string'?v.length:90000000),0)>90000000)throw Error('Project is too large.');if(Object.keys(p.assets).length>50)throw Error('Too many images.');for(const value of Object.values(p.assets))if(typeof value!=='string'||!/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(value))throw Error('Invalid project image.');return p;}
function dataBlob(value){const [head,body]=value.split(',');const raw=atob(body),bytes=new Uint8Array(raw.length);for(let i=0;i<raw.length;i++)bytes[i]=raw.charCodeAt(i);return new Blob([bytes],{type:head.slice(5,head.indexOf(';'))});}
async function restorePackage(p){if(busy)return;validateProject(p);busy=true;controls();try{const nextImages=new Map(),nextAssets=new Map();let pixels=0;pixels=[...images.values()].reduce((n,i)=>n+i.naturalWidth*i.naturalHeight,0);for(const [id,value] of Object.entries(p.assets)){const b=dataBlob(value),im=await decode(b);pixels+=im.naturalWidth*im.naturalHeight;if(pixels>64000000)throw Error('Project images exceed the 64 MP total memory limit.');nextImages.set(id,im);nextAssets.set(id,b);}for(const id of [p.document.background,...p.document.layers.filter(l=>l.type==='image').map(l=>l.asset),...p.document.variants.flatMap(v=>v.layers.filter(l=>l.type==='image').map(l=>l.asset))])if(id&&!nextImages.has(id))throw Error('Project image missing.');checkpoint();doc=clone(p.document);const remap=new Map();for(const [id,im] of nextImages){const fresh=uid();remap.set(id,fresh);images.set(fresh,im);assets.set(fresh,nextAssets.get(id));}doc.background=doc.background?remap.get(doc.background):null;for(const l of [...doc.layers,...doc.variants.flatMap(v=>v.layers)])if(l.type==='image')l.asset=remap.get(l.asset);selected=null;commit();status('Project opened.');}finally{busy=false;controls();}}
$('open-project').onchange=async e=>{const f=e.target.files[0];e.target.value='';if(!f)return;try{if(f.size>90*1048576)throw Error('Project file must be under 90 MB.');await restorePackage(JSON.parse(await f.text()));}catch(e){status(e.message,true);}};
let dbPromise;function db(){return dbPromise||(dbPromise=new Promise((resolve,reject)=>{const req=indexedDB.open('hostingspell-wallpaper',1);req.onupgradeneeded=()=>req.result.createObjectStore('saved');req.onsuccess=()=>resolve(req.result);req.onerror=()=>reject(req.error);}));}
async function storage(action,key,value){const database=await db();return new Promise((resolve,reject)=>{const tx=database.transaction('saved',action==='get'?'readonly':'readwrite'),s=tx.objectStore('saved'),r=action==='get'?s.get(key):action==='put'?s.put(value,key):s.delete(key);let result;r.onsuccess=()=>result=r.result;tx.oncomplete=()=>resolve(result);tx.onerror=()=>reject(tx.error);});}
let draftVersion=0;function scheduleDraft(){const version=++draftVersion;if(!$('recover-draft').checked||!doc.background)return;clearTimeout(draftTimer);draftTimer=setTimeout(async()=>{try{const p=await packageProject();if(version===draftVersion&&$('recover-draft').checked)await storage('put','draft',p);}catch{status('Device draft could not be saved. Download a project file to keep your work.',true);}},1200);}
$('recover-draft').onchange=async()=>{if($('recover-draft').checked)scheduleDraft();else{clearTimeout(draftTimer);await storage('delete','draft').catch(()=>{});}};
$('restore-draft').onclick=async()=>{try{const p=await storage('get','draft');if(!p)return status('No saved device draft.');await restorePackage(p);}catch(e){status(e.message,true);}};
$('clear-device').onclick=async()=>{if(busy)return;busy=true;controls();clearTimeout(draftTimer);$('recover-draft').checked=false;try{await Promise.all(['draft','brand'].map(k=>storage('delete',k)));status('Saved device data cleared.');}catch{status('Saved device data could not be cleared. Use your browser’s site storage settings.',true);}finally{busy=false;controls();}};
$('save-brand').onclick=async()=>{try{const ls=doc.layers.filter(l=>l.type==='image');if(!ls.length)return status('Add a logo first.',true);const p=await packageProject();p.brandColour=$('brand-colour').value;p.document.layers=ls;p.document.background=null;p.document.variants=[];const used=new Set(ls.map(l=>l.asset));p.assets=Object.fromEntries(Object.entries(p.assets).filter(([id])=>used.has(id)));await storage('put','brand',p);status('Brand kit saved on this device.');}catch(e){status(e.message,true);}};
$('load-brand').onclick=async()=>{try{const p=await storage('get','brand');if(!p)return status('No brand kit saved.');validateProject(p);if(/^#[\da-f]{6}$/i.test(p.brandColour||''))$('brand-colour').value=p.brandColour;if(doc.layers.length+p.document.layers.length>MAX_LAYERS)throw Error('Layer limit reached.');const loaded=[];let total=[...images.values()].reduce((n,i)=>n+i.naturalWidth*i.naturalHeight,0);for(const l of p.document.layers){const b=dataBlob(p.assets[l.asset]),im=await decode(b);total+=im.naturalWidth*im.naturalHeight;if(total>64000000)throw Error('Brand kit exceeds this session’s image memory limit.');loaded.push({l,b,im});}checkpoint();for(const {l,b,im} of loaded){const id=uid();assets.set(id,b);images.set(id,im);doc.layers.push({...l,id:uid(),asset:id});}commit();status('Brand kit added.');}catch(e){status(e.message,true);}};
$('new-project').onclick=()=>{if(doc.background&&!confirm('Start a new project? Download your project first to keep it.'))return;checkpoint();doc.background=null;doc.layers=[];doc.variants=[];selected=null;commit();status('New project.');};
// Legacy private server projects can be opened explicitly, without uploading new files.
$('restore-legacy').onclick=async()=>{busy=true;controls();try{const r=await fetch(BOOT.script+'?action=state',{method:'POST',headers:{'X-CSRF-Token':BOOT.csrf},credentials:'same-origin'}),data=await r.json();if(!r.ok||!data.ok)throw Error(data.error||'Previous workspace unavailable.');const p=data.project;if(!p.background)return status('No previous server workspace.');const loaded={};for(const kind of ['background','logo'])if(p[kind]){const response=await fetch(BOOT.script+'?action=asset&kind='+kind+'&v='+encodeURIComponent(p[kind].rev));if(!response.ok)throw Error('Previous image is unavailable.');const b=await response.blob();loaded[kind]={blob:b,image:await decode(b),id:uid()};}if([...images.values()].reduce((n,i)=>n+i.naturalWidth*i.naturalHeight,0)+Object.values(loaded).reduce((n,a)=>n+a.image.naturalWidth*a.image.naturalHeight,0)>64000000)throw Error('Previous workspace exceeds this session’s image memory limit. Save and reload first.');checkpoint();doc.width=p.layout.width;doc.height=p.layout.height;doc.fit=p.layout.fit;doc.filter=p.layout.filter||'none';doc.strength=doc.brightness=doc.contrast=doc.saturation=100;for(const key of ['grid','snap','gridMode','gridSize','gridRows','gridCols','gridSub'])if(p.layout[key]!==undefined)doc[key]=p.layout[key];doc.layers=[];for(const [kind,a] of Object.entries(loaded)){images.set(a.id,a.image);assets.set(a.id,a.blob);if(kind==='background')doc.background=a.id;else{const l=baseLayer('image','Previous logo');Object.assign(l,{asset:a.id,x:p.layout.x,y:p.layout.y,w:p.layout.scale,h:p.layout.scale*doc.width/doc.height*a.image.naturalHeight/a.image.naturalWidth,opacity:p.layout.opacity});doc.layers.push(l);}}commit();status('Previous server project opened locally.');}catch(e){status(e.message,true);}finally{busy=false;controls();}};
$('delete-legacy').onclick=async()=>{if(busy||!confirm('Delete the previous temporary server workspace now?'))return;busy=true;controls();try{const r=await fetch(BOOT.script+'?action=clear',{method:'POST',headers:{'X-CSRF-Token':BOOT.csrf},credentials:'same-origin'}),d=await r.json();if(!r.ok||!d.ok)throw Error(d.error||'Previous workspace could not be deleted.');status('Previous server workspace deleted.');}catch(e){status(e.message,true);}finally{busy=false;controls();}};
function selectTab(name){for(const k of ['collection','create']){$('tab-'+k).setAttribute('aria-selected',String(k===name));$(k).hidden=k!==name;}const u=new URL(location.href);u.searchParams.set('tab',name);history.replaceState(null,'',u);}
for(const k of ['collection','create']){$('tab-'+k).onclick=e=>{e.preventDefault();selectTab(k);};$('tab-'+k).onkeydown=e=>{if(['ArrowLeft','ArrowRight','Home','End'].includes(e.key)){e.preventDefault();const next=e.key==='Home'?'collection':e.key==='End'?'create':k==='collection'?'create':'collection';selectTab(next);$('tab-'+next).focus();}};}
let active=0,returnTo;function view(index,opener){const item=BOOT.items[index];if(!item)return;active=index;returnTo=opener||returnTo;$('viewer-share').textContent='Share link';$('viewer-title').textContent=item.name;$('viewer-image').src='./walls/'+encodeURIComponent(item.file);$('viewer-image').alt=item.name;$('viewer-meta').textContent=`${item.width} × ${item.height} · ${(item.bytes/1048576).toFixed(1)} MB`;$('viewer-download').href=BOOT.script+'?download='+encodeURIComponent(item.file);$('viewer-stage').classList.remove('original');$('viewer-fit').textContent='View original size';if(!$('viewer').open)$('viewer').showModal();const u=new URL(location.href);u.searchParams.set('wallpaper',item.file);history.replaceState(null,'',u);}
document.querySelectorAll('[data-view]').forEach(b=>b.onclick=()=>view(Number(b.dataset.view),b));$('viewer-close').onclick=()=>$('viewer').close();$('viewer').onclose=()=>{returnTo?.focus();const u=new URL(location.href);u.searchParams.delete('wallpaper');history.replaceState(null,'',u);};$('viewer-prev').onclick=()=>view((active-1+BOOT.items.length)%BOOT.items.length);$('viewer-next').onclick=()=>view((active+1)%BOOT.items.length);$('viewer-fit').onclick=()=>{const on=$('viewer-stage').classList.toggle('original');$('viewer-fit').textContent=on?'Fit to screen':'View original size';};$('viewer').onkeydown=e=>{if(e.key==='ArrowLeft')$('viewer-prev').click();if(e.key==='ArrowRight')$('viewer-next').click();};
$('viewer-share').onclick=async()=>{try{const u=new URL(location.href);u.searchParams.set('wallpaper',BOOT.items[active].file);if(navigator.share)await navigator.share({title:BOOT.items[active].name,url:u.href});else{await navigator.clipboard.writeText(u.href);$('viewer-share').textContent='Link copied';}}catch{}};
$('viewer-edit').onclick=async()=>{try{const item=BOOT.items[active],r=await fetch('./walls/'+encodeURIComponent(item.file));if(!r.ok)throw Error('Wallpaper could not be opened.');const b=await r.blob();$('viewer').close();selectTab('create');await importImage(new File([b],item.file,{type:b.type}),'background');}catch(e){status(e.message,true);}};
$('sort').onchange=()=>{try{localStorage.setItem('wallpaper-sort',$('sort').value);}catch{}$('sort-form').requestSubmit();};try{const saved=localStorage.getItem('wallpaper-sort');if(!new URL(location.href).searchParams.has('sort')&&[...$('sort').options].some(o=>o.value===saved)&&saved!==$('sort').value){const u=new URL(location.href);u.searchParams.set('sort',saved);location.replace(u);}}catch{}$('sort-apply').hidden=true;$('search').oninput=()=>{const q=$('search').value.toLocaleLowerCase().trim();let n=0;document.querySelectorAll('.card').forEach(c=>{c.hidden=!c.dataset.name.toLocaleLowerCase().includes(q);if(!c.hidden)n++;});$('collection-empty').hidden=n>0;$('collection-empty').textContent=q?'No wallpapers match. Clear search to start again.':'No wallpapers here yet.';};$('clear-search').onclick=()=>{$('search').value='';$('search').dispatchEvent(new Event('input'));};
const requested=new URL(location.href).searchParams.get('wallpaper'),idx=BOOT.items.findIndex(i=>i.file===requested);if(idx>=0)view(idx);selectTab(BOOT.initialTab||'collection');commit();status('Ready. Images are edited on your device.');
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
    if ($action === 'sitemap') {
        if($_SERVER['REQUEST_METHOD']!=='GET')fail('Method not allowed.',405);
        header('Content-Type: application/xml; charset=utf-8');header('Cache-Control: public, max-age=3600');
        $urls=[SITE_URL,SITE_URL . '?tab=create'];foreach(['logo-wallpaper-maker','company-wallpaper-maker','phone-wallpaper-maker','amoled-wallpapers'] as $page)$urls[]=SITE_URL . '?page=' . $page;foreach(collection() as $item)$urls[]=SITE_URL . '?wallpaper=' . rawurlencode($item['file']);
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($urls as $url)echo '<url><loc>' . h($url) . '</loc></url>';echo '</urlset>';exit;
    }
    if ($action === 'thumbnail') {
        $file = input('file', $_GET); $found = null;
        foreach (collection() as $item) if ($item['file'] === $file) { $found = $item; break; }
        if (!$found || $_SERVER['REQUEST_METHOD'] !== 'GET') fail('Thumbnail not found.', 404);
        $source = __DIR__ . '/walls/' . $file;
        if ($found['bytes'] > MAX_BACKGROUND || $found['width'] * $found['height'] > 16000000 || !extension_loaded('gd')) { header('Location: ./walls/' . rawurlencode($file)); exit; }
        $cache = $storage . '/thumbnails'; if (!is_dir($cache)) @mkdir($cache, 0700);
        if(!is_writable($cache)){header('Location: ./walls/' . rawurlencode($file));exit;}
        $path = $cache . '/' . hash('sha256', $file . ':' . $found['time'] . ':' . $found['bytes']) . '.php';
        if (!is_file($path)) {
            $image = @imagecreatefromstring(file_get_contents($source));
            if (!$image) { header('Location: ./walls/' . rawurlencode($file)); exit; }
            $ratio = min(1, 640 / max($found['width'], $found['height']));
            $thumb = imagecreatetruecolor(max(1,(int)round($found['width']*$ratio)), max(1,(int)round($found['height']*$ratio)));
            imagecopyresampled($thumb,$image,0,0,0,0,imagesx($thumb),imagesy($thumb),imagesx($image),imagesy($image));
            ob_start(); imagejpeg($thumb,null,82); $bytes=ob_get_clean(); imagedestroy($thumb); imagedestroy($image);
            $tmp=$path . '.' . bin2hex(random_bytes(4)); file_put_contents($tmp,GUARD . $bytes,LOCK_EX); chmod($tmp,0600); rename($tmp,$path);
        }
        header('Cache-Control: public, max-age=86400'); header('CDN-Cache-Control: public, max-age=86400');
        sendAsset($path,['mime'=>'image/jpeg']);
    }
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
    $sort = input('sort', $_GET); if (!isset(SORT_OPTIONS[$sort])) $sort = 'name-asc';
    $items = collection($sort);
    $pages = [
        'logo-wallpaper-maker' => ['Wallpaper Maker with Logo', 'Upload a wallpaper, add one or several logos, and export a branded background. Use alignment guides and a subtle logo plate for legibility.'],
        'company-wallpaper-maker' => ['Company Desktop Wallpaper Maker', 'Create consistent company wallpapers with a reusable device-local brand kit. Save separate layouts for desktop and laptop displays and export them together.'],
        'phone-wallpaper-maker' => ['Phone Wallpaper Maker', 'Start with a portrait canvas, position your design below the clock, and use the lock screen preview before downloading. Preview clocks and controls are excluded from exports.'],
        'amoled-wallpapers' => ['AMOLED Wallpapers', 'Browse the original wallpaper collection or create a dark background. Pure black pixels can reduce display power use on OLED screens; the result depends on brightness and the image.'],
    ];
    $page = input('page', $_GET); if (!isset($pages[$page])) $page = '';
    $detail = null; foreach ($items as $item) if ($item['file'] === input('wallpaper', $_GET)) { $detail=$item; break; }
    $pageTitle = $detail ? $detail['name'] . ' Wallpaper' : ($page ? $pages[$page][0] : 'Free Wallpaper Maker & AMOLED Wallpapers');
    $description = $detail ? 'Preview and download ' . $detail['name'] . ', ' . $detail['width'] . ' × ' . $detail['height'] . ' pixels. Open it in the free logo wallpaper editor.' : ($page ? $pages[$page][1] : 'Free wallpaper maker with local image editing, logos, text, shapes and photo filters. Download desktop, phone and AMOLED wallpapers.');
    $canonical = SITE_URL . ($detail ? '?wallpaper=' . rawurlencode($detail['file']) : ($page ? '?page=' . rawurlencode($page) : ($initialTab === 'create' ? '?tab=create' : '')));
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
<meta name="theme-color" content="#000000"><meta name="description" content="<?= h($description) ?>">
<title><?= h($pageTitle) ?> | HostingSpell</title>
<link rel="canonical" href="<?= h($canonical) ?>">
<meta property="og:type" content="website"><meta property="og:title" content="<?= h($pageTitle) ?> | HostingSpell"><meta property="og:description" content="<?= h($description) ?>"><meta property="og:url" content="<?= h($canonical) ?>"><meta property="og:image" content="<?= h($detail ? dirname(SITE_URL) . '/walls/' . rawurlencode($detail['file']) : BRAND_LOGO) ?>">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= h($pageTitle) ?> | HostingSpell"><meta name="twitter:description" content="<?= h($description) ?>">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14'/%3E%3Cpath d='M32 7 38 25 57 32 38 39 32 57 25 39 7 32 25 25Z' fill='%23ff842e'/%3E%3C/svg%3E">
<script type="application/ld+json" nonce="<?= h($nonce) ?>"><?= json_encode(['@context'=>'https://schema.org','@type'=>'WebApplication','name'=>'HostingSpell Wallpaper Studio','url'=>SITE_URL,'applicationCategory'=>'DesignApplication','operatingSystem'=>'Any','offers'=>['@type'=>'Offer','price'=>'0','priceCurrency'=>'USD'],'description'=>$description], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
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
.nav-actions{display:flex;align-items:center;gap:24px}.repo-link{display:inline-flex;align-items:center;gap:8px;border:1px solid #333;border-radius:9px;padding:8px 12px;font-size:12px;font-weight:600;white-space:nowrap}.repo-link:hover{color:var(--orange);border-color:var(--orange)}.repo-link svg{flex:none}.collection-tools{display:flex;align-items:center;gap:12px;max-width:100%;flex:1;justify-content:flex-end}.collection-tools input{width:260px;max-width:100%}.collection-tools form{display:flex;align-items:center;gap:8px}.collection-tools select{width:180px}@media(max-width:740px){.collection-tools{width:100%;flex-wrap:wrap;justify-content:flex-start}.collection-tools input{flex:1;min-width:140px}.collection-tools select{width:170px}.nav-actions{gap:12px}}@media(max-width:600px){.nav-actions .tag{display:none}}@media(max-width:400px){.collection-tools input{width:100%;flex-basis:100%}.collection-tools form,.collection-tools select{flex:1;width:100%}}

/* Local layered editor */
.editor-toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.editor-toolbar select{width:auto;max-width:180px}.tools-panel .panel{padding:16px;margin:0 0 12px}.tools-panel summary{cursor:pointer;font-weight:650}.tools-panel details[open]>summary{margin-bottom:14px}.tools-panel label{margin-top:12px}.tools-panel .file-label{margin-bottom:10px}.tools-panel .inline{margin-top:10px}.tools-panel .inline>*{flex:1;min-width:0}.tools-panel button{margin-top:8px}.tools-panel input[type=color]{width:100%;height:38px;background:#000;border:1px solid #333}.tools-panel textarea{width:100%;background:#000;color:#fff;border:1px solid #333;border-radius:8px;padding:10px;font:inherit}.tools-panel .check{white-space:normal}.layer-row button{width:100%;text-align:left;overflow-wrap:anywhere}.layer-row button[aria-pressed=true]{border-color:var(--orange);color:var(--orange)}.v2-studio .stage{overflow:auto;min-height:320px}.v2-studio .stage canvas{flex-shrink:0}.v2-studio .canvas-panel{top:12px}.v2-studio .canvas-bottom{flex-wrap:wrap}.tools-panel .help{margin-top:8px}.drag-over{outline:2px solid var(--orange)}#clear-search{margin-top:16px}
@media(max-width:740px){.v2-studio{display:flex;flex-direction:column}.v2-studio .tools-panel{display:block;width:100%;order:2}.v2-studio .canvas-panel{order:1;position:static;width:100%}.v2-studio .stage{min-height:230px}.v2-studio .canvas-bottom{position:sticky;bottom:0;background:#000;padding:10px 0;z-index:2}.editor-toolbar{gap:6px}.editor-toolbar button{padding:7px 10px}.editor-toolbar select{max-width:140px}.tools-panel input,.tools-panel select,.tools-panel button{min-height:44px}}
@media(max-width:740px){#object-properties{position:fixed;bottom:8px;left:12px;width:calc(100% - 24px);max-height:42vh;overflow:auto;z-index:10;background:#080808;border-color:#555;box-shadow:0 -10px 40px #0009}body:has(#object-properties:not([hidden])){padding-bottom:80px}}
</style>
</head>
<body>
<header class="header"><div class="wrap nav"><a href="<?= h($script) ?>" aria-label="HostingSpell Wallpaper Studio"><img class="brand" src="<?= h(BRAND_LOGO) ?>" alt="HostingSpell" width="185"></a><div class="nav-actions"><span class="tag">Wallpaper Studio</span><a class="repo-link" href="<?= h(REPOSITORY_URL) ?>" target="_blank" rel="noopener noreferrer" aria-label="View Wallpaper Studio source on GitHub (opens in a new tab)"><svg viewBox="0 0 24 24" width="19" height="19" fill="currentColor" aria-hidden="true"><path d="M12 .3a12 12 0 0 0-3.79 23.39c.6.11.82-.26.82-.58v-2.23c-3.34.73-4.04-1.42-4.04-1.42-.55-1.39-1.34-1.76-1.34-1.76-1.09-.75.08-.73.08-.73 1.21.09 1.84 1.24 1.84 1.24 1.07 1.83 2.81 1.3 3.49.99.11-.78.42-1.3.76-1.6-2.67-.3-5.47-1.34-5.47-5.93 0-1.31.47-2.38 1.24-3.22-.13-.3-.54-1.52.12-3.18 0 0 1.01-.32 3.3 1.23a11.5 11.5 0 0 1 6 0c2.29-1.55 3.3-1.23 3.3-1.23.66 1.66.25 2.88.12 3.18.77.84 1.24 1.91 1.24 3.22 0 4.6-2.81 5.62-5.49 5.92.43.37.82 1.1.82 2.22v3.3c0 .32.22.7.83.58A12 12 0 0 0 12 .3Z"/></svg><span>GitHub</span></a></div></div></header>
<main class="wrap">
<section class="intro"><p class="eyebrow">Your screen. Your signature.</p><h1>Your wallpaper. Your signature.</h1><p>Free desktop &amp; mobile wallpapers. Add your logo. Make it yours.</p></section>
<nav class="tabs" role="tablist" aria-label="Wallpaper tools">
<a class="tab-card" href="?tab=collection" id="tab-collection" role="tab" aria-selected="<?= $initialTab === 'collection' ? 'true' : 'false' ?>" aria-controls="collection"><span class="tab-icon" aria-hidden="true">▦</span><span><strong>Our Collection</strong><small>Browse &amp; download HostingSpell wallpapers</small></span><span class="tab-arrow" aria-hidden="true">→</span></a>
<a class="tab-card create-tab" href="?tab=create" id="tab-create" role="tab" aria-selected="<?= $initialTab === 'create' ? 'true' : 'false' ?>" aria-controls="create"><span class="tab-icon" aria-hidden="true">✦</span><span><strong>Create a wallpaper <em>FREE TOOL</em></strong><small>Upload your photo, add a logo &amp; make it yours</small></span><span class="tab-arrow" aria-hidden="true">→</span></a>
</nav>
<noscript><p>The collection downloads work without JavaScript. Enable JavaScript to use the viewer and wallpaper editor.</p></noscript>
<section id="collection" role="tabpanel" aria-labelledby="tab-collection" <?= $initialTab !== 'collection' ? 'hidden' : '' ?>>
<div class="collection-bar"><span><?= count($items) ?> wallpapers</span><div class="collection-tools"><label class="sr" for="search">Search wallpapers</label><input id="search" type="search" maxlength="120" placeholder="Find a wallpaper…"><form id="sort-form" method="get" action="<?= h($script) ?>"><input type="hidden" name="tab" value="collection"><label class="sr" for="sort">Sort wallpapers</label><select id="sort" name="sort"><?php foreach (SORT_OPTIONS as $key => $label): ?><option value="<?= h($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select><button id="sort-apply" type="submit">Sort</button></form></div></div>
<div class="gallery" id="gallery">
<?php foreach ($items as $index => $item): ?>
<article class="card" data-name="<?= h($item['name']) ?>">
<button class="preview" data-view="<?= $index ?>" aria-label="<?= h('View ' . $item['name']) ?>"><img src="<?= h($script . '?action=thumbnail&file=' . rawurlencode($item['file']) . '&v=' . $item['time']) ?>" alt="<?= h($item['name']) ?>" width="<?= $item['width'] ?>" height="<?= $item['height'] ?>" loading="lazy" decoding="async"></button>
<div class="card-body"><h2><?= h($item['name']) ?></h2><div class="card-actions"><button data-view="<?= $index ?>">Preview ↗</button><a class="link-button" href="?download=<?= h(rawurlencode($item['file'])) ?>">Download</a></div></div>
</article>
<?php endforeach; ?>
</div><button id="clear-search">Clear search</button><p id="collection-empty" class="empty" <?= $items ? 'hidden' : '' ?>>No wallpapers here yet.</p>
</section>
<section id="create" role="tabpanel" aria-labelledby="tab-create" <?= $initialTab !== 'create' ? 'hidden' : '' ?>>
<div class="studio-top"><h2>Wallpaper maker</h2><span class="hint">Edited on your device</span></div>
<div class="editor-toolbar" id="editor-toolbar"><button id="undo" aria-label="Undo" disabled>↶ Undo</button><button id="redo" aria-label="Redo" disabled>↷ Redo</button><label class="sr" for="zoom">Zoom</label><select id="zoom"><option value="1">Fit</option><option value=".5">50%</option><option value="1.5">150%</option><option value="2">200%</option><option value="3">300%</option></select><label class="sr" for="device-preview">Device preview</label><select id="device-preview"><option value="none">Clean preview</option><option value="desktop">Desktop preview</option><option value="phone">Lock screen preview</option></select></div>
<div class="studio v2-studio"><aside class="tools-panel" id="tools-panel">
<details class="panel" open><summary>Images &amp; objects</summary>
<label class="file-label background-drop" id="background-drop"><strong>Upload your wallpaper</strong><input id="background-file" type="file" accept="image/png,image/jpeg,image/webp"></label>
<label class="file-label">Add logo / image<input id="logo-file" type="file" accept="image/png,image/jpeg,image/webp"></label>
<div class="inline"><label class="sr" for="shape-type">Shape</label><select id="shape-type"><option value="rectangle">Rectangle</option><option value="square">Square</option><option value="rounded">Rounded</option><option value="circle">Circle</option><option value="ellipse">Ellipse</option><option value="triangle">Triangle</option><option value="diamond">Diamond</option><option value="pentagon">Pentagon</option><option value="hexagon">Hexagon</option><option value="polygon">Polygon</option><option value="star">Star</option><option value="arrow">Arrow</option><option value="line">Line</option></select><button id="add-shape">+ Shape</button></div><button id="add-text" class="full">+ Text</button>
</details>
<details class="panel"><summary>Canvas &amp; grid</summary>
<label for="preset">Size</label><select id="preset"><option value="1920,1080">Desktop · 1920 × 1080</option><option value="2560,1440">QHD · 2560 × 1440</option><option value="3840,2160">4K · 3840 × 2160</option><option value="1080,1920">Phone · 1080 × 1920</option><option value="1440,2560">Phone QHD · 1440 × 2560</option><option value="2560,1080">Ultrawide · 2560 × 1080</option><option value="custom">Custom</option></select>
<div class="inline"><div><label for="width">Width</label><input id="width" type="number" min="320" max="7680" value="1920"></div><div><label for="height">Height</label><input id="height" type="number" min="320" max="7680" value="1080"></div></div>
<label for="fit">Image fit</label><select id="fit"><option value="cover">Fill canvas</option><option value="contain">Fit image</option></select>
<label class="check"><input id="grid" type="checkbox">Show grid</label><label class="check"><input id="snap" type="checkbox">Snap to grid</label><label class="check"><input id="smart" type="checkbox" checked>Smart guides</label>
<label for="gridMode">Grid layout</label><select id="gridMode"><option value="pixels">Pixel spacing</option><option value="divisions">Rows &amp; columns</option></select><div id="pixel-grid"><label for="gridSize">Spacing</label><input id="gridSize" type="number" min="8" max="256" value="64"></div><div id="division-grid" hidden><div class="inline"><div><label for="gridRows">Rows</label><input id="gridRows" type="number" min="1" max="24" value="3"></div><div><label for="gridCols">Columns</label><input id="gridCols" type="number" min="1" max="24" value="3"></div></div><label for="gridSub">Subdivisions</label><input id="gridSub" type="number" min="1" max="4" value="1"></div>
</details>
<details class="panel"><summary>Photo filters</summary><label for="filter">Look</label><select id="filter"><option value="none">Original</option><option value="grayscale">Grayscale</option><option value="vintage">Vintage</option><option value="warm">Warm</option><option value="cool">Cool</option><option value="noir">Noir</option><option value="fade">Fade</option></select><label for="strength">Strength</label><input id="strength" type="range" min="0" max="100" value="100"><label for="brightness">Brightness</label><input id="brightness" type="range" min="25" max="175" value="100"><label for="contrast">Contrast</label><input id="contrast" type="range" min="25" max="175" value="100"><label for="saturation">Saturation</label><input id="saturation" type="range" min="0" max="200" value="100"></details>
<details class="panel"><summary>Layers</summary><div id="layers"></div></details>
<details class="panel" id="object-properties" hidden open><summary>Selected object</summary><label for="object-name">Name</label><input id="object-name" maxlength="80">
<div class="inline"><button id="layer-backward" title="Move backward">↓ Back</button><button id="layer-forward" title="Move forward">↑ Forward</button></div><div class="inline"><button id="layer-duplicate">Duplicate</button><button id="layer-delete" class="danger">Delete</button></div>
<details><summary>Position &amp; size</summary><label for="object-x">X %</label><input id="object-x" data-prop="x" type="number" min="-100" max="200" step="0.1"><label for="object-y">Y %</label><input id="object-y" data-prop="y" type="number" min="-100" max="200" step="0.1"><label for="object-w">Width %</label><input id="object-w" data-prop="w" type="number" min="0.1" max="200" step="0.1"><label for="object-h">Height %</label><input id="object-h" data-prop="h" type="number" min="0.1" max="200" step="0.1"><label for="object-rotation">Rotation</label><input id="object-rotation" data-prop="rotation" type="number" min="-180" max="180" step="1"><label for="object-opacity">Opacity %</label><input id="object-opacity" data-prop="opacity" type="number" min="0" max="100" step="1"></details><details><summary>Style</summary><label for="object-fill">Fill / text colour</label><input id="object-fill" data-prop="fill" type="color"><label for="object-stroke">Outline colour</label><input id="object-stroke" data-prop="stroke" type="color"><div data-kind="shape text"><label for="object-strokeWidth">Outline width</label><input id="object-strokeWidth" data-prop="strokeWidth" type="number" min="0" max="80" step="1"></div><div data-kind="shape"><label for="object-radius">Corner radius %</label><input id="object-radius" data-prop="radius" type="number" min="0" max="50" step="1"></div><div data-kind="shape"><label for="object-sides">Polygon sides</label><input id="object-sides" data-prop="sides" type="number" min="3" max="16" step="1"></div><div data-kind="shape"><label for="object-points">Star points</label><input id="object-points" data-prop="points" type="number" min="3" max="16" step="1"></div><div data-kind="shape"><label for="object-inner">Star inner radius</label><input id="object-inner" data-prop="inner" type="number" min="0.1" max="0.9" step="0.05"></div><div data-kind="image text shape"><label for="object-shadow">Shadow</label><input id="object-shadow" data-prop="shadow" type="number" min="0" max="80" step="1"></div><div data-kind="image"><label for="object-outline">Logo outline</label><input id="object-outline" data-prop="outline" type="number" min="0" max="20" step="1"></div><div data-kind="text"><label for="object-fontSize">Font size</label><input id="object-fontSize" data-prop="fontSize" type="number" min="8" max="600" step="1"></div><label class="check" data-kind="shape"><input id="object-hollow" data-prop="hollow" type="checkbox">Hollow</label><label class="check" data-kind="image"><input id="object-plate" data-prop="plate" type="checkbox">Background plate</label><label class="check" data-kind="text"><input id="object-bold" data-prop="bold" type="checkbox">Bold</label><div data-kind="text"><label for="object-text">Text</label><textarea id="object-text" data-prop="text" maxlength="1000" rows="3"></textarea><label for="object-font">Font</label><select id="object-font" data-prop="font"><option>system-ui</option><option>serif</option><option>monospace</option><option>Arial</option><option>Georgia</option><option>Verdana</option></select><label for="object-align">Text alignment</label><select id="object-align" data-prop="align"><option>center</option><option>left</option><option>right</option></select></div></details>
<label class="check"><input id="object-locked" data-prop="locked" type="checkbox">Lock</label><label class="check"><input id="object-hidden" data-prop="hidden" type="checkbox">Hide</label>
<details><summary>Quick placement</summary><div class="align-grid" id="alignment"><button data-align="0,0" aria-label="Align 0, 0">↖</button><button data-align="0.5,0" aria-label="Align 0.5, 0">↑</button><button data-align="1,0" aria-label="Align 1, 0">↗</button><button data-align="0,0.5" aria-label="Align 0, 0.5">←</button><button data-align="0.5,0.5" aria-label="Align 0.5, 0.5">•</button><button data-align="1,0.5" aria-label="Align 1, 0.5">→</button><button data-align="0,1" aria-label="Align 0, 1">↙</button><button data-align="0.5,1" aria-label="Align 0.5, 1">↓</button><button data-align="1,1" aria-label="Align 1, 1">↘</button></div></details></details>
<details class="panel"><summary>Projects &amp; brand kit</summary><div class="inline"><button id="save-project">Save project</button><label class="file-label">Open project<input id="open-project" type="file" accept=".hswall,application/json"></label></div><label for="brand-colour">Brand colour</label><input id="brand-colour" type="color" value="#ff842e"><label class="check"><input id="recover-draft" type="checkbox">Save a draft on this device</label><button id="restore-draft">Restore draft</button><div class="inline"><button id="save-brand">Save brand kit</button><button id="load-brand">Use brand kit</button></div><button id="clear-device">Clear saved device data</button><button id="restore-legacy">Open previous server workspace</button><button id="delete-legacy" class="danger">Delete previous server workspace</button><button id="new-project">New project</button></details>
<details class="panel"><summary>Export several sizes</summary><p class="hint">Save each canvas size after positioning its objects. Up to 8 sizes.</p><button id="capture-variant">Save this size &amp; layout</button><button id="export-zip">Download sizes ZIP</button></details>
</aside>
<section class="panel canvas-panel" aria-label="Wallpaper editor"><div class="canvas-toolbar"><strong>Preview</strong><span id="dimensions">1920 × 1080</span></div><div class="stage" id="stage"><div id="stage-empty" class="stage-empty"><strong>Choose a wallpaper.</strong>Drop an image to start.</div><canvas id="canvas" width="1920" height="1080" tabindex="0" aria-label="Wallpaper canvas. Drag objects, resize corners, or use arrow keys." hidden></canvas></div><div class="canvas-bottom"><span class="hint">Drag · Resize corners · Paste images</span><div class="inline"><label class="sr" for="format">Export format</label><select id="format"><option value="png">PNG</option><option value="jpeg">JPG</option><option value="webp">WebP</option></select><button id="export" class="primary" disabled>Download ↓</button></div></div><p id="status" class="status" role="status" aria-live="polite"></p><details class="help"><summary>Privacy &amp; shortcuts</summary><p>New images stay in your browser. Optional drafts and brand kits are stored on this device until cleared. Download a project file for a portable backup.</p><p>Ctrl/Cmd Z: undo. Shift Ctrl/Cmd Z: redo. Arrow keys: nudge. Shift: 10 px. Delete: remove selected object. Drop onto the canvas to add an image layer. Pinch to zoom on touchscreens.</p><p>PNG, JPG and WebP up to 32 MB. Large images are resized. Export up to 16 MP. Maximum 40 layers.</p></details></section>
</div></section>
<?php if ($page): ?><section class="panel" style="margin-top:32px"><h2><?= h($pages[$page][0]) ?></h2><p><?= h($pages[$page][1]) ?></p><a class="link-button" href="?tab=create">Open wallpaper maker →</a></section><?php endif; ?>
<details class="help"><summary>Wallpaper guides</summary><nav aria-label="Wallpaper guides"><?php foreach ($pages as $key=>$guide): ?><p><a href="?page=<?= h($key) ?>"><?= h($guide[0]) ?></a></p><?php endforeach; ?></nav></details>
</main>
<footer><div class="wrap">© <?= date('Y') ?> <a href="https://hostingspell.com" target="_blank" rel="noopener noreferrer">HostingSpell</a>. A little magic for every screen.</div></footer>
<dialog id="viewer" class="viewer" aria-labelledby="viewer-title"><div class="viewer-top"><h2 id="viewer-title">Wallpaper</h2><button id="viewer-close" aria-label="Close wallpaper viewer">Close ✕</button></div><div id="viewer-stage" class="viewer-stage"><img id="viewer-image" alt=""></div><div class="viewer-bottom"><p class="meta" id="viewer-meta"></p><div class="inline"><button id="viewer-prev" aria-label="Previous wallpaper">← Previous</button><button id="viewer-next" aria-label="Next wallpaper">Next →</button><button id="viewer-fit">View original size</button><button id="viewer-share">Share link</button><button id="viewer-edit">Use in editor</button><a id="viewer-download" class="link-button">Download original ↓</a></div></div></dialog>
<script id="studio-config" data-cfasync="false" type="application/json"><?= json_encode(['script' => $script, 'initialTab' => $initialTab, 'csrf' => csrf($session), 'expires' => $session['expires'], 'items' => $items], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script data-cfasync="false" nonce="<?= h($nonce) ?>" src="<?= h($script) ?>?action=app&amp;v=2.0.0" defer></script>
</body></html>
