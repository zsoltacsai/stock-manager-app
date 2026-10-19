# Third-party notices

The FountainTrade project licence does not replace the licences of the components below. Preserve their original notices and licence files when redistributing a source or built release.

| Component | Shipped path | Licence evidence in this repository |
| --- | --- | --- |
| PHPMailer | `vendor/phpmailer/` | LGPL 2.1 text in `vendor/phpmailer/LICENSE`; author/copyright notices in the PHP files |
| TinyMCE | `webroot/vendor/tinymce/` | `webroot/vendor/tinymce/license.md` provides GPL version 2 or later as the open-source option, alongside an alternative commercial agreement |
| DOMPurify bundled in TinyMCE | `webroot/vendor/tinymce/tinymce.min.js` | The bundle header credits Cure53 and other contributors and identifies DOMPurify 3.4.11. The upstream tag's Apache-2.0 licence is preserved in `LICENSES/DOMPurify-Apache-2.0.txt` |
| QR Code Generator for JavaScript | `webroot/vendor/qrcode-generator/qrcode.js` | Copyright (c) 2009 Kazuhiko Arase; MIT notice in the source and full terms in `LICENSES/qrcode-generator-MIT.txt` |

## TinyMCE configuration

`license_key: 'gpl'` in `webroot/product-modal.js` selects TinyMCE's open-source licence mode. It is not a paid FountainTrade activation key and must not be removed as part of opening the project.

TinyMCE's bundled DOMPurify notice and all existing vendor licence files remain unchanged. This notice does not grant a commercial TinyMCE licence or permit removing upstream attribution.

## Scope

This inventory was checked against repository commit `587ce04453a96ceacbf7c9984095bf9cf640458b`. It records shipped third-party components, not ownership of every line. New dependencies must be added here with their source, version, licence and required notices. External services, APIs and separately installed runtimes keep their own terms.

FountainTrade names and logos are not licensed as trademarks by the application source-code licence.
