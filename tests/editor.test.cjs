const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),{createCanvas}=require('@napi-rs/canvas');
const source=fs.readFileSync(path.join(__dirname,'../editor-source.js'),'utf8'),php=fs.readFileSync(path.join(__dirname,'../index.php'),'utf8');let passed=0;
function check(name,fn){fn();console.log('PASS:',name);passed++;}
function functionSource(name){const start=source.indexOf('function '+name+'(');assert.ok(start>=0);let depth=0,body=source.indexOf('{',start),i=body;for(;i<source.length;i++){if(source[i]==='{')depth++;if(source[i]==='}'&&!--depth)break;}return source.slice(start,i+1);}
const context={doc:{width:1920,height:1080,gridMode:'pixels',gridSize:64,gridRows:3,gridCols:4,gridSub:2},Math,Uint8Array,DataView,TextEncoder,Blob};vm.createContext(context);
for(const name of ['rect','localPoint','steps','path'])vm.runInContext(functionSource(name),context);
check('embedded production JavaScript matches development source',()=>assert.equal(php.match(/return <<<'WALLPAPER_APP_JS'\n([\s\S]*?)\nWALLPAPER_APP_JS;/)[1],source.trimEnd()));
check('normalized layer positions and dimensions match canvas size',()=>{const r=context.rect({x:.5,y:.5,w:.2,h:.1});assert.equal(r.x,960);assert.equal(r.y,540);assert.equal(r.w,384);assert.equal(r.h,108);});
check('rotated pointer coordinates map into object space',()=>{const p=context.localPoint({x:960,y:640},{x:.5,y:.5,w:.2,h:.2,rotation:90});assert.ok(Math.abs(p.x-100)<.001);assert.ok(Math.abs(p.y)<.001);});
check('row and column snapping uses independent subdivisions',()=>{context.doc.gridMode='divisions';const s=context.steps();assert.equal(s.x,240);assert.equal(s.y,180);context.doc.gridMode='pixels';assert.equal(context.steps().x,64);});
for(const shape of ['rectangle','square','rounded','circle','ellipse','triangle','diamond','pentagon','hexagon','polygon','star','arrow','line'])check(shape+' creates a finite drawable shape path',()=>{const c=createCanvas(200,200),ctx=c.getContext('2d');ctx.translate(100,100);context.path(ctx,{shape,radius:15,sides:8,points:5,inner:.45},120,100);ctx.fillStyle='#ff842e';ctx.fill();ctx.strokeStyle='#fff';ctx.stroke();assert.ok(c.toBuffer('image/png').length>100);});
console.log(`${passed} editor checks passed.`);
