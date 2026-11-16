const {chromium}=require('playwright');const fs=require('fs');
(async()=>{const [mode,dur]=[process.argv[2],+process.argv[3]];const fps=30;
const b=await chromium.launch();const pg=await b.newPage({viewport:{width:1080,height:1920}});
await pg.goto('file://'+__dirname+'/anim.html');await pg.evaluate(m=>build(m),mode);await pg.waitForTimeout(500);
fs.mkdirSync('f_'+mode,{recursive:true});
const N=Math.round(dur*fps);
for(let i=0;i<N;i++){await pg.evaluate(t=>render(t),i/fps);await pg.screenshot({path:`f_${mode}/${String(i).padStart(4,'0')}.png`})}
await b.close();console.log(mode,N)})();
