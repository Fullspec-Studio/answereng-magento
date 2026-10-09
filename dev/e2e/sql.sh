#!/usr/bin/env bash
# Run SQL against the local Magento DB: dev/e2e/sql.sh "select ..."
ml="${MAGENTO_LOCAL:-/Volumes/Tukar/Code/answer-eng/magento-local}"
docker compose -f "$ml/compose.yaml" exec -T db mariadb -umagento -pmagento magento -e "$1"
