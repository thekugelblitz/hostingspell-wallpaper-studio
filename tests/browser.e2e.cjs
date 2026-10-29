/* Run from a development environment with PHP 8.2+, cURL, GD, Fileinfo,
   Playwright, @napi-rs/canvas and a Playwright-compatible Chromium installed.
   npm install --no-save playwright @napi-rs/canvas
   npx playwright install chromium
   node tests/browser.e2e.cjs
   Optional environment variables: PHP_BIN, PHP_EXTRA_ARGS (JSON array),
   CHROMIUM_PATH, PHP_LIBRARY_PATH. This always uses a disposable app/storage copy. */
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),assert=require('node:assert/strict'),{spawn}=require('node:child_process');
const {chromium}=require('playwright'),{createCanvas,loadImage}=require('@napi-rs/canvas');
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'hs-wallpaper-e2e-')),app=path.join(tmp,'app');fs.mkdirSync(app);fs.mkdirSync(path.join(app,'walls'));
fs.copyFileSync(path.join(__dirname,'../index.php'),path.join(app,'index.php'));
const bg=createCanvas(1600,900),bc=bg.getContext('2d');bc.fillStyle='#123456';bc.fillRect(0,0,1600,900);const bgBytes=bg.toBuffer('image/png');
fs.writeFileSync(path.join(app,'walls','Collection fixture.png'),bgBytes);fs.writeFileSync(path.join(app,'Root image must be ignored.png'),bgBytes);
const logo=createCanvas(100,50),lc=logo.getContext('2d');lc.clearRect(0,0,100,50);lc.fillStyle='#ff0000';lc.fillRect(35,15,30,20);const logoBytes=logo.toBuffer('image/png');
const port=18765,base=`http://127.0.0.1:${port}/index.php`,env={...process.env,WPS_STORAGE:path.join(tmp,'private-process')};if(process.env.PHP_LIBRARY_PATH)env.LD_LIBRARY_PATH=process.env.PHP_LIBRARY_PATH;
const args=process.env.PHP_EXTRA_ARGS?JSON.parse(process.env.PHP_EXTRA_ARGS):[];
const php=spawn(process.env.PHP_BIN||'php',[...args,'-d','upload_max_filesize=25M','-d','post_max_size=26M','-d','memory_limit=256M','-S',`127.0.0.1:${port}`,'-t',app],{env});let serverLog='';php.stderr.on('data',b=>serverLog+=b);php.stdout.on('data',b=>serverLog+=b);
let browser,passed=0;
const check=(name,ok)=>{assert.ok(ok,name);console.log('PASS:',name);passed++;};
(async()=>{
 for(let i=0;i<100;i++){try{await fetch(base);break;}catch{await new Promise(r=>setTimeout(r,50));}}
 browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_PATH?{executablePath:process.env.CHROMIUM_PATH}:{}),args:['--no-sandbox','--disable-dev-shm-usage','--disable-gpu']});
 const context=await browser.newContext({viewport:{width:1440,height:1000},acceptDownloads:true});const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('https://manage.hostingspell.com/**',route=>route.fulfill({contentType:'image/png',body:bgBytes}));
 await page.goto(base);await page.locator('#tab-collection[aria-selected="true"]').waitFor();
 check('only walls/ images appear in gallery',await page.locator('.card').count()===1&&await page.locator('.card h2').innerText()==='Collection fixture');
 check('collection images use walls/ URLs',(await page.locator('.card img').getAttribute('src')).startsWith('./walls/'));
 const download1=page.waitForEvent('download');await page.getByRole('link',{name:'Download',exact:true}).click();const collectionDownload=await download1;check('collection download returns original bytes',fs.readFileSync(await collectionDownload.path()).equals(bgBytes));
 await page.getByRole('button',{name:'View Collection fixture',exact:true}).click();await page.getByRole('dialog').waitFor();check('collection viewer opens with the correct image',(await page.locator('#viewer-image').getAttribute('src')).includes('/walls/'));
 await page.getByRole('button',{name:'Close wallpaper viewer'}).click();
 await page.getByRole('tab',{name:/Create a wallpaper/}).click();await page.locator('#status').filter({hasText:'Ready.'}).waitFor();
 check('Create tab opens and workspace initializes',await page.locator('#create').isVisible()&&await page.locator('#tab-create').getAttribute('aria-selected')==='true');
 check('Create has prominent custom upload control',await page.getByText('Upload your wallpaper',{exact:true}).isVisible());
 await page.locator('#background-file').setInputFiles({name:'Custom.png',mimeType:'image/png',buffer:bgBytes});await page.locator('#status').filter({hasText:'Wallpaper ready.'}).waitFor();
 check('custom wallpaper renders on canvas',await page.locator('#canvas').isVisible()&&await page.locator('#export').isEnabled());
 await page.locator('#logo-file').setInputFiles({name:'Logo.png',mimeType:'image/png',buffer:logoBytes});await page.locator('#status').filter({hasText:'Logo ready.'}).waitFor();
 check('company logo appears in canvas',(await page.locator('#canvas').evaluate(c=>Array.from(c.getContext('2d').getImageData(960,540,1,1).data))).join(',')==='255,0,0,255');
 await page.locator('#grid').check();await page.locator('#snap').check();
 const exportWait=page.waitForEvent('download');await page.locator('#export').click();const exported=await exportWait,exportBytes=fs.readFileSync(await exported.path());
 check('browser exports a PNG at selected dimensions',exportBytes.readUInt32BE(16)===1920&&exportBytes.readUInt32BE(20)===1080);
 const im=await loadImage(exportBytes),out=createCanvas(1920,1080);out.getContext('2d').drawImage(im,0,0);const pixel=(x,y)=>[...out.getContext('2d').getImageData(x,y,1,1).data].join(',');
 check('download retains logo and excludes preview grid',pixel(960,540)==='255,0,0,255'&&pixel(64,20)==='18,52,86,255');
 await page.waitForFunction(()=>document.getElementById('status').textContent.includes('Layout saved'),{timeout:10000});await page.reload();await page.locator('#status').filter({hasText:'Ready.'}).waitFor();
 check('custom wallpaper and logo restore after reload',await page.locator('#canvas').isVisible()&&(await page.locator('#canvas').evaluate(c=>Array.from(c.getContext('2d').getImageData(977,540,1,1).data))).join(',')==='255,0,0,255');
 await page.getByRole('tab',{name:/Our Collection/}).click();check('private uploads never enter public collection',await page.locator('.card').count()===1);
 const nojs=await browser.newContext({javaScriptEnabled:false});const fallback=await nojs.newPage();await fallback.goto(base);await fallback.getByRole('tab',{name:/Create a wallpaper/}).click();
 check('Create link works with JavaScript disabled',await fallback.locator('#create').isVisible());await nojs.close();
 const mobile=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true});const phone=await mobile.newPage();await phone.route('https://manage.hostingspell.com/**',r=>r.fulfill({contentType:'image/png',body:bgBytes}));await phone.goto(base);await phone.getByRole('tab',{name:/Create a wallpaper/}).click();await phone.locator('#status').filter({hasText:'Ready.'}).waitFor();
 check('Create is usable on a 390px mobile viewport',await phone.locator('#create').isVisible()&&await phone.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
 await phone.locator('#background-file').setInputFiles({name:'Mobile.png',mimeType:'image/png',buffer:bgBytes});await phone.locator('#status').filter({hasText:'Wallpaper ready.'}).waitFor();
 check('mobile custom upload completes',await phone.locator('#canvas').isVisible()&&await phone.locator('#export').isEnabled());
 if(process.env.E2E_SCREENSHOT_DIR){fs.mkdirSync(process.env.E2E_SCREENSHOT_DIR,{recursive:true});await phone.screenshot({path:path.join(process.env.E2E_SCREENSHOT_DIR,'mobile.png'),fullPage:true});await page.getByRole('tab',{name:/Create a wallpaper/}).click();await page.screenshot({path:path.join(process.env.E2E_SCREENSHOT_DIR,'desktop.png'),fullPage:true});}
 check('editor has no uncaught browser JavaScript errors',errors.length===0);
 console.log(`${passed} real-browser checks passed.`);
})().catch(e=>{console.error(e);console.error(serverLog);process.exitCode=1}).finally(async()=>{if(browser)await browser.close();php.kill('SIGTERM');fs.rmSync(tmp,{recursive:true,force:true});});
