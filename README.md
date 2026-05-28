# Mailora Email Composer
Send HTML emails directly from your WordPress admin dashboard.

Mailora Email Composer is a lightweight WordPress plugin that provides a clean interface for composing and sending HTML emails using the built-in WordPress editor.

## Features
- Rich HTML email composer
- TinyMCE editor support
- Sender and recipient name support
- Sent email logging
- Searchable email history
- One-click Send Again functionality
- Secure HTML email viewer
- Automatic log cleanup
- Clean uninstall support
- SMTP compatible

## Requirements
- WordPress 5.0+
- PHP 7.2+

## Installation

**From WordPress (Recommended)**  
Go to Plugins → Add New, search for *Mailora Email Composer*, install and activate.  
Or download from WordPress.org: https://wppassion.com/plugins/mailora-email-composer/

**Manual Upload**  
Upload the ZIP via Plugins → Add New → Upload Plugin, then activate.

## Usage
1. Open Send Email from the WordPress admin menu
2. Enter the sender email address
3. Enter the recipient email address
4. Add the email subject
5. Compose your HTML email
6. Click Send Email

## Supported Address Formats
`email@example.com` <br>
`John Doe <john@example.com>`

## Email Logging

Every sent email is stored in a dedicated database table.

The plugin automatically retains only the latest 200 entries to prevent database bloat.

## SMTP Compatibility

Mailora Email Composer works with WordPress wp_mail() and most SMTP/email delivery plugins and services, including:

- WP Mail SMTP
- Postmark
- SendGrid
- Mailgun
- Amazon SES

## License

GPLv2 or later

## Documentation
See full guide: https://wppassion.com/plugins/mailora-email-composer/

## Author

Developed by [WP Passion](https://wppassion.com)
