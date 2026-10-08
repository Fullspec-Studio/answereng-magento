<?php
// Save a category through the CategoryRepository (fires the category save events).
// php var/scripts/category-save.php <categoryId> [name]
require '/var/www/html/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$om->get(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore(0);
$repo = $om->get(\Magento\Catalog\Api\CategoryRepositoryInterface::class);
$category = $repo->get((int) $argv[1], 0);
if (isset($argv[2])) {
    $category->setName($argv[2]);
}
$category->setData('answereng_touch', microtime(true));   // force a data change so the save runs
$repo->save($category);
echo 'saved category ' . $category->getId() . ' ' . $category->getName() . "\n";
