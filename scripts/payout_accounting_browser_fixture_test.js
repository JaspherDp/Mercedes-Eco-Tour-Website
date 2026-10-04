'use strict';
// Exercise the actual provider aggregation/render functions without browser or network access.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/adearningsdisbursements.js'), 'utf8');
const start = source.indexOf('  function aggregateProviders(');
const end = source.indexOf('  function updatePageSummary(', start);
const label = source.match(/const totalLabel=([^;]+);/)[0];
const context = {
  peso: new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'}),
  providerBalanceBody: {innerHTML: ''}, exposureList: null, exposureTotal: null, exposureProviderCount: null,
  stakeholderLabels: {boat: 'Boat'}, escapeHtml: value => String(value)
};
vm.createContext(context);
vm.runInContext(label + source.slice(start, end), context);
const row = {provider_key: 'boat:1', provider_name: 'Fixture', provider_type: 'boat', state: 'pending', payout: null};
context.renderProviderScope([row]);
assert.match(context.providerBalanceBody.innerHTML, /Pending review/);
assert.equal(context.aggregateProviders([row])[0].unknown.pending, 1);
context.renderProviderScope([row, {...row, payout: 567.36}]);
assert.match(context.providerBalanceBody.innerHTML, /Pending review/);
assert.doesNotMatch(context.providerBalanceBody.innerHTML, /\+ pending/);
context.renderProviderScope([{...row, payout: 567.36}]);
assert.match(context.providerBalanceBody.innerHTML, /567\.36/);
assert.doesNotMatch(context.providerBalanceBody.innerHTML, /pending|Pending review/);
console.log('Passed provider summary rendering checks (unknown, partial and verified totals).');
