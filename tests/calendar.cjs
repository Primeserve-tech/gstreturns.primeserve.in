require('../public/gst-calendar.js');
const assert=require('node:assert/strict');
const c=globalThis.GSTCalendar;
assert.deepEqual(c.current(new Date('2026-12-31T18:29:59Z')),{year:2026,month:'12',day:'31',financialYear:'2026-27',financialStart:2026});
assert.deepEqual(c.current(new Date('2026-12-31T18:30:00Z')),{year:2027,month:'01',day:'01',financialYear:'2026-27',financialStart:2026});
assert.equal(c.current(new Date('2027-03-31T18:30:00Z')).financialYear,'2027-28');
assert.equal(c.current(new Date('2028-02-28T18:30:00Z')).day,'29');
console.log('PASS: India midnight, New Year, April financial-year rollover and leap day.');
// Exercise selectors on a page left open over midnight, without changing the system clock.
const NativeDate=Date;let now='2026-12-31T18:29:59Z',tick;
global.Date=class extends NativeDate {constructor(...args){super(...(args.length?args:[now]));}};
global.window={addEventListener(){}};
global.document={hidden:false,addEventListener(){},createElement(){return {value:'',textContent:''};}};
global.setInterval=fn=>{tick=fn;};
function select(){return {options:[],value:'',listeners:{},addEventListener(k,fn){this.listeners[k]=fn;},matches(){return false;},insertBefore(option,before){const index=this.options.indexOf(before);if(index<0)this.options.push(option);else this.options.splice(index,0,option);}};}
const year=select(),month=select();let changed=0;
c.bind(year,month,false,()=>changed++);
assert.equal(year.value,'2026');assert.equal(month.value,'12');
now='2026-12-31T18:30:00Z';tick();
assert.equal(year.value,'2027');assert.equal(month.value,'01');assert.equal(changed,1);
year.value='2025';year.listeners.change();now='2027-01-31T18:30:00Z';tick();
assert.equal(year.value,'2025');assert.equal(month.value,'01');
global.Date=NativeDate;
console.log('PASS: open-page New Year rollover adds 2027 and preserves manually chosen periods.');
