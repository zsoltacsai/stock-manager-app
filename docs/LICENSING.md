# FountainTrade open-source licensing

## Application licence

Except for third-party material carrying its own terms, FountainTrade project-authored source code and accompanying project documentation are provided under the **GNU Affero General Public License, version 3 or (at your option) any later version**.

SPDX identifier: `AGPL-3.0-or-later`.

The complete licence text is in [LICENSE](../LICENSE). Third-party terms and attribution are described in [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md).

The application may be downloaded and self-hosted without a FountainTrade subscription or central activation service. Optional installation, migration, training, hosting, support and custom development services may be offered for a fee. Users of a self-hosted installation do not have to buy those services.

The licence terms govern redistribution and modified versions, including applicable source-availability obligations for modified network services. This summary does not replace the licence text.

## Security is separate from commercial licensing

Keep login/session authentication, roles, CSRF checks, machine registration, HMAC, replay protection, update verification and third-party licence declarations. A local application password or WooCommerce API credential is not a paid product licence.

A targeted source scan found no commercial FountainTrade activation gate in the examined baseline. The existing TinyMCE `license_key: 'gpl'` setting is an upstream open-source declaration, not a commercial gate.

## Contributions and provenance

Contributors retain their existing copyright. By contributing, they must have the right to submit their changes under the project's licence and identify material with separate terms. Do not remove existing third-party notices or claim ownership of upstream code.

AI-assisted contributions must follow [AI_USAGE.md](AI_USAGE.md). A licence file alone does not establish the provenance of generated or copied material.

## Current release boundaries

This change adds licensing and transparency documentation. It does not change POS behaviour, authentication, invoice integrations, AI functionality, update verification or dependency code. Opening the source is separate from the proposed Restack development work.

No warranty is provided except as required by applicable law or a separate written agreement. See the licence text.
