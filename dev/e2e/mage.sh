#!/usr/bin/env bash
# Run bin/magento inside the phpfpm container: dev/e2e/mage.sh cron:run
ml="${MAGENTO_LOCAL:-/Volumes/Tukar/Code/answer-eng/magento-local}"
docker compose -f "$ml/compose.yaml" exec -T phpfpm bin/magento "$@"
