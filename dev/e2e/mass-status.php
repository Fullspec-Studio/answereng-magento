<?php
// Mass "Update attributes" (same model call as the admin mass action): status for products at a store view.
// php var/scripts/mass-status.php <storeId> <status 1|2> [all | sku,sku,...]
require '/var/www/html/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$conn = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
[$_, $storeId, $status, $which] = $argv + [3 => 'all'];
$ids = $which === 'all'
    ? $conn->fetchCol('SELECT entity_id FROM catalog_product_entity')
    : $conn->fetchCol($conn->quoteInto('SELECT entity_id FROM catalog_product_entity WHERE sku IN (?)', explode(',', $which)));
$om->get(\Magento\Catalog\Model\Product\Action::class)->updateAttributes($ids, ['status' => (int) $status], (int) $storeId);
echo 'updated ' . count($ids) . " products: status=$status at store $storeId\n";
