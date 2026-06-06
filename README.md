# forum-cms

A minimal, SEO-focused forum. Plain PHP + MySQL, no dependencies.

## Requirements

- PHP 8.0+
- MySQL 5.7+
- A web host that supports `.htaccess` (Apache)

## Install

1. Upload all files to your web root (`public_html`, `htdocs`, etc.)
2. Visit `yoursite.com/install.php`
3. Fill in your database credentials, site URL, and admin account — done

`install.php` deletes itself when finished. `config.php` is written to the server and never committed to git.

## Updates

Updates apply automatically within 24 hours — no action needed. To update immediately, go to **Admin → Update Now**.

## Webhook (optional)

For instant deploys on every push, add a webhook in your GitHub repo settings pointing to `yoursite.com/webhook.php` with a secret, then set the same secret as `webhook_secret` in your `config.php`.
