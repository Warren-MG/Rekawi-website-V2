# Deploying to HostAfrica (cPanel)

Everything in this archive goes into `public_html`. Read step 0 first — it is
not optional.

---

## 0. Change the mailbox password FIRST

The previous version of this site had the `info@rekawi.com` password written in
plain text inside `send-mail.php` and `send-quote.php`. That file has been
copied and handled, so treat the old password as public.

1. cPanel → **Email Accounts** → `info@rekawi.com` → **Manage** → set a new
   password. Use something long and random.
2. cPanel → **Email** → **Track Delivery**. Look for outbound mail you did not
   send. A leaked SMTP login is normally used to relay spam, which gets the
   whole domain blacklisted. If you see any, tell HostAfrica support.

Do not skip this because the new site keeps credentials out of the code. The
old password is already exposed.

---

## 1. Upload

1. cPanel → **File Manager** → open `public_html`.
2. **Back up whatever is there now** (select all → Compress → download the zip)
   before you delete anything.
3. Delete the old contents of `public_html`.
4. Upload `rekawi-website.zip`, then right-click → **Extract**.
5. Delete the zip once extracted.

The files must sit directly in `public_html` — `index.html` at
`public_html/index.html`, not `public_html/rekawi-website/index.html`.

### What should be there afterwards

```
public_html/
├── index.html
├── 404.html
├── robots.txt
├── sitemap.xml
├── favicon.ico
├── .htaccess
├── assets/
│   ├── css/     app.css, components.css
│   ├── js/      app.js
│   ├── fonts/   Fraunces-var.woff2, Jost-var.woff2
│   ├── images/
│   └── videos/  README.txt  (put hero.mp4 / hero.webm here)
└── forms/
    ├── send-mail.php
    ├── send-quote.php
    ├── includes/
    │   ├── .htaccess
    │   ├── config.sample.php
    │   └── mailer.php
    └── phpmailer/
        ├── Exception.php, PHPMailer.php, SMTP.php
```

`.htaccess` is a hidden file. If you cannot see it in File Manager, use
**Settings** (top right) → tick **Show Hidden Files**.

---

## 2. Configure email

The forms will not send until you do this.

1. In File Manager, go into `forms/includes/`.
2. Copy `config.sample.php` → rename the copy to **`config.php`**.
3. Right-click `config.php` → **Edit**.
4. Fill in `smtp_pass` with the new password from step 0.

```php
'smtp_host'   => 'mail.rekawi.com',
'smtp_user'   => 'info@rekawi.com',
'smtp_pass'   => 'the-new-password-here',
'smtp_secure' => 'tls',
'smtp_port'   => 587,
```

**Getting the right host and port.** cPanel → **Email Accounts** →
`info@rekawi.com` → **Connect Devices** shows the exact SMTP host and port
HostAfrica expects. Use those values, not a guess.

- If it offers **port 465**, prefer it: set `'smtp_secure' => 'ssl'` and
  `'smtp_port' => 465`.
- If `mail.rekawi.com` fails, try the server hostname shown on that page, or
  `localhost`.

Leave `config.php` where it is. Both `.htaccess` files block direct web access
to it. If you would rather move `forms/includes/` above `public_html`, update
the `require_once` path at the top of `forms/send-mail.php` and
`forms/send-quote.php` to match.

---

## 3. Test the forms

1. Open `https://rekawi.com` and submit the contact form with a **real address
   you can check** — ideally a Gmail account, since Gmail is the strictest.
2. You should get two emails: the enquiry at `info@rekawi.com`, and an
   automatic confirmation at the address you entered.
3. Repeat with the **Request a quote** button.

### If it fails

The page tells you what went wrong. To see the underlying cause:

1. Edit `forms/includes/config.php`, set `'smtp_debug' => 2`.
2. Submit again.
3. Read `forms/includes/mail-error.log` — the full SMTP conversation is in there.
4. **Set `smtp_debug` back to `0` when you are done.**

| Message in the log | Cause |
|---|---|
| `Could not authenticate` | Wrong password, or wrong username |
| `Could not connect to SMTP host` | Wrong host or port — recheck Connect Devices |
| `Email is not configured` | `config.php` missing, or `smtp_pass` still blank |

If mail arrives at `info@rekawi.com` but the confirmation does not reach the
sender, the enquiry still got through — the autoresponse is non-critical and
failures are logged separately.

---

## 4. Check deliverability

cPanel SMTP on shared hosting has a habit of landing in spam. Before you rely
on it:

1. Send a test to a Gmail address. Check the spam folder too.
2. cPanel → **Email Deliverability**. It will flag missing **SPF** or **DKIM**
   records and usually offers a one-click fix. Apply both.
3. Re-test.

If mail still lands in spam, switch to an API sender (Brevo has a free tier and
works well from Kenya). The config swaps over cleanly — only the transport
settings in `config.php` change.

---

## 5. Post-launch

- **Search Console.** The verification tag is preserved in `index.html`. Submit
  `https://rekawi.com/sitemap.xml` under Sitemaps.
- **PHP version.** cPanel → **MultiPHP Manager** → set to **8.0 or newer**.
  The code targets 8.x and uses typed declarations.
- **Analytics.** There was no analytics or GTM snippet in the files I was
  given, only the Search Console meta tag. If you have one running, paste it
  just before `</head>` in `index.html`.
- **HTTPS.** `.htaccess` forces HTTPS and strips `www`. Make sure AutoSSL has
  issued a certificate (cPanel → **SSL/TLS Status**) or every request will
  redirect into a broken padlock.

---

## 6. Hero video (optional)

The homepage hero plays a looping background video. `hero.mp4` (1.7MB) and
`hero.webm` (1.4MB) are already in `assets/videos/` — a seamless 11-second
slow-motion loop, muted, 1600x900. The poster `assets/images/hero-bg.jpg` is
the video's first frame, so the fallback matches.

To swap in different footage later, replace both files and regenerate the
poster; encoding instructions are in `assets/videos/README.txt`.

The video will not load on reduced-motion or Data Saver settings, or if the
files are missing — in those cases the poster image shows instead.

---

## Notes

- `forms/includes/mail-error.log` is created automatically on the first error. It is
  blocked from web access. Clear it occasionally.
- Rate limiting allows 6 submissions per IP per hour. Change `rate_limit` in
  `config.php`, or set it to `0` to disable.
- Never commit `config.php` to git or include it in a backup you share.
