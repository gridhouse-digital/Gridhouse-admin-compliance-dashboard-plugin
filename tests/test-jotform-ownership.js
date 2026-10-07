const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(__dirname + '/../assets/jotform-ownership.js', 'utf8');
const observers = [];
const iframes = [];
let requests = 0;
const document = {
  readyState: 'complete',
  documentElement: {},
  querySelectorAll: () => iframes,
};
const window = {
  location: { href: 'https://academy.test/course/' },
  fetch: async () => ({ ok: true, json: async () => ({ success: true, data: { userId: 42, claim: 'fresh-signed-claim' } }) }),
};
window.fetch = async (...args) => { requests++; return { ok: true, json: async () => ({ success: true, data: { userId: 42, claim: 'fresh-signed-claim' } }) }; };
class MutationObserver { constructor(callback) { this.callback = callback; observers.push(callback); } observe() {} }
function iframe(src) { return { src, dataset: {}, nodeType: 1, matches: selector => selector === 'iframe', querySelectorAll: () => [] }; }
const context = { window, document, MutationObserver, URL, URLSearchParams, Promise, setTimeout, clearTimeout };
window.ghcaAcdJotformOwnershipConfig = { ajaxUrl: '/ajax', nonce: 'nonce', formIds: ['123456'] };
const partial = iframe('https://form.jotform.com/123456/?user_id=99');
iframes.push(partial);
vm.runInNewContext(source, context);

function tick() { return new Promise(resolve => setTimeout(resolve, 0)); }
function check(condition, message) { console.log((condition ? 'PASS: ' : 'FAIL: ') + message); if (!condition) process.exitCode = 1; }

(async () => {
  await tick(); await tick();
  let bound = new URL(partial.src);
  check(requests === 1 && bound.searchParams.get('user_id') === '42' && bound.searchParams.get('ghca_ownership_claim') === 'fresh-signed-claim' && partial.dataset.ghcaOwnershipBound === '1', 'partial prefill is atomically replaced with a fresh signed claim');
  observers[0]([{ addedNodes: [partial] }]);
  await tick();
  check(requests === 1, 'the bound iframe is not reloaded by a later observer pass');

	const dynamic = iframe('https://eu.jotform.com/123456/');
	let mutationThrew = false;
	try { observers[0]([{ addedNodes: [{ nodeType: 3, textContent: 'embed loading' }, dynamic] }]); } catch (error) { mutationThrew = true; }
	await tick(); await tick();
	bound = new URL(dynamic.src);
	check(!mutationThrew && requests === 2 && bound.searchParams.get('user_id') === '42' && bound.searchParams.get('ghca_ownership_claim') === 'fresh-signed-claim' && dynamic.dataset.ghcaOwnershipBound === '1', 'a text-node mutation before a configured iframe does not throw and binds once');
  if (!process.exitCode) console.log('\nALL PASS');
})();
