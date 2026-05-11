=== Mailora Email Composer ===
Contributors: wppassiondev, sumon1068
Tags: email, send email, html email, admin email, email log
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compose and send HTML emails from your WordPress admin dashboard with a rich text editor and full sent email log.

== Description ==

**Mailora Email Composer** lets you compose and send HTML emails directly from the WordPress admin — without leaving your site, without touching code, and without needing a separate email platform.

Whether you need to send a quick reply to a customer, follow up with a lead, or fire off a formatted HTML message to anyone, Mailora Email Composer makes it simple.

Developed by [WP Passion](https://wppassion.com)

= Why Mailora Email Composer? =

Most WordPress sites can already send emails through `wp_mail()`, but there is no built-in interface to compose and send an email from the admin dashboard. Mailora Email Composer fills that gap with a clean, distraction-free compose screen powered by the familiar WordPress TinyMCE editor.

= Features =

* **Compose HTML emails** from the WordPress admin — no external tool needed
* **Rich text editor** powered by the built-in WordPress TinyMCE toolbar (bold, italic, lists, links, and more)
* **Sender and recipient fields** support both plain `email@example.com` and `Name <email@example.com>` formats
* **From address is saved** automatically so you don't retype it every time
* **Sent email log** stored in a dedicated database table — view, search, and delete entries any time
* **Send Again** — pre-fill the compose form from any previous log entry in one click
* **Secure email viewer** — view the full HTML body of any logged email in a sandboxed popup
* **Client-side search** — filter the log instantly by recipient, subject, or body content
* **Automatic log pruning** — keeps only the latest 200 entries to protect database performance
* **Clean uninstall** — removes plugin tables when deleted
* **Works with `wp_mail()`** — compatible with WP Mail SMTP, Postmark, SendGrid, Mailgun, Amazon SES, and any SMTP plugin

= Who Is This For? =

* Site owners who occasionally need to send a formatted email from their domain
* Developers who want a quick way to test outgoing email from a WordPress install
* Admins who want to send quick customer emails without leaving WordPress
* Anyone who needs a lightweight email composer without building a full CRM

== Installation ==

1. Upload the `mailora-email-composer` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Send Email** in the WordPress admin menu.
4. Enter your From address, recipient, subject, and compose your message.
5. Click **Send Email**.

== Frequently Asked Questions ==

= Does this plugin require an SMTP plugin? =

No. The plugin uses WordPress's built-in `wp_mail()` function, which works out of the box on most hosting environments.

However, for reliable delivery and better email formatting support, an SMTP plugin is strongly recommended. Compatible options include:

* WP Mail SMTP
* Postmark
* SendGrid
* Mailgun
* Amazon SES

= What email format is used? =

Emails are sent as HTML. The TinyMCE editor lets you apply bold, italic, underline, lists, blockquotes, and links, all of which are preserved in the recipient's inbox.

= Does the plugin store sent emails? =

Yes. Every sent email is logged in a custom database table. You can view, search, and delete individual entries from the **Sent Log** tab. The log automatically keeps only the latest 200 entries to avoid database bloat.

= Can I resend a previous email? =

Yes. Open any log entry using the **View** button, then click **Send Again** to pre-fill the compose form with the original recipient and subject.

= Is the email viewer safe? =

Yes. The email body is displayed inside a sandboxed `<iframe>` that prevents scripts from running and isolates the content from the rest of the admin page.

= Does the plugin remove its data on uninstall? =

Yes. The plugin's custom database tables are removed cleanly when you delete the plugin from WordPress.

== Screenshots ==

1. Compose tab with TinyMCE editor, From, To, and Subject fields.
2. Sent log table with search, View, Delete, and Clear All actions.
3. Secure popup viewer showing the full HTML email body with a Send Again button.

== Changelog ==

= 1.0.0 =
* Initial release.
