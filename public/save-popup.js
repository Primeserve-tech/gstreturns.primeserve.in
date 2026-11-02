'use strict';
(()=>{
  const dialog=document.createElement('dialog');dialog.className='save-dialog';dialog.setAttribute('aria-labelledby','saveDialogTitle');dialog.setAttribute('aria-describedby','saveDialogMessage');
  const close=document.createElement('button');close.type='button';close.className='save-dialog-close';close.setAttribute('aria-label','Close success popup');close.textContent='×';
  const icon=document.createElement('div');icon.className='save-dialog-icon';icon.setAttribute('aria-hidden','true');icon.textContent='✓';
  const title=document.createElement('h2');title.id='saveDialogTitle';title.textContent='Saved successfully';
  const message=document.createElement('p');message.id='saveDialogMessage';
  const ok=document.createElement('button');ok.type='button';ok.className='primary-action';ok.textContent='OK, got it';
  dialog.append(close,icon,title,message,ok);document.body.append(dialog);let previousFocus=null;
  close.addEventListener('click',()=>dialog.close());ok.addEventListener('click',()=>dialog.close());
  dialog.addEventListener('close',()=>{if(previousFocus?.isConnected)previousFocus.focus({preventScroll:true});});
  window.PortalSuccess={show(text='Your changes have been saved.'){message.textContent=text;if(!dialog.open){previousFocus=document.activeElement;dialog.showModal();ok.focus();}}};
})();
