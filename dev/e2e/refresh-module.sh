#!/usr/bin/env bash
# Fast refresh of the copied module after editing the repo (equivalent to
# `composer reinstall fullspec/module-answereng` without re-copying the repo's dev vendor/).
set -euo pipefail
ml="${MAGENTO_LOCAL:-/Volumes/Tukar/Code/answer-eng/magento-local}"
repo="$(CDPATH= cd "$(dirname "$0")/../.." && pwd)"
rsync -a --delete --exclude .git --exclude vendor --exclude dist --exclude dev/mock-engine/received \
  --exclude dev/e2e/.jars "$repo/" "$ml/src/vendor/fullspec/module-answereng/"
docker compose -f "$ml/compose.yaml" exec -T phpfpm bash -c \
  "rm -rf generated/code/Fullspec generated/metadata/* && bin/magento cache:flush" >/dev/null
echo refreshed
