// Simulate the real rendered estimator and JS; only the HTTP boundary is stubbed.
const fs=require('fs'), path=require('path');
const {JSDOM,VirtualConsole}=require(process.env.IEB_JSDOM);
const d=process.env.IEB_CAPTURE_DIR, root=path.resolve(__dirname,'..');
if (!d) throw Error('capture directory required');
let html=fs.readFileSync(path.join(d,'index.html'),'utf8')
 .replace('<script src="/jquery.js"></script>',()=>'<script>'+fs.readFileSync(path.join(process.env.IEB_TEST_WP,'wp-includes/js/jquery/jquery.min.js'),'utf8')+'</script>')
 .replace('<script src="/multi-step-form.js"></script>',()=>'<script>'+fs.readFileSync(path.join(root,'assets/multi-step-form.js'),'utf8')+'</script>');
const dom=new JSDOM(html,{runScripts:'dangerously',virtualConsole:new VirtualConsole()}); const w=dom.window;
setTimeout(()=>{try {
 const steps=[...w.document.querySelectorAll('#multiStepForm .form-step')];
 for(let i=0;i<3;i++) { const s=steps[i].querySelector('select'); s.selectedIndex=1; s.dispatchEvent(new w.Event('change',{bubbles:true})); steps[i].querySelector('.hgm-next-btn').click(); }
 w.document.querySelector('#first_name').value='Test'; w.document.querySelector('#email').value='test@example.invalid';
 let p=w.document.querySelector('#phone'); p.value='2025550192'; p.dispatchEvent(new w.Event('input',{bubbles:true}));
 w.document.querySelector('#zip_code').value='61264'; w.document.querySelector('#multiStepForm').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
 setTimeout(()=>{ if(!w.captured || w.captured.action!=='hgm_submit_quote_form') { console.error('UI did not produce submission'); process.exitCode=1; } else { fs.writeFileSync(path.join(d,'payload.json'),JSON.stringify(w.captured)); console.log('PASS: actual form template and frontend JavaScript produced synthetic payload'); } w.close(); },200);
} catch(e) { console.error(e); process.exitCode=1; w.close(); }},100);
