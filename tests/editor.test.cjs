/* Node 18+: npm install --no-save @napi-rs/canvas
   Run: node tests/editor.test.cjs
   Executes the actual inline editor code in a small DOM harness with a native raster canvas.
   This is not a full browser/visual-layout test. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const {createCanvas}=require('@napi-rs/canvas');
const source=fs.readFileSync(require('node:path').join(__dirname,'../index.php'),'utf8');
let code=source.match(/return <<<'WALLPAPER_APP_JS'\n([\s\S]*?)WALLPAPER_APP_JS;/)[1].replace(/^\(\(\) => \{\n/,'').replace(/\n\}\)\(\);\n$/,'');
code=code.replace(/const BOOT = JSON.parse\([^\n]+;/,"const BOOT = {script:'index.php',csrf:'test',expires:Math.floor(Date.now()/1000)+86400,items:[]};");
let passed=0;
function check(name, fn){fn();console.log('PASS:',name);passed++;}
class Element {
 constructor(){this.value='';this.checked=false;this.hidden=false;this.disabled=false;this.options=[];this.dataset={};this.style={};this.events={};this.files=[];this.textContent='';this.classList={add(){},remove(){},toggle(){return true}};}
 addEventListener(name,fn){this.events[name]=fn;}setAttribute(){}focus(){}append(){}remove(){}scrollTo(){}setPointerCapture(){}getBoundingClientRect(){return{left:0,top:0,width:1920,height:1080}}close(){}showModal(){}click(){}
}
const elements=new Map();function element(id){if(!elements.has(id))elements.set(id,new Element());return elements.get(id);}
const canvas=createCanvas(1920,1080);canvas.addEventListener=(n,f)=>{canvas[n]=f};canvas.getBoundingClientRect=()=>({left:0,top:0,width:1920,height:1080});canvas.setPointerCapture=()=>{};canvas.focus=()=>{};
elements.set('canvas',canvas);
const context={console,document:{getElementById:element,querySelectorAll:()=>[],createElement:()=>new Element(),body:new Element()},setTimeout:()=>1,clearTimeout(){},setInterval(){},history:{replaceState(){}},URL,Blob,AbortController,FormData,location:{reload(){}},confirm:()=>true,fetch:()=>{throw Error('Unexpected network in geometry tests')}};
vm.createContext(context);vm.runInContext(code,context);
const bg=createCanvas(320,180);bg.naturalWidth=320;bg.naturalHeight=180;const bc=bg.getContext('2d');bc.fillStyle='#123456';bc.fillRect(0,0,320,180);
const logo=createCanvas(100,50);logo.naturalWidth=100;logo.naturalHeight=50;const lc=logo.getContext('2d');lc.fillStyle='#ff0000';lc.fillRect(0,0,100,50);
context.testBG=bg;context.testLogo=logo;
const run=s=>vm.runInContext(s,context);
run('background=testBG;logo=testLogo;');
check('logo stays inside canvas at extreme positions',()=>{for(const p of [-5,0,.5,1,5]){run(`layout.x=${p};layout.y=${p};`);const r=run('logoRect()');assert.ok(r.x>=0&&r.y>=0&&r.x+r.w<=1920&&r.y+r.h<=1080)}});
check('tall logos are constrained by canvas height',()=>{run('layout.scale=1;');logo.naturalWidth=10;logo.naturalHeight=100;const r=run('logoRect()');assert.ok(r.h<=1080&&r.w<=1920);logo.naturalWidth=100;logo.naturalHeight=50});
check('snap rounds logo center independently of grid visibility',()=>{run('layout.scale=.22;layout.snap=true;layout.grid=false;layout.gridSize=64;moveLogo(501,501);');assert.equal(run('layout.x*layout.width'),512);assert.equal(run('layout.y*layout.height'),512)});
check('snap off permits free placement',()=>{run('layout.snap=false;moveLogo(501,501);');assert.equal(run('layout.x*layout.width'),501)});
check('pointer dragging moves logo and finishes the drag',()=>{
 run('layout.x=.5;layout.y=.5;layout.snap=false;');
 canvas.pointerdown({pointerId:7,clientX:960,clientY:540,preventDefault(){}});
 canvas.pointermove({pointerId:7,clientX:1060,clientY:590});canvas.pointerup({pointerId:7});
 assert.equal(run('layout.x*layout.width'),1060);assert.equal(run('layout.y*layout.height'),590);assert.equal(run('drag'),null);
});
check('keyboard shift movement uses ten-pixel steps',()=>{
 canvas.keydown({key:'ArrowRight',shiftKey:true,preventDefault(){}});assert.equal(run('layout.x*layout.width'),1070);
});
check('4K dimensions accepted',()=>{run('resize(3840,2160)');assert.equal(canvas.width,3840);assert.equal(canvas.height,2160)});
check('over-budget dimensions rejected',()=>{run('resize(7680,7680)');assert.equal(canvas.width,3840)});
check('guides do not appear in exported painting',()=>{
 run('resize(1920,1080);layout.x=.5;layout.y=.5;layout.scale=.2;layout.opacity=1;layout.grid=true;layout.gridSize=64;render();');
 const clean=createCanvas(1920,1080);context.clean=clean;run('paint(clean.getContext("2d"),false)');
 const pixel=(c,x,y)=>[...c.getContext('2d').getImageData(x,y,1,1).data];
 assert.deepEqual(pixel(clean,64,20),[18,52,86,255]);assert.notDeepEqual(pixel(canvas,64,20),pixel(clean,64,20));
 assert.deepEqual(pixel(clean,960,540),[255,0,0,255]);
 const png=clean.toBuffer('image/png');assert.ok(png.length>100);assert.equal(png.readUInt32BE(16),1920);assert.equal(png.readUInt32BE(20),1080);
});
check('row and column subdivisions snap independently with guides hidden',()=>{
 run('layout.gridMode="divisions";layout.gridRows=3;layout.gridCols=4;layout.gridSub=2;layout.snap=true;layout.grid=false;moveLogo(501,501);');
 assert.equal(run('layout.x*layout.width'),480);assert.equal(run('layout.y*layout.height'),540);
 run('layout.gridMode="pixels";layout.snap=false;');
});
check('corner resizing preserves logo ratio and anchors top-left',()=>{
 run('layout.x=.5;layout.y=.5;layout.scale=.2;');const before=run('logoRect()');
 canvas.pointerdown({pointerId:8,clientX:before.x+before.w,clientY:before.y+before.h,preventDefault(){}});
 canvas.pointermove({pointerId:8,clientX:before.x+before.w+100,clientY:before.y+before.h+50});canvas.pointerup({pointerId:8});
 const after=run('logoRect()');assert.ok(after.w>before.w);assert.ok(Math.abs(after.x-before.x)<1e-8);assert.ok(Math.abs(after.y-before.y)<1e-8);assert.equal(after.w/after.h,2);
});
check('contain fit preserves complete image with black padding',()=>{
 run('layout.fit="contain";layout.width=1000;layout.height=1000;layout.grid=false;logo=null;');
 const out=createCanvas(1000,1000);context.clean=out;run('paint(clean.getContext("2d"),false)');
 assert.deepEqual([...out.getContext('2d').getImageData(0,0,1,1).data],[0,0,0,255]);
 assert.deepEqual([...out.getContext('2d').getImageData(500,500,1,1).data],[18,52,86,255]);
});
console.log(passed+' editor checks passed. Real browser/mobile layout still needs deployment acceptance testing.');
