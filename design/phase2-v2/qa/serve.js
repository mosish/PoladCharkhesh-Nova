// Static server that mimics production: precompressed-style gzip, long cache for /assets, no-cache for HTML/JSON.
const http=require('http'),fs=require('fs'),path=require('path'),zlib=require('zlib');
const root=path.resolve(process.argv[2]||'../site'), port=+process.argv[3]||8090;
const types={'.html':'text/html; charset=utf-8','.css':'text/css','.js':'text/javascript','.json':'application/json','.woff2':'font/woff2','.svg':'image/svg+xml','.png':'image/png','.webp':'image/webp','.txt':'text/plain'};
http.createServer((req,res)=>{let u=decodeURIComponent(req.url.split('?')[0]); if(u.endsWith('/'))u+='index.html';
 const f=path.join(root,u); if(!f.startsWith(root)||!fs.existsSync(f)||fs.statSync(f).isDirectory()){res.writeHead(404);return res.end('404');}
 const ext=path.extname(f), buf=fs.readFileSync(f), h={'Content-Type':types[ext]||'application/octet-stream','Cache-Control':u.startsWith('/assets/')?'public, max-age=31536000, immutable':'no-cache'};
 if(ext!=='.woff2'&&ext!=='.png'&&ext!=='.webp'&&/gzip/.test(req.headers['accept-encoding']||'')){h['Content-Encoding']='gzip';res.writeHead(200,h);return res.end(zlib.gzipSync(buf,{level:9}));}
 res.writeHead(200,h);res.end(buf);}).listen(port,()=>console.log('serving',root,'on',port));
