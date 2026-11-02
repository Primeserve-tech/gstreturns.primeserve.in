'use strict';
document.getElementById('customerNavLogout')?.addEventListener('click',async event=>{
  const button=event.currentTarget;if(button.disabled)return;button.disabled=true;
  try{const response=await fetch('/customer/logout',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name="customer-csrf"]').content},body:'{}'});const data=await response.json();if(!response.ok)throw Error(data.error||'Unable to log out.');location.assign('/login');}
  catch(error){window.PortalErrors?.reset();window.PortalErrors?.show(error.message);button.disabled=false;}
});
