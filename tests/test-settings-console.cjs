// DOM doubles only; not browser rendering or WordPress persistence.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../assets/settings-console.js','utf8');
function form(){return {events:{},querySelector(q){return q==='.submit'?{appendChild:n=>this.status=n}:{}},addEventListener(k,f){this.events[k]=f}}}
const section={classList:{contains:()=>true},open:false,scrollIntoView(){},querySelector(){return this},hasAttribute:()=>true,focus(){}};
const forms=[form(),form()],window={location:{hash:'#ghca-branding'},events:{},confirm:()=>false,addEventListener(k,f){this.events[k]=f}};
const document={events:{},getElementById:()=>section,addEventListener(k,f){this.events[k]=f},body:{classList:{contains:()=>true}},querySelectorAll:q=>q.includes('nav a')?[]:forms,createElement:()=>({setAttribute(){}})};
vm.runInNewContext(source,{document,window,Set,Array});
assert(section.open,'Deep links open settings sections');
section.open=false;document.events.invalid({target:{closest:()=>section}});assert(section.open,'Invalid hidden fields are revealed');
forms[0].events.input();assert.match(forms[0].status.textContent,/Unsaved/);
let blocked=false;window.events.beforeunload({preventDefault(){blocked=true}});assert(blocked);
forms[1].events.change();let cancelled=false;forms[0].events.submit({preventDefault(){cancelled=true}});assert(cancelled,'Other form changes need confirmation');
window.confirm=()=>true;forms[0].events.submit({preventDefault(){throw Error('Unexpected cancel')}});
blocked=false;window.events.beforeunload({preventDefault(){blocked=true}});assert(!blocked,'Confirmed native submit can navigate');
assert(!/fetch\(|localStorage|\.submit\(/.test(source),'No replacement persistence or forced native submit');
console.log('PASS: dirty-state message, leave warning, cross-form cancellation/confirmation, native save boundary.');
