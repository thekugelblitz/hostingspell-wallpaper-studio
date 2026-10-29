"""Run against a disposable local PHP server, never against a production workspace.
python3 tests/security_smoke.py http://127.0.0.1:8765/index.php
Uses only Python's standard library. Run with a fresh WPS_STORAGE directory.
"""
import http.cookiejar, urllib.request, urllib.error, json, re, sys, io, struct, zlib
BASE = sys.argv[1] if len(sys.argv) > 1 else 'http://127.0.0.1:8765/index.php'
checks = []
def check(name, condition):
    assert condition, name
    checks.append(name)
    print('PASS:', name)
def png(w=64,h=32):
    def chunk(t,b):return struct.pack('!I',len(b))+t+b+struct.pack('!I',zlib.crc32(t+b)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!2I5B',w,h,8,6,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+b'\xff\x80\x00\x90'*w)*h))+chunk(b'IEND',b'')
class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar();self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.code,self.headers,body=self.get(BASE)
        self.boot=json.loads(re.search(rb'<script id="studio-config"[^>]*>(.*?)</script>',body,re.S).group(1))
    def get(self,url):
        try:r=self.http.open(url,timeout=45)
        except urllib.error.HTTPError as e:r=e
        return r.code,r.headers,r.read()
    def api(self,action,fields=None,file=None,token=True,site=None,raw=None):
        headers={}
        if token:headers['X-CSRF-Token']=self.boot['csrf']
        if site:headers['Sec-Fetch-Site']=site
        if raw is not None:data=raw;headers['Content-Type']='application/json'
        elif file:
            boundary='smoketestboundary';b=io.BytesIO()
            for k,v in (fields or {}).items():b.write(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            name,mime,payload=file
            b.write(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode());b.write(payload);b.write(f'\r\n--{boundary}--\r\n'.encode())
            data=b.getvalue();headers['Content-Type']='multipart/form-data; boundary='+boundary
        else:data=urllib.parse.urlencode(fields or {}).encode()
        req=urllib.request.Request(BASE+'?action='+action,data=data,headers=headers)
        try:r=self.http.open(req,timeout=45)
        except urllib.error.HTTPError as e:r=e
        payload=r.read()
        return r.code,json.loads(payload)
a=Client();b=Client()
check('page and private cache headers',a.code==200 and 'no-store' in a.headers['Cache-Control'])
check('editor JavaScript endpoint works',a.get(BASE+'?action=app&v=1.1.0')[0]==200 and 'javascript' in a.get(BASE+'?action=app&v=1.1.0')[1]['Content-Type'])
check('JavaScript response contains no private CSRF token',a.boot['csrf'].encode() not in a.get(BASE+'?action=app')[2])
check('editor endpoint rejects POST',a.api('app')[0]==405)
create_code,_,create_html=a.get(BASE+'?tab=create')
check('Create has a server-rendered fallback',create_code==200 and re.search(rb'<section id="create"[^>]*hidden',create_html) is None and b'href="?tab=create"' in create_html)
check('Rocket Loader exclusion is present before script source',b'<script data-cfasync="false"' in create_html and b'?action=app&amp;v=1.1.0' in create_html)
check('explicit custom wallpaper upload is visible in Create markup',b'id="background-file"' in create_html and b'Upload your wallpaper' in create_html and b'25 MB' in create_html)
check('CSP and frame protection',"default-src 'none'" in a.headers['Content-Security-Policy'] and a.headers['X-Frame-Options']=='DENY')
cookie=next(iter(a.jar));check('HttpOnly and Strict cookie',cookie.has_nonstandard_attr('HttpOnly') and cookie.get_nonstandard_attr('SameSite')=='Strict')
check('separate browser tokens',a.boot['csrf']!=b.boot['csrf'])
check('CSRF missing rejected',a.api('state',token=False)[0]==403)
check('cross-site request rejected',a.api('state',site='cross-site')[0]==403)
code,data=a.api('state');check('project initialized',code==200 and data['project']['expires']==a.boot['expires'])
check('second project initialized',b.api('state')[0]==200)
check('wrong HTTP method rejected',a.get(BASE+'?action=save')[0]==405)
check('fake image rejected',a.api('upload',{'kind':'logo'},('exploit.png','image/png',b'<?php echo "bad"; ?>'))[0]==400)
check('logo byte limit enforced',a.api('upload',{'kind':'logo'},('big.png','image/png',png()+b'0'*(4*1024*1024)))[0]==400)
wide=bytearray(png());wide[16:24]=struct.pack('!II',5000,5000);wide[29:33]=struct.pack('!I',zlib.crc32(wide[12:29])&0xffffffff)
check('wallpaper pixel limit enforced',a.api('upload',{'kind':'background'},('wide.png','image/png',bytes(wide)))[0]==400)
check('SVG upload rejected',a.api('upload',{'kind':'logo'},('logo.svg','image/svg+xml',b'<svg xmlns="http://www.w3.org/2000/svg"></svg>'))[0]==400)
image=png()
code,data=a.api('upload',{'kind':'background'},('photo.png','image/png',image));check('valid wallpaper uploaded',code==200 and data['project']['background']['width']==64)
code,data=a.api('upload',{'kind':'logo'},('company.png','image/png',image));check('PNG logo decoded and normalized',code==200 and data['project']['logo']['mime']=='image/png')
code,headers,body=a.get(BASE+'?action=asset&kind=background');check('owner asset is raster data without PHP wrapper',code==200 and body==image and headers['Content-Type']=='image/png')
check('another session cannot read asset',b.get(BASE+'?action=asset&kind=background')[0]==404)
check('asset traversal rejected',a.get(BASE+'?action=asset&kind=../../secret')[0]==404)
check('invalid settings rejected',a.api('save',raw=b'{"width":7680,"height":7680}')[0]==400)
code,data=a.api('save',raw=json.dumps({'width':1080,'height':1920,'x':.25,'y':.75,'scale':.2,'snap':True}).encode())
check('layout persisted',code==200 and a.api('state')[1]['project']['layout']['x']==.25)
check('absolute expiry unchanged after activity',data['project']['expires']==a.boot['expires'])
for label,url in [('off-domain','https://example.com/photos/cPccYbPrF-A'),('credentials','https://u:p@unsplash.com/photos/cPccYbPrF-A'),('port','https://unsplash.com:443/photos/cPccYbPrF-A'),('wrong path','https://unsplash.com/collections/cPccYbPrF-A'),('loopback','http://127.0.0.1/'),('bad ID','https://unsplash.com/photos/short')]:
    check('import rejects '+label,a.api('import',{'url':url})[0]==400)
check('import rate limit enforced',a.api('import',{'url':'https://example.com'})[0]==429)
check('logo removal works',a.api('remove_logo')[0]==200 and 'logo' not in a.api('state')[1]['project'])
check('deletion works',a.api('clear')[0]==200)
check('deleted cookie loses access',a.get(BASE+'?action=asset&kind=background')[0]==410)
check('second session remains valid',b.api('state')[0]==200)
original_cookie=next(iter(b.jar));original_value=original_cookie.value;original_cookie.value=original_value[:-1]+('0' if original_value[-1]!='0' else '1')
check('tampered session signature rejected',b.api('state')[0]==410)
original_cookie.value=original_value
print(f'{len(checks)} checks passed. Live Unsplash and browser interactions are separate integration checks.')
