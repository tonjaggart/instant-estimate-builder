# Instant Estimate Builder

Instant Estimate Builder is a WordPress plugin for creating customizable multi-step instant estimate forms for local service businesses.

It supports:

- Multi-step estimate forms
- Lead capture and lead management
- Customer estimate emails
- Sales notification emails/text alerts
- Service-specific pricing ranges
- Optional done-for-you setup from Taggart Media Group

## Local development

Keep this repository as the plugin source and symlink it into a local WordPress install:

```
cd /path/to/local-wordpress/wp-content/plugins
ln -s ~/Code/instant-estimate-builder instant-estimate-builder
```

Then activate **Instant Estimate Builder** in WordPress admin.

## Compatibility note

Some internal identifiers still use the legacy `hgm` / `instant_quote_form` naming so existing installs, forms, shortcodes, leads, AJAX actions, and settings keep working. New user-facing language should use **Instant Estimate Builder** and **estimate forms**.

## Author

Taggart Media Group

https://taggartmediagroup.com

## License

Copyright © 2026 Taggart Media Group.

Instant Estimate Builder is licensed under the GPL-2.0-or-later license. See `LICENSE` for details.
