<nav class="customer-nav" aria-label="Customer navigation"><div class="brand-wrap customer-nav-inner">
  <a href="/" <?= ($active??'')==='services'?'aria-current="page"':'' ?>>GST Services</a>
  <a href="/account" <?= ($active??'')==='account'?'aria-current="page"':'' ?>>My Account</a>
  <div class="customer-nav-profile"><a href="/account#profile">Profile</a><span aria-hidden="true">/</span><button type="button" id="customerNavLogout">Logout</button></div>
</div></nav>
