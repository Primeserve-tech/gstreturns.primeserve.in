'use strict';
window.PrimeserveBilling={
  async confirm(service,count){
    const response=await fetch('/customer/quote',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="customer-csrf"]').content},body:JSON.stringify({service,count})});
    const quote=await response.json();if(!response.ok)throw Error(quote.error||'Unable to obtain the current price.');
    const money=n=>'₹'+(n/100).toFixed(2);const dialog=document.createElement('dialog');dialog.className='billing-confirm';const title=document.createElement('h2');title.textContent='Confirm API charge';const text=document.createElement('p');text.style.whiteSpace='pre-line';text.textContent=`${quote.count} × ${quote.unit}\nTaxable value: ${money(quote.net)}\nGST @ 18%: ${money(quote.tax)}\nTotal: ${money(quote.gross)}\nBilling: ${quote.mode}\nUncertain provider outcomes hold funds for Admin review.`;const accept=document.createElement('button');accept.className='primary-action';accept.textContent='Confirm '+money(quote.gross);const cancel=document.createElement('button');cancel.className='secondary-action';cancel.textContent='Cancel';dialog.append(title,text,accept,cancel);document.body.append(dialog);
    return new Promise((resolve,reject)=>{let confirmed=false;accept.onclick=()=>{confirmed=true;dialog.close();};cancel.onclick=()=>dialog.close();dialog.addEventListener('close',()=>{dialog.remove();confirmed?resolve(quote.confirmation):reject(Error('Paid request cancelled.'));},{once:true});dialog.showModal();});
  }
};
document.addEventListener('DOMContentLoaded',()=>{
  for(const id of ['apiKey','serviceApiKey']){const input=document.getElementById(id);if(input){input.required=false;input.disabled=true;input.closest('.field').hidden=true;}}
});

(()=>{
  let busy=false;
  async function refreshBalance(){if(busy||document.hidden||!document.getElementById('headerBalanceValue'))return;busy=true;try{const response=await fetch('/customer/balance',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="customer-csrf"]').content},body:'{}'});if(!response.ok)return;const d=await response.json();document.getElementById('headerBalanceLabel').textContent=d.label;document.getElementById('headerBalanceValue').textContent='₹'+(d.amount/100).toFixed(2);}catch{}finally{busy=false;}}
  document.addEventListener('visibilitychange',refreshBalance);window.addEventListener('focus',refreshBalance);setInterval(refreshBalance,10000);
})();
