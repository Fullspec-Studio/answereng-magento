<?php
// Limited admin roles/users for the ACL checks:
//   aesync     / Sync1234!local  -> settings + status page + sync + fixes (no delete_store)
//   aeconfig   / Conf1234!local  -> settings + status page          (no sync, no fixes, no delete_store)
//   aesettings / Sett1234!local  -> settings only                   (no status page)
require '/var/www/html/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$base = ['Magento_Backend::admin', 'Magento_Backend::dashboard', 'Magento_Backend::stores',
    'Magento_Backend::stores_settings', 'Magento_Config::config', 'Fullspec_AnswerEng::config'];
$status = array_merge($base, ['Magento_Catalog::catalog', 'Fullspec_AnswerEng::status']);
$defs = [
    ['AnswerEng sync (no delete)', 'aesync', 'Sync1234!local', array_merge($status, ['Fullspec_AnswerEng::sync', 'Fullspec_AnswerEng::fixes'])],
    ['AnswerEng config only', 'aeconfig', 'Conf1234!local', $status],
    ['AnswerEng settings only', 'aesettings', 'Sett1234!local', $base],
];
foreach ($defs as [$roleName, $username, $password, $resources]) {
    $role = $om->create(\Magento\Authorization\Model\Role::class)->load($roleName, 'role_name');
    if (!$role->getId()) {
        $role->setRoleName($roleName)->setRoleType(\Magento\Authorization\Model\Acl\Role\Group::ROLE_TYPE)
            ->setUserType((string) \Magento\Authorization\Model\UserContextInterface::USER_TYPE_ADMIN)
            ->setParentId(0)->setTreeLevel(1)->save();
    }
    $om->create(\Magento\Authorization\Model\Rules::class)->setRoleId($role->getId())->setResources($resources)->saveRel();
    $user = $om->create(\Magento\User\Model\User::class)->loadByUsername($username);
    if (!$user->getId()) {
        $user->setData(['username' => $username, 'firstname' => 'AE', 'lastname' => $username,
            'email' => $username . '@example.com', 'password' => $password, 'is_active' => 1]);
    }
    $user->setRoleId($role->getId())->save();
    echo "$username -> role {$role->getId()} ({$roleName})\n";
}
