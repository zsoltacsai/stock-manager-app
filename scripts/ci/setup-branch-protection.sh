#!/usr/bin/env bash
# A main ág védelmének beállítása a gh CLI-vel.
#
# Mit követel:
#   - pull requestet a main-re (0 kötelező jóváhagyással, hogy egyedüli
#     tulajdonos mellett se legyen önmagát jóváhagyó kényszer),
#   - zöld "CI Gate" státusz-ellenőrzést,
#   - nincs force push, nincs ágtörlés.
#
# Használat:
#   bash scripts/ci/setup-branch-protection.sh [owner/repo] [branch]
set -euo pipefail

repo="${1:-$(gh repo view --json nameWithOwner --jq .nameWithOwner)}"
branch="${2:-main}"

echo "Branch protection beállítása: ${repo} / ${branch}"

gh api -X PUT "repos/${repo}/branches/${branch}/protection" \
  -H "Accept: application/vnd.github+json" \
  --input - <<'JSON'
{
  "required_status_checks": {
    "strict": false,
    "contexts": ["CI Gate"]
  },
  "enforce_admins": false,
  "required_pull_request_reviews": {
    "required_approving_review_count": 0,
    "dismiss_stale_reviews": false
  },
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "required_conversation_resolution": true,
  "required_linear_history": false
}
JSON

echo "Kész. Ellenőrzés:"
gh api "repos/${repo}/branches/${branch}/protection" --jq '{required_status_checks: .required_status_checks.contexts, pull_request: .required_pull_request_reviews, force_push: .allow_force_pushes.enabled, deletions: .allow_deletions.enabled}'
