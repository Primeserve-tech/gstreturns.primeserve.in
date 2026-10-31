<dialog id="errorDialog" class="error-dialog" role="alertdialog" aria-labelledby="errorDialogTitle" aria-describedby="errorDialogMessage" data-initial-error="<?= esc($initialError ?? '', 'attr') ?>">
  <button type="button" id="errorDialogClose" class="error-dialog-close" aria-label="Close error popup">&times;</button>
  <div class="error-dialog-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 4h.01"/></svg></div>
  <p class="error-dialog-kicker">PRIMESERVE GST PORTAL</p>
  <h2 id="errorDialogTitle">Unable to complete the request</h2>
  <div id="errorDialogMessage" class="error-dialog-message"></div>
  <div class="error-dialog-actions"><button type="button" id="errorDialogOk" class="primary-action" autofocus>OK, got it</button></div>
</dialog>
