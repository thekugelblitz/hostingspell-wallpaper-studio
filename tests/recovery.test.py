"""Disposable HTTP checks for fatal recovery, logs, and removed endpoint.
PHP_BIN, PHP_EXTRA_ARGS (JSON), PHP_LIBRARY_PATH may select a local PHP runtime.
Never injects fault hooks into the delivered application.
"""
import os, pathlib, tempfile, subprocess, json, urllib.request, urllib.error, http.cookiejar, re, time
source=pathlib.Path(__file__).resolve().parent.parent/'index.php'
env=os.environ.copy()
if env.get('PHP_LIBRARY_PATH'):env['LD_LIBRARY_PATH']=env['PHP_LIBRARY_PATH']
php=[env.get('PHP_BIN','php')]+json.loads(env.get('PHP_EXTRA_ARGS','[]'))
with tempfile.TemporaryDirectory(prefix='hs-recovery-') as folder:
    root=pathlib.Path(folder);app=root/'index.php';env['WPS_STORAGE']=str(root/'private')
    # Trigger a real uncatchable PHP memory fatal after shutdown registration.
    app.write_text(source.read_text().replace('    boot();', "    boot();\n    if (($_SERVER['HTTP_X_TEST_FATAL'] ?? '') === '1') str_repeat('x', 512 * 1024 * 1024);",1))
    with (root/'server.log').open('w') as log:
        process=subprocess.Popen(php+['-d','memory_limit=32M','-S','127.0.0.1:18766','-t',folder],env=env,stdout=log,stderr=log)
        try:
            opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));base='http://127.0.0.1:18766/index.php'
            for _ in range(50):
                try:html=opener.open(base,timeout=1).read();break
                except OSError:time.sleep(.1)
            config=json.loads(re.search(rb'<script id="studio-config"[^>]*>(.*?)</script>',html,re.S).group(1))
            def post(action,body,extra=None):
                headers={'X-CSRF-Token':config['csrf'],'Content-Type':'application/json',**(extra or {})}
                try:r=opener.open(urllib.request.Request(base+'?action='+action,data=body,headers=headers),timeout=10)
                except urllib.error.HTTPError as e:r=e
                return r.status,r.headers,json.loads(r.read())
            code,headers,data=post('state',b'{}',{'X-Test-Fatal':'1'})
            assert code==500 and data['reference']==headers['X-Studio-Request-ID']
            print('PASS: real PHP memory fatal returns JSON and a matching reference')
            logs=(root/'private/diagnostics.php').read_text()
            assert logs.startswith('<?php http_response_code(404); exit;') and data['reference'] in logs
            assert config['csrf'] not in logs
            print('PASS: bounded application log is PHP-guarded and excludes CSRF tokens')
            code,_,data=post('import',b'{}')
            assert code==404 and data['reference']
            print('PASS: removed import endpoint is rejected with JSON 404')
            code,_,data=post('save',json.dumps({'gridMode':'divisions','gridRows':40,'gridCols':6,'gridSub':4}).encode())
            assert code==200 and data['project']['layout']['gridRows']==24 and data['project']['layout']['gridCols']==6
            print('PASS: row/column settings persist with server-enforced limits')
            result=subprocess.run(php+[str(app),'--diagnostics'],env=env,capture_output=True,text=True)
            assert result.returncode==0 and 'memory_limit=' in result.stdout and 'fatal' in result.stdout
            print('PASS: private CLI diagnostics prints configuration and recent failure references')
        finally:process.terminate();process.wait(timeout=5)
