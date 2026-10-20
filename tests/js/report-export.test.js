const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {JSDOM}=require('jsdom');
const script=fs.readFileSync('public/assets/js/report-export.js','utf8');
function setup(html,available=true){
 const dom=new JSDOM('<body><aside class="sidebar">NAVIGATION</aside><main class="main_content"><div id="page-print-area">'+html+'</div></main><footer>FOOTER</footer></body>',{runScripts:'outside-only'});
 let options,exported,printed=0;
 const worker={set(value){options=value;return this;},from(value){exported=value;return this;},save(){return Promise.resolve();}};
 dom.window.print=()=>{printed++;dom.window.dispatchEvent(new dom.window.Event('beforeprint'));};
 if(available)dom.window.html2pdf=()=>worker;
 dom.window.eval(script);
 return {window:dom.window,document:dom.window.document,result:()=>({options,exported,printed})};
}
const report=`<h2>Fee report</h2><aside class="print-hidden">SETUP HELP</aside><form><label for="search">Search students</label><input id="search" value="filter"><button>Filter</button></form><div class="pagination">Next page</div><table id="fees"><thead><tr><th>Name</th><th>Amount</th><th>Actions</th></tr></thead><tbody><tr><td>Alice</td><td>500</td><td><a class="btn">Edit</a></td></tr></tbody><tfoot><tr><td colspan="3">Total: 500</td></tr></tfoot></table>`;

test('PDF keeps report values and totals while excluding controls and action columns',async()=>{
 const c=setup(report);
 await c.window.exportReportPdf(c.document.getElementById('page-print-area'),'fees.pdf');
 const {options,exported}=c.result();
 assert.match(exported.textContent,/Alice/);assert.match(exported.textContent,/Total: 500/);
 assert.doesNotMatch(exported.textContent,/NAVIGATION|FOOTER|Search students|Filter|Next page|Actions|Edit|SETUP HELP/);
 assert.equal(exported.querySelector('tbody tr').cells.length,2);
 assert.equal(exported.querySelector('tfoot td').colSpan,2);
 assert.equal(c.document.querySelector('#fees tbody tr').cells.length,3);
 assert.equal(c.document.querySelector('#search').value,'filter');
 assert.equal(options.filename,'fees.pdf');assert.equal(options.margin,12);
});

test('printing isolates the selected document without replacing the live page',()=>{
 const c=setup('<p>UNRELATED PAGE CONTENT</p><div id="printableDiv"><h2>Invoice</h2><p>Total: 700</p><button>Pay now</button></div>');
 const original=c.document.querySelector('main');
 c.window.printReport('page-print-area');
 const output=c.document.getElementById('report-print-output');
 assert.match(output.textContent,/Invoice/);assert.match(output.textContent,/Total: 700/);
 assert.doesNotMatch(output.textContent,/UNRELATED|Pay now/);
 assert(c.document.body.classList.contains('report-printing'));
 assert.equal(c.document.querySelector('main'),original);
 c.window.dispatchEvent(new c.window.Event('afterprint'));
 assert.equal(c.document.getElementById('report-print-output'),null);
 assert(!c.document.body.classList.contains('report-printing'));
 assert.equal(c.document.querySelector('main'),original);
});

test('native browser printing uses the same cleaned report',()=>{
 const c=setup(report);
 c.window.dispatchEvent(new c.window.Event('beforeprint'));
 const output=c.document.getElementById('report-print-output');
 assert.match(output.textContent,/Alice/);
 assert.doesNotMatch(output.textContent,/Filter|Actions|Edit|Next page/);
});

test('PDF fallback prints only the report and missing targets do not print the page',async()=>{
 const c=setup(report,false);
 await c.window.exportReportPdf(c.document.getElementById('fees'));
 assert.equal(c.result().printed,1);
 assert.doesNotMatch(c.document.getElementById('report-print-output').textContent,/Actions|Edit|Filter/);
 const missing=setup(report);
 await missing.window.exportReportPdf(null);
 missing.window.printReport('missing-report');
 assert.equal(missing.result().printed,0);
});

test('table-only exports remove action columns and choose orientation using data columns',async()=>{
 const columns=Array.from({length:7},(_,i)=>'<th>Data '+i+'</th>').join('');
 const cells=Array.from({length:7},(_,i)=>'<td>Value '+i+'</td>').join('');
 const c=setup('<table id="wide"><thead><tr>'+columns+'<th>Actions</th></tr></thead><tbody><tr>'+cells+'<td><button>Edit</button></td></tr></tbody></table>');
 await c.window.exportReportPdf(c.document.getElementById('wide'));
 assert.equal(c.result().options.jsPDF.orientation,'landscape');
 assert.equal(c.result().exported.style.width,'273mm');
 assert.equal(c.result().exported.querySelector('tbody tr').cells.length,7);
 assert(c.result().options.pagebreak.avoid.includes('tr'));
});

test('empty checkbox selection columns are excluded but document links remain',async()=>{
 const c=setup('<table id="items"><thead><tr><th><input type="checkbox"></th><th>Resource</th></tr></thead><tbody><tr><td><input type="checkbox"></td><td><a href="/library/book">Course handbook</a></td></tr></tbody></table>');
 await c.window.exportReportPdf(c.document.getElementById('items'));
 const output=c.result().exported;
 assert.equal(output.querySelector('tbody tr').cells.length,1);
 assert.equal(output.querySelector('a').textContent,'Course handbook');
});

test('explicit report regions exclude other page sections for both print and PDF',async()=>{
 const c=setup('<h2>Dashboard</h2><p>OTHER PAGE ITEMS</p><section data-print-root><h3>Transcript</h3><p>Final grade: A</p></section>');
 await c.window.exportReportPdf(c.document.getElementById('page-print-area'));
 assert.match(c.result().exported.textContent,/Final grade: A/);
 assert.doesNotMatch(c.result().exported.textContent,/Dashboard|OTHER PAGE ITEMS/);
 c.window.printReport('page-print-area');
 assert.doesNotMatch(c.document.getElementById('report-print-output').textContent,/OTHER PAGE ITEMS/);
});

test('report tables inside forms retain data while selection controls disappear',async()=>{
 const c=setup('<form><label for="filter">Choose period</label><select id="filter"><option>FILTER VALUE</option></select><table><tr><td>Student tuition</td><td>900</td></tr></table><button>Save</button></form>');
 await c.window.exportReportPdf(c.document.getElementById('page-print-area'));
 assert.match(c.result().exported.textContent,/Student tuition/);
 assert.match(c.result().exported.textContent,/900/);
 assert.doesNotMatch(c.result().exported.textContent,/Choose period|FILTER VALUE|Save/);
});
