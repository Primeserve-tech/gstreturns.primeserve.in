'use strict';
globalThis.GSTCalendar = (() => {
  function current(date = new Date()) {
    const parts = new Intl.DateTimeFormat('en', {timeZone:'Asia/Kolkata', year:'numeric', month:'2-digit', day:'2-digit'}).formatToParts(date);
    const part = name => parts.find(item => item.type === name).value;
    const year = Number(part('year')), month = part('month');
    const start = year - (Number(month) < 4 ? 1 : 0);
    return {year, month, day:part('day'), financialYear:`${start}-${String(start + 1).slice(-2)}`, financialStart:start};
  }
  function bind(yearSelect, monthSelect, financial, changed = () => {}) {
    let manual = false, previous = '';
    for (const select of [yearSelect, monthSelect]) select.addEventListener('change', () => {manual = true;});
    function sync(notify = true) {
      const date = current();
      const key = `${date.year}-${date.month}`;
      const top = financial ? date.financialStart : date.year;
      // Add new years even when the page was left open across the rollover.
      for (let year = top; year >= 2017; year--) {
        const value = financial ? `${year}-${String(year + 1).slice(-2)}` : String(year);
        if (![...yearSelect.options].some(option => option.value === value)) {
          const option = document.createElement('option'); option.value = value; option.textContent = value;
          const before = [...yearSelect.options].find(item => item.value < value);
          yearSelect.insertBefore(option, before || null);
        }
      }
      if (!manual && key !== previous && !yearSelect.matches(':focus') && !monthSelect.matches(':focus')) {
        yearSelect.value = financial ? date.financialYear : String(date.year);
        monthSelect.value = date.month;
        previous = key;
        if (notify) changed();
      }
    }
    sync(false);
    setInterval(sync, 15000);
    window.addEventListener('pageshow', () => sync());
    document.addEventListener('visibilitychange', () => {if (!document.hidden) sync();});
    return sync;
  }
  return {current, bind};
})();
