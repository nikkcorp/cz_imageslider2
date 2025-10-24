<?php
/**
 * Upgrade script for adding performance indexes
 * Version 1.0.1 - Security and Performance improvements
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    $sql = array();

    // Add index to czhomeslider table for better performance
    $sql[] = 'ALTER TABLE `'._DB_PREFIX_.'czhomeslider`
              ADD INDEX IF NOT EXISTS `id_shop` (`id_shop`)';

    // Add composite index for active and position for better sorting performance
    $sql[] = 'ALTER TABLE `'._DB_PREFIX_.'czhomeslider_slides`
              ADD INDEX IF NOT EXISTS `active_position` (`active`, `position`)';

    // Add index to slides_lang table for language lookups
    $sql[] = 'ALTER TABLE `'._DB_PREFIX_.'czhomeslider_slides_lang`
              ADD INDEX IF NOT EXISTS `id_lang` (`id_lang`)';

    foreach ($sql as $query) {
        if (!Db::getInstance()->execute($query)) {
            return false;
        }
    }

    // Clear cache after upgrade
    $module->clearCache();

    return true;
}
