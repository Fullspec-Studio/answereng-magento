<?php
// Product CSV import through Magento\ImportExport\Model\Import (the model the admin
// System › Data Transfer › Import page drives): validate, then import.
// php var/scripts/import.php <csv-path-inside-container> [append|replace|delete]
require '/var/www/html/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$file = $argv[1];
$behavior = $argv[2] ?? 'append';
$import = $om->create(\Magento\ImportExport\Model\Import::class)->setData([
    'entity' => 'catalog_product', 'behavior' => $behavior,
    'validation_strategy' => 'validation-stop-on-errors', 'allowed_error_count' => 10,
    \Magento\ImportExport\Model\Import::FIELD_NAME_IMG_FILE_DIR => 'pub/media/import',
    '_import_field_separator' => ',', '_import_multiple_value_separator' => ',',
]);
$dir = $om->get(\Magento\Framework\Filesystem::class)->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::ROOT);
$source = \Magento\ImportExport\Model\Import\Adapter::findAdapterFor($file, $dir, ',');
$valid = $import->validateSource($source);
$errors = $import->getErrorAggregator();
if (!$valid || $errors->getErrorsCount() > 0) {
    foreach ($errors->getAllErrors() as $e) {
        echo 'row ' . $e->getRowNumber() . ': ' . $e->getErrorMessage() . "\n";
    }
    exit(1);
}
$ok = $import->importSource();
$import->invalidateIndex();
echo ($ok ? 'imported ' : 'import FAILED ') . $import->getProcessedRowsCount() . " rows ($behavior)\n";
