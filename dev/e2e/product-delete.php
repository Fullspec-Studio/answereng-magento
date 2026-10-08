<?php
// Delete products through the ProductRepository (fires the same delete events as the admin grid).
// php var/scripts/product-delete.php <sku> [sku ...]
require '/var/www/html/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$om->get(\Magento\Framework\Registry::class)->register('isSecureArea', true);
$repo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
foreach (array_slice($argv, 1) as $sku) {
    $repo->deleteById($sku);
    echo "deleted $sku\n";
}
