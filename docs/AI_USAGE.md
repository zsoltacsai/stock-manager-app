# AI assistance and contributor provenance

FountainTrade uses an AI-assisted development workflow. The repository includes AI-related application features as well. These are different matters: using a code assistant does not make every application feature AI-dependent.

## Existing baseline

The public README and source include the existing AI Copilot and provider integrations. Historical commits do not necessarily identify the exact assistant/model and prompt used for every contribution. This document does **not** certify complete historical provenance or assert that every line was manually authored.

Do not invent missing model names, timestamps, prompts, reviewers or test results. Where historical records are incomplete, state the limitation.

## New substantive AI-assisted changes

For each contribution that materially contains generated code:

- Record the assistant/model and version when available.
- Describe what the tool generated and what the human contributor designed, reviewed, changed and verified.
- Retain prompts/interactions and outputs, or an appropriate public provenance summary, alongside the contribution.
- Document sources or licence constraints and resolve incompatible copied material before release.
- Report tests actually run and material limitations; test code existing in a repository is not evidence that it passed.

For assistance limited to tests or documentation, give a clear general description; detailed contribution records are preferred. Never commit credentials, private customer data, or unrelated account/conversation content into a public provenance log.

Human contributors remain responsible for understanding and explaining the design, integration, originality and behaviour of their submissions. Purely generated outcomes must not be represented as human-authored grant deliverables.

## Grant proposals

The Restack/NLnet application requires disclosure of AI use in drafting, translation or summarisation and a separate prompt provenance log. Keep that application log outside the public source repository unless its publication is explicitly authorised. Preserve original prompts and unedited outputs; disclose unavailable metadata honestly.

Reference: https://nlnet.nl/foundation/policies/generativeAI/

## This documentation change

ChatGPT/Codex assisted with reviewing repository files, assembling the component notice inventory and drafting this documentation. The connected ChatGPT interface displayed **GPT-6.1 Sol** during preparation on 2026-10-19 (Europe/Budapest). This observation is not evidence of the model used for older commits.

No application source, third-party bundle, security control or test implementation was changed by this documentation-only patch. The licence text is reproduced from SPDX licence-list-data; the DOMPurify Apache-2.0 text is reproduced from its upstream 3.4.11 tag. Existing third-party notices are retained.
