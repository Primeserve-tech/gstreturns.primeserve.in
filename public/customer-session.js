"use strict";
(()=>{
 if(document.body.dataset.screen && document.body.dataset.screen!=='account')return;
 const token=document.querySelector('meta[name="customer-csrf"]')?.content;if(!token)return;
 let busy=false;
 async function check(){if(busy||document.hidden)return;busy=true;try{
  const r=await fetch('/customer/session-status',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:'{}'});
  if(r.status===401){location.replace('/login');return;}
  if(r.status===422){const data=await r.json();if(data.error==='Refresh this page and try again.')location.replace('/login');}
 }catch{}finally{busy=false;}}
 setInterval(check,5000);window.addEventListener('focus',check);document.addEventListener('visibilitychange',check);window.addEventListener('pageshow',check);check();
})();
