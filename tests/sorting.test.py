"""Test collection ordering against a disposable PHP server and generated images."""
import os,pathlib,tempfile,subprocess,json,urllib.request,re,time,struct,zlib
source=pathlib.Path(__file__).resolve().parent.parent/'index.php'
env=os.environ.copy()
if env.get('PHP_LIBRARY_PATH'):env['LD_LIBRARY_PATH']=env['PHP_LIBRARY_PATH']
php=[env.get('PHP_BIN','php')]+json.loads(env.get('PHP_EXTRA_ARGS','[]'))
def png(w,h):
    def chunk(t,b):return struct.pack('!I',len(b))+t+b+struct.pack('!I',zlib.crc32(t+b)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('!2I5B',w,h,8,6,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+b'\xff\x80\x00\xff'*w)*h))+chunk(b'IEND',b'')
with tempfile.TemporaryDirectory(prefix='hs-sort-') as folder:
    root=pathlib.Path(folder);(root/'walls').mkdir();(root/'index.php').write_bytes(source.read_bytes());env['WPS_STORAGE']=str(root/'private')
    for i,(name,w) in enumerate([('Wallpaper 10',10),('Wallpaper 2',30),('apple',20)]):
        f=root/'walls'/f'{name}.png';f.write_bytes(png(w,10)+b' '*(1000*(i+1)));os.utime(f,(1700000000+i,1700000000+i))
    with (root/'server.log').open('w') as log:
        process=subprocess.Popen(php+['-S','127.0.0.1:18767','-t',folder],env=env,stdout=log,stderr=log)
        try:
            base='http://127.0.0.1:18767/index.php'
            for _ in range(50):
                try:urllib.request.urlopen(base,timeout=1);break
                except OSError:time.sleep(.1)
            def page(query=''):
                html=urllib.request.urlopen(base+query).read().decode();config=json.loads(re.search(r'<script id="studio-config"[^>]*>(.*?)</script>',html,re.S).group(1));return html,[x['name'] for x in config['items']]
            az=['apple','Wallpaper 2','Wallpaper 10'];za=az[::-1]
            for key,expected in [('name-asc',az),('name-desc',za),('newest',['apple','Wallpaper 2','Wallpaper 10']),('oldest',['Wallpaper 10','Wallpaper 2','apple']),('size-desc',['apple','Wallpaper 2','Wallpaper 10']),('size-asc',['Wallpaper 10','Wallpaper 2','apple']),('resolution-desc',['Wallpaper 2','apple','Wallpaper 10']),('resolution-asc',['Wallpaper 10','apple','Wallpaper 2'])]:
                html,names=page('?sort='+key);assert names==expected,(key,names);assert re.search(r'value="'+key+r'" selected',html);assert re.findall(r'<article class="card" data-name="([^"]+)"',html)==expected;print('PASS: '+key+' sorts server HTML and viewer data consistently')
            assert page()[1]==az and page('?sort=invalid')[1]==az
            print('PASS: default and invalid sort use natural name order')
            html,_=page();assert 'href="https://github.com/thekugelblitz/hostingspell-wallpaper-studio"' in html and '<svg viewBox="0 0 24 24"' in html
            print('PASS: public source link includes an inline GitHub icon')
        finally:process.terminate();process.wait(timeout=5)
