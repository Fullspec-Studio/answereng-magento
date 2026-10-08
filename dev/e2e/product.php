<?php
// Edit products through the ProductRepository (fires the same save events as the admin form).
// php var/scripts/product.php <sku> [name=..] [status=1|2] [websites=1,2] [price=..]
require '/var/www/html/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$om->get(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore(0);
$repo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$sku = $argv[1];
$p = $repo->get($sku, true, 0, true);
foreach (array_slice($argv, 2) as $arg) {
    [$k, $v] = explode('=', $arg, 2);
    if ($k === 'websites') {
        $p->setWebsiteIds(array_map('intval', explode(',', $v)));
    } else {
        $p->setData($k, $v);
    }
}
$repo->save($p);
echo "saved $sku websites=" . implode(',', $p->getWebsiteIds()) . " status=" . $p->getStatus() . "\n";
