<?php
// Creates website "second" / group / store view "second_view" (base URL http://127.0.0.1:8894/)
// and assigns the first 40 eligible products of website 1 to it too.
use Magento\Framework\App\Bootstrap;
require '/var/www/html/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$websiteFactory = $om->get(\Magento\Store\Model\WebsiteFactory::class);
$website = $websiteFactory->create()->load('second', 'code');
if (!$website->getId()) {
    $website->setCode('second')->setName('Second Website')->save();
}
$group = $om->get(\Magento\Store\Model\GroupFactory::class)->create();
$group->load($website->getDefaultGroupId());
if (!$group->getId()) {
    $group->setWebsiteId($website->getId())->setName('Second Store')->setCode('second_store')
        ->setRootCategoryId(2)->save();
    $website->setDefaultGroupId($group->getId())->save();
}
$store = $om->get(\Magento\Store\Model\StoreFactory::class)->create()->load('second_view', 'code');
if (!$store->getId()) {
    $store->setCode('second_view')->setName('Second View')->setWebsiteId($website->getId())
        ->setGroupId($group->getId())->setIsActive(1)->save();
    $group->setDefaultStoreId($store->getId())->save();
}
$writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
$writer->save('web/unsecure/base_url', 'http://127.0.0.1:8894/', 'websites', (int) $website->getId());
$writer->save('web/secure/base_url', 'http://127.0.0.1:8894/', 'websites', (int) $website->getId());
// add 40 products to the second website
$conn = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$ids = $conn->fetchCol("SELECT e.entity_id FROM catalog_product_entity e WHERE e.type_id='simple' AND e.entity_id IN (SELECT entity_id FROM catalog_product_entity_int WHERE attribute_id=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='visibility' AND entity_type_id=4) AND value=4 AND store_id=0) ORDER BY e.entity_id LIMIT 40");
foreach ($ids as $id) {
    $conn->insertOnDuplicate('catalog_product_website', ['product_id' => $id, 'website_id' => $website->getId()]);
}
echo "website {$website->getId()} group {$group->getId()} store {$store->getId()} products " . count($ids) . "\n";
