from pathlib import Path
root=Path(__file__).resolve().parent
p=root/'index.php';s=p.read_text();a=s.index("    return <<<'WALLPAPER_APP_JS'",s.index('function applicationJS'));b=s.index('\nWALLPAPER_APP_JS;',a);s=s[:a]+"    return <<<'WALLPAPER_APP_JS'\n"+(root/'editor-source.js').read_text().rstrip()+s[b:];p.write_text(s)
