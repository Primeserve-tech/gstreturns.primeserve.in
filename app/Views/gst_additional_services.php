<section id="additionalService" class="portal-card additional-service" aria-labelledby="additionalServiceTitle" hidden>
  <div class="card-heading"><span class="heading-icon"><svg aria-hidden="true"><use href="#i-file"/></svg></span><div><p class="card-eyebrow">GST DOCUMENTS</p><h2 id="additionalServiceTitle">GST Challan Download</h2></div></div>
  <p class="section-description" id="additionalServiceDescription"></p>
  <form id="additionalServiceForm">
    <div id="challanPeriodFields" class="form-grid service-period-fields">
      <div class="field"><label for="challanScope">Select Challan Period</label><select id="challanScope"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></div>
      <div class="field"><label for="challanYear" id="challanYearLabel">Calendar Year</label><select id="challanYear"></select></div>
      <div class="field" id="challanMonthField"><label for="challanMonth">Month</label><select id="challanMonth"><option value="01">January</option><option value="02">February</option><option value="03">March</option><option value="04">April</option><option value="05">May</option><option value="06">June</option><option value="07">July</option><option value="08">August</option><option value="09">September</option><option value="10">October</option><option value="11">November</option><option value="12">December</option></select></div>
    </div>
    <p class="period-note" id="challanPeriodHelp"></p>
    <fieldset class="service-credentials"><legend>GST account details</legend><div class="form-grid">
      <div class="field full-width"><label for="serviceApiKey">API Key</label><input id="serviceApiKey" type="password" autocomplete="off" placeholder="Enter API key, or reuse your connected account key" maxlength="512"></div>
      <div class="field"><label for="serviceUsername">GST Username</label><input id="serviceUsername" autocomplete="off" placeholder="GST portal username" required minlength="3" maxlength="100"></div>
      <div class="field"><label for="serviceGstin">GSTIN</label><input id="serviceGstin" autocomplete="off" placeholder="15-character GSTIN" maxlength="15" minlength="15" required></div>
      <div class="field full-width"><label for="servicePassword">GST Password</label><input id="servicePassword" type="password" autocomplete="off" placeholder="Enter your GST portal password" required maxlength="512"><small>The provider documents password-based requests for these services. This password is not part of the existing OTP connection.</small></div>
    </div></fieldset>
    <div class="selection-bottom"><span class="quiet-note">Existing GST Returns downloads remain available.</span><button id="getAdditionalService" class="primary-action" type="submit">Get Challan</button></div>
  </form>
  <section id="serviceResponse" class="service-response" aria-live="polite" hidden>
    <h3>Provider response</h3><p id="serviceResponseMessage"></p>
    <div class="service-export-actions"><button type="button" class="secondary-action" id="saveServiceResponse">Download response</button><button type="button" class="secondary-action" id="exportServiceCsv" hidden>Export CSV</button></div>
    <div class="table-scroll"><table><thead><tr><th>Response field</th><th>Value</th></tr></thead><tbody id="serviceResponseBody"></tbody></table></div>
  </section>
</section>
