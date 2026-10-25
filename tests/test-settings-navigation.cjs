// Native navigation remains the default for other admin pages and modified clicks.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const base='https://synthetic.test/wp-admin/admin.php?page=ghca-acd-settings';
const location={};
function navigate(value){const u=new URL(value,base);for(const key of ['href','origin','pathname','search','hash'])location[key]=u[key]}
navigate(base+'#ghca-branding');
const sections={};
for(const id of ['ghca-training','ghca-branding','ghca-performance','ghca-packet-selection']){
  sections[id]={open:false,scrolled:0,focused:0,classList:{contains:()=>id!=='ghca-packet-selection'},scrollIntoView(){this.scrolled++},querySelector(){return this},hasAttribute:()=>true,focus(){this.focused++}};
}
function link(href){return {href,events:{},attrs:{},addEventListener(k,f){this.events[k]=f},setAttribute(k,v){this.attrs[k]=v},removeAttribute(k){delete this.attrs[k]}}}
const links=[link(base),link(base+'#ghca-branding'),link(base+'#ghca-performance'),link(base+'#ghca-packet-selection'),link('https://synthetic.test/wp-admin/admin.php?page=ghca-acd-audit-mapping')];
const window={location,events:{},history:{pushState(a,b,url){navigate(url)}},addEventListener(k,f){this.events[k]=f}};
const document={events:{},body:{classList:{contains:()=>true}},getElementById:id=>sections[id],querySelectorAll:s=>s.includes('nav a')?links:[],addEventListener(k,f){this.events[k]=f}};
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/settings-console.js','utf8'),{document,window,Set,Array,URL});
assert(sections['ghca-branding'].open,'Initial deep link opens section');
function click(target,extra={}){let prevented=false;target.events.click({button:0,preventDefault(){prevented=true},...extra});return prevented}
sections['ghca-branding'].open=false;
assert(click(links[1]));assert(sections['ghca-branding'].open,'Same-hash click reopens section');assert.equal(links[1].attrs['aria-current'],'location');assert(sections['ghca-branding'].focused);
assert(click(links[2]));assert.equal(location.hash,'#ghca-performance');assert.equal(links[1].attrs['aria-current'],undefined);
assert(click(links[3]));assert(sections['ghca-packet-selection'].focused,'Normal section headings receive focus');
assert(click(links[0]));assert.equal(location.hash,'#ghca-training');
assert.equal(click(links[4]),false,'Different admin page retains native navigation');
assert.equal(click(links[1],{ctrlKey:true}),false,'Modified clicks retain new-tab behavior');
assert.equal(click(links[1],{button:1}),false,'Middle click not intercepted');
navigate(base+'#ghca-branding');sections['ghca-branding'].open=false;window.events.hashchange();assert(sections['ghca-branding'].open,'Back/forward hash navigation reveals target');
console.log('PASS: deep links, repeated clicks, focus/current state, packet/training links, native cross-page and modified clicks.');
