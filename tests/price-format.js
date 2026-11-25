// Runs the value formatter from assets/notify.js on its own: node tests/price-format.js
const fs = require('fs');
const src = fs.readFileSync(__dirname + '/../assets/notify.js', 'utf8');
const start = src.indexOf('	const builtIn = {');
const end = src.indexOf('	const loadSlip = () => {');
if (start < 0 || end < start) { console.error('formatter block not found'); process.exit(1); }
let fail = 0, run = 0;
const check = (name, ok) => { run++; if (!ok) { fail++; console.log('FAIL: ' + name); } };
const make = (cfg, storage) => {
	const window = {};
	const localStorage = { getItem: (k) => (k in storage ? storage[k] : null) };
	return { window, price: new Function('cfg', 'window', 'localStorage', src.slice(start, end) + '; return price;')(cfg, window, localStorage) };
};
let t = make({}, {});
check('default is decimal', t.price(2.5) === '2.50');
check('unknown value falls back to text', t.price(0, '3/1') === '3/1' && t.price(undefined) === '');
t = make({ format: { storage: 'k', default: 'percent' } }, {});
check('percent', t.price(4) === '25%');
t = make({ format: { storage: 'k', default: 'percent' } }, { k: 'decimal' });
check('reader choice beats default', t.price(4) === '4.00');
t = make({ format: { storage: 'k', default: 'custom' } }, {});
check('unregistered format uses the text', t.price(3, '2/1') === '2/1' && t.price(3) === '3.00');
t.window.aceNudgeFormats = { custom: (n, a) => 'x' + (a || n) };
check('registered format is used, even late', t.price(3, 'a') === 'xa');
t.window.aceNudgeFormats = { custom: () => { throw new Error('boom'); } };
check('a broken format cannot break the page', t.price(3, 'a') === 'a');
t.window.aceNudgeFormats = { custom: () => '' };
check('an empty answer is respected', t.price(3, 'a') === '');
console.log(run + ' checks, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
