'use strict';
(() => {
  const dialog=document.getElementById('errorDialog');
  if(!dialog)return;
  const messageBox=document.getElementById('errorDialogMessage');
  const seen=new Set();
  let previousFocus=null;
  const close=()=>dialog.close();
  window.PortalErrors={
    show(message,options={}){
      const text=String(message||'The request could not be completed. Please try again.');
      const key=options.key||text;
      if(seen.has(key))return;
      seen.add(key);
      if(!dialog.open){messageBox.replaceChildren();previousFocus=document.activeElement;}
      const paragraph=document.createElement('p');paragraph.textContent=text;messageBox.append(paragraph);
      if(!dialog.open)dialog.showModal();
    },
    reset(){seen.clear();},
    forget(key){seen.delete(key);}
  };
  document.getElementById('errorDialogClose').addEventListener('click',close);
  document.getElementById('errorDialogOk').addEventListener('click',close);
  dialog.addEventListener('close',()=>{if(previousFocus?.isConnected)previousFocus.focus({preventScroll:true});});
  document.addEventListener('invalid',event=>{
    event.preventDefault();
    const label=event.target.labels?.[0]?.textContent.replace('*','').trim()||'Required field';
    window.PortalErrors.show(`${label}: ${event.target.validationMessage}`,{key:'validation:'+event.target.id+':'+event.target.validationMessage});
  },true);
  document.addEventListener('input',()=>{if(!dialog.open)seen.clear();});
  document.addEventListener('click',event=>{if(event.target.closest('button[type="submit"],input[type="submit"]')&&!dialog.open)seen.clear();},true);
  window.addEventListener('error',()=>window.PortalErrors.show('An unexpected page error occurred. Refresh the page and try again.',{key:'page-error'}));
  window.addEventListener('unhandledrejection',()=>window.PortalErrors.show('An unexpected request error occurred. Please try again.',{key:'unhandled-request'}));
  if(dialog.dataset.initialError)window.PortalErrors.show(dialog.dataset.initialError,{key:'initial-error'});
})();
