<!--
  MESH Cloud hotspot login page for {{TENANT_NAME}} ({{SLUG}}).

  Upload this as  hotspot/login.html  on the MikroTik (Winbox/WebFig > Files >
  open the "hotspot" folder > drag this file in, replacing the stock login.html).
  The .rsc already points the hotspot at  html-directory=hotspot.

  What it does: instead of MikroTik's built-in login form, it hands the client to
  the MESH portal and passes the gateway login URL ($(link-login-only)) plus the
  originally requested page. The portal then logs the user in (after they buy or
  enter a voucher) by POSTing the credentials back to that gateway URL.
-->
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{TENANT_NAME}} WiFi</title>
  <script>
    (function () {
      var base = "{{PORTAL_URL}}";
      var sep  = base.indexOf('?') >= 0 ? '&' : '?';
      var url  = base + sep
        + "gw="   + encodeURIComponent("$(link-login-only)")
        + "&dst=" + encodeURIComponent("$(link-orig)")
        + "&mac=" + encodeURIComponent("$(mac)")
        + "&err=" + encodeURIComponent("$(error)");
      location.replace(url);
    })();
  </script>
</head>
<body style="font-family:sans-serif;text-align:center;padding:2rem">
  <p>Opening the {{TENANT_NAME}} WiFi portal…</p>
  <p><a href="{{PORTAL_URL}}">Tap here if it doesn't open automatically.</a></p>
</body>
</html>
