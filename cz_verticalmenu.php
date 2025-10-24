<?php
/**
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2016 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registred Trademark & Property of PrestaShop SA
*/

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

require(dirname(__FILE__).'/cz_verticalmenutoplinks.class.php');

class Cz_VerticalMenu extends Module implements WidgetInterface
{
    const MENU_JSON_CACHE_KEY = 'MOD_BLOCKTOPMENU_MENU_JSON';

    protected $_menu = '';
    protected $_html = '';
    protected $user_groups;

    /*
     * Pattern for matching config values
     */
    protected $pattern = '/^([A-Z_]*)[0-9]+/';

    /*
     * Name of the controller
     * Used to set item selected or not in top menu
     */
    protected $page_name = '';

    /*
     * Spaces per depth in BO
     */
    protected $spacer_size = '5';

    public function __construct()
    {
        $this->name = 'cz_verticalmenu';
        $this->tab = 'front_office_features';
        $this->version = '1.0.5';
        $this->author = 'Codezeel';

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('CZ - Vertical Menu');
        $this->description = $this->l('Adds a vertical menu to the left sidebar of your e-commerce website.');
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        // Performance optimization: limit category depth
        if (!defined('CZ_VERTICALMENU_MAX_DEPTH')) {
            define('CZ_VERTICALMENU_MAX_DEPTH', (int)Configuration::get('CZ_VERTICALMENU_MAX_DEPTH') ?: 3);
        }
    }

    public function install($delete_params = true)
    {
        if (!parent::install() ||
            !$this->registerHook('actionObjectCategoryUpdateAfter') ||
            !$this->registerHook('actionObjectCategoryDeleteAfter') ||
            !$this->registerHook('actionObjectCategoryAddAfter') ||
            !$this->registerHook('actionObjectCmsUpdateAfter') ||
            !$this->registerHook('actionObjectCmsDeleteAfter') ||
            !$this->registerHook('actionObjectCmsAddAfter') ||
            !$this->registerHook('actionObjectSupplierUpdateAfter') ||
            !$this->registerHook('actionObjectSupplierDeleteAfter') ||
            !$this->registerHook('actionObjectSupplierAddAfter') ||
            !$this->registerHook('actionObjectManufacturerUpdateAfter') ||
            !$this->registerHook('actionObjectManufacturerDeleteAfter') ||
            !$this->registerHook('actionObjectManufacturerAddAfter') ||
            !$this->registerHook('actionObjectProductUpdateAfter') ||
            !$this->registerHook('actionObjectProductDeleteAfter') ||
            !$this->registerHook('actionObjectProductAddAfter') ||
            !$this->registerHook('categoryUpdate') ||
            !$this->registerHook('actionShopDataDuplication') ||
            !$this->registerHook('header') ||
            !$this->registerHook('displayNavFullWidth')) {
            return false;
        }

        if ($delete_params) {
            if (!$this->installDb() ||
                !Configuration::updateGlobalValue('MOD_CZVERTICALMENU_ITEMS', 'CAT3') ||
                !Configuration::updateGlobalValue('CZ_VERTICALMENU_MAX_DEPTH', 3) ||
                !Configuration::updateGlobalValue('CZ_VERTICALMENU_CACHE_ENABLED', 1)) {
                return false;
            }
        }

        return true;
    }

    public function installDb()
    {
        return (Db::getInstance()->execute('
		CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'czverticalmenu` (
			`id_czverticalmenu` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`id_shop` INT(11) UNSIGNED NOT NULL,
			`new_window` TINYINT( 1 ) NOT NULL,
			INDEX `idx_shop` (`id_shop`)
		) ENGINE = '._MYSQL_ENGINE_.' CHARACTER SET utf8 COLLATE utf8_general_ci;') &&
            Db::getInstance()->execute('
			 CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'czverticalmenu_lang` (
			`id_czverticalmenu` INT(11) UNSIGNED NOT NULL,
			`id_lang` INT(11) UNSIGNED NOT NULL,
			`id_shop` INT(11) UNSIGNED NOT NULL,
			`label` VARCHAR( 128 ) NOT NULL ,
			`link` VARCHAR( 128 ) NOT NULL ,
			INDEX `idx_menu_lang_shop` ( `id_czverticalmenu` , `id_lang`, `id_shop`),
			INDEX `idx_lang_shop` (`id_lang`, `id_shop`)
		) ENGINE = '._MYSQL_ENGINE_.' CHARACTER SET utf8 COLLATE utf8_general_ci;'));
    }

    public function uninstall($delete_params = true)
    {
        if (!parent::uninstall()) {
            return false;
        }

        $this->clearMenuCache();

        if ($delete_params) {
            if (!$this->uninstallDB() || !Configuration::deleteByName('MOD_CZVERTICALMENU_ITEMS')) {
                return false;
            }
        }

        return true;
    }

    protected function uninstallDb()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS`'._DB_PREFIX_.'czverticalmenu`');
        Db::getInstance()->execute('DROP TABLE IF EXISTS`'._DB_PREFIX_.'czverticalmenu_lang`');
        return true;
    }

    public function reset()
    {
        if (!$this->uninstall(false)) {
            return false;
        }
        if (!$this->install(false)) {
            return false;
        }

        return true;
    }

    public function getContent()
    {
        $this->context->controller->addjQueryPlugin('hoverIntent');

        $id_lang = (int)Context::getContext()->language->id;
        $languages = $this->context->controller->getLanguages();
        $default_language = (int)Configuration::get('PS_LANG_DEFAULT');

        $labels = Tools::getValue('label') ? array_filter(Tools::getValue('label'), 'strlen') : array();
        $links_label = Tools::getValue('link') ? array_filter(Tools::getValue('link'), 'strlen') : array();
        $spacer = str_repeat('&nbsp;', $this->spacer_size);
        $divLangName = 'link_label';

        $update_cache = false;

        if (Tools::isSubmit('submitCzverticalmenu')) {
            $errors_update_shops = array();
            $items = Tools::getValue('items');
            $shops = Shop::getContextListShopID();


            foreach ($shops as $shop_id) {
                $shop_group_id = Shop::getGroupFromShop($shop_id);
                $updated = true;

                if (count($shops) == 1) {
                    if (is_array($items) && count($items)) {
                        $updated = Configuration::updateValue('MOD_CZVERTICALMENU_ITEMS', (string)implode(',', $items), false, (int)$shop_group_id, (int)$shop_id);
                    } else {
                        $updated = Configuration::updateValue('MOD_CZVERTICALMENU_ITEMS', '', false, (int)$shop_group_id, (int)$shop_id);
                    }
                }
				
				$number_cols = Tools::getValue('number_cols_menu');
				$updated &= Configuration::updateValue('MOD_CZVERTICALMENU_COLS', (string)$number_cols, false, (int)$shop_group_id, (int)$shop_id);
				
                if (!$updated) {
                    $shop = new Shop($shop_id);
                    $errors_update_shops[] =  $shop->name;
                }
            }

            if (!count($errors_update_shops)) {
                $this->_html .= $this->displayConfirmation($this->l('The settings have been updated.'));
            } else {
                $this->_html .= $this->displayError(sprintf($this->l('Unable to update settings for the following shop(s): %s'), implode(', ', $errors_update_shops)));
            }

            $update_cache = true;
        } else {
            if (Tools::isSubmit('submitCzverticalmenuLinks')) {
                $errors_add_link = array();

                foreach ($languages as $key => $val) {
                    $links_label[$val['id_lang']] = Tools::getValue('link_'.(int)$val['id_lang']);
                    $labels[$val['id_lang']] = Tools::getValue('label_'.(int)$val['id_lang']);
                }

                $count_links_label = count($links_label);
                $count_label = count($labels);

                if ($count_links_label || $count_label) {
                    if (!$count_links_label) {
                        $this->_html .= $this->displayError($this->l('Please complete the "Link" field.'));
                    } elseif (!$count_label) {
                        $this->_html .= $this->displayError($this->l('Please add a label.'));
                    } elseif (!isset($labels[$default_language])) {
                        $this->_html .= $this->displayError($this->l('Please add a label for your default language.'));
                    } else {
                        $shops = Shop::getContextListShopID();

                        foreach ($shops as $shop_id) {
                            $added = Cz_VerticalMenuTopLinks::add($links_label, $labels,  Tools::getValue('new_window', 0), (int)$shop_id);

                            if (!$added) {
                                $shop = new Shop($shop_id);
                                $errors_add_link[] =  $shop->name;
                            }
                        }

                        if (!count($errors_add_link)) {
                            $this->_html .= $this->displayConfirmation($this->l('The link has been added.'));
                        } else {
                            $this->_html .= $this->displayError(sprintf($this->l('Unable to add link for the following shop(s): %s'), implode(', ', $errors_add_link)));
                        }
                    }
                }
                $update_cache = true;
            } elseif (Tools::isSubmit('deleteczverticalmenu')) {
                $errors_delete_link = array();
                $id_czverticalmenu = Tools::getValue('id_czverticalmenu', 0);
                $shops = Shop::getContextListShopID();

                foreach ($shops as $shop_id) {
                    $deleted = Cz_VerticalMenuTopLinks::remove($id_czverticalmenu, (int)$shop_id);
                    Configuration::updateValue('MOD_CZVERTICALMENU_ITEMS', str_replace(array('LNK'.$id_czverticalmenu.',', 'LNK'.$id_czverticalmenu), '', Configuration::get('MOD_CZVERTICALMENU_ITEMS')));

                    if (!$deleted) {
                        $shop = new Shop($shop_id);
                        $errors_delete_link[] =  $shop->name;
                    }
                }

                if (!count($errors_delete_link)) {
                    $this->_html .= $this->displayConfirmation($this->l('The link has been removed.'));
                } else {
                    $this->_html .= $this->displayError(sprintf($this->l('Unable to remove link for the following shop(s): %s'), implode(', ', $errors_delete_link)));
                }

                $update_cache = true;
            } elseif (Tools::isSubmit('updateczverticalmenu')) {
                $id_czverticalmenu = (int)Tools::getValue('id_czverticalmenu', 0);
                $id_shop = (int)Shop::getContextShopID();

                if (Tools::isSubmit('updatelink')) {
                    $link = array();
                    $label = array();
                    $new_window = (int)Tools::getValue('new_window', 0);

                    foreach (Language::getLanguages(false) as $lang) {
                        $link[$lang['id_lang']] = Tools::getValue('link_'.(int)$lang['id_lang']);
                        $label[$lang['id_lang']] = Tools::getValue('label_'.(int)$lang['id_lang']);
                    }

                    Cz_VerticalMenuTopLinks::update($link, $label, $new_window, (int)$id_shop, (int)$id_czverticalmenu, (int)$id_czverticalmenu);
                    $this->_html .= $this->displayConfirmation($this->l('The link has been edited.'));
                }
                $update_cache = true;
            }
        }

        if ($update_cache) {
            $this->clearMenuCache();
        }


        $shops = Shop::getContextListShopID();
        $links = array();

        if (count($shops) > 1) {
            $this->_html .= $this->getWarningMultishopHtml();
        }

        if (Shop::isFeatureActive()) {
            $this->_html .= $this->getCurrentShopInfoMsg();
        }

        $this->_html .= $this->renderForm().$this->renderAddForm();

        foreach ($shops as $shop_id) {
            $links = array_merge($links, Cz_VerticalMenuTopLinks::gets((int)$id_lang, null, (int)$shop_id));
        }

        if (!count($links)) {
            return $this->_html;
        }

        $this->_html .= $this->renderList();
        return $this->_html;
    }

    protected function getWarningMultishopHtml()
    {
        return '<p class="alert alert-warning">'.
                    $this->l('You cannot manage top menu items from a "All Shops" or a "Group Shop" context, select directly the shop you want to edit').
                '</p>';
    }

    protected function getCurrentShopInfoMsg()
    {
        $shop_info = null;

        if (Shop::getContext() == Shop::CONTEXT_SHOP) {
            $shop_info = sprintf($this->l('The modifications will be applied to shop: %s'), $this->context->shop->name);
        } else {
            if (Shop::getContext() == Shop::CONTEXT_GROUP) {
                $shop_info = sprintf($this->l('The modifications will be applied to this group: %s'), Shop::getContextShopGroup()->name);
            } else {
                $shop_info = $this->l('The modifications will be applied to all shops');
            }
        }

        return '<div class="alert alert-info">'.
                    $shop_info.
                '</div>';
    }

    protected function getMenuItems()
    {
        $items = Tools::getValue('items');
        if (is_array($items) && count($items)) {
            return $items;
        } else {
            $shops = Shop::getContextListShopID();
            $conf = null;

            if (count($shops) > 1) {
                foreach ($shops as $key => $shop_id) {
                    $shop_group_id = Shop::getGroupFromShop($shop_id);
                    $conf .= (string)($key > 1 ? ',' : '').Configuration::get('MOD_CZVERTICALMENU_ITEMS', null, $shop_group_id, $shop_id);
                }
            } else {
                $shop_id = (int)$shops[0];
                $shop_group_id = Shop::getGroupFromShop($shop_id);
                $conf = Configuration::get('MOD_CZVERTICALMENU_ITEMS', null, $shop_group_id, $shop_id);
            }

            if (Tools::strlen($conf)) {
                return explode(',', $conf);
            } else {
                return array();
            }
        }
    }

    protected function makeMenuOption()
    {
        $id_shop = (int)Shop::getContextShopID();

        $menu_item = $this->getMenuItems();
        $id_lang = (int)$this->context->language->id;

        $html = '<select multiple="multiple" name="items[]" id="items" style="width: 300px; height: 160px;">';
        foreach ($menu_item as $item) {
            if (!$item) {
                continue;
            }

            preg_match($this->pattern, $item, $values);
            $id = (int)Tools::substr($item, Tools::strlen($values[1]), Tools::strlen($item));

            switch (Tools::substr($item, 0, Tools::strlen($values[1]))) {
                case 'CAT':
                    $category = new Category((int)$id, (int)$id_lang);
                    if (Validate::isLoadedObject($category)) {
                        $html .= '<option selected="selected" value="CAT'.$id.'">'.$category->name.'</option>'.PHP_EOL;
                    }
                    break;

                case 'PRD':
                    $product = new Product((int)$id, true, (int)$id_lang);
                    if (Validate::isLoadedObject($product)) {
                        $html .= '<option selected="selected" value="PRD'.$id.'">'.$product->name.'</option>'.PHP_EOL;
                    }
                    break;

                case 'CMS':
                    $cms = new CMS((int)$id, (int)$id_lang);
                    if (Validate::isLoadedObject($cms)) {
                        $html .= '<option selected="selected" value="CMS'.$id.'">'.$cms->meta_title.'</option>'.PHP_EOL;
                    }
                    break;

                case 'CMS_CAT':
                    $category = new CMSCategory((int)$id, (int)$id_lang);
                    if (Validate::isLoadedObject($category)) {
                        $html .= '<option selected="selected" value="CMS_CAT'.$id.'">'.$category->name.'</option>'.PHP_EOL;
                    }
                    break;

                // Case to handle the option to show all Manufacturers
                case 'ALLMAN':
                    $html .= '<option selected="selected" value="ALLMAN0">'.$this->l('All manufacturers').'</option>'.PHP_EOL;
                    break;

                case 'MAN':
                    $manufacturer = new Manufacturer((int)$id, (int)$id_lang);
                    if (Validate::isLoadedObject($manufacturer)) {
                        $html .= '<option selected="selected" value="MAN'.$id.'">'.$manufacturer->name.'</option>'.PHP_EOL;
                    }
                    break;

                // Case to handle the option to show all Suppliers
                case 'ALLSUP':
                    $html .= '<option selected="selected" value="ALLSUP0">'.$this->l('All suppliers').'</option>'.PHP_EOL;
                    break;

                case 'SUP':
                    $supplier = new Supplier((int)$id, (int)$id_lang);
                    if (Validate::isLoadedObject($supplier)) {
                        $html .= '<option selected="selected" value="SUP'.$id.'">'.$supplier->name.'</option>'.PHP_EOL;
                    }
                    break;

                case 'LNK':
                    $link = Cz_VerticalMenuTopLinks::get((int)$id, (int)$id_lang, (int)$id_shop);
                    if (count($link)) {
                        if (!isset($link[0]['label']) || ($link[0]['label'] == '')) {
                            $default_language = Configuration::get('PS_LANG_DEFAULT');
                            $link = Cz_VerticalMenuTopLinks::get($link[0]['id_czverticalmenu'], (int)$default_language, (int)Shop::getContextShopID());
                        }
                        $html .= '<option selected="selected" value="LNK'.(int)$link[0]['id_czverticalmenu'].'">'.Tools::safeOutput($link[0]['label']).'</option>';
                    }
                    break;

                case 'SHOP':
                    $shop = new Shop((int)$id);
                    if (Validate::isLoadedObject($shop)) {
                        $html .= '<option selected="selected" value="SHOP'.(int)$id.'">'.$shop->name.'</option>'.PHP_EOL;
                    }
                    break;
            }
        }

        return $html.'</select>';
    }

    protected function makeNode(array $fields)
    {
        $defaults = [
            'type' => '',
            'label' => '',
            'url' => '',
            'children' => [],
            'open_in_new_window' => false,
            'image_urls' => [],
            'page_identifier' => null
        ];

        return array_merge($defaults, $fields);
    }

    protected function generateCMSCategoriesMenu($id_cms_category, $id_lang)
    {
        $category = new CMSCategory($id_cms_category, $id_lang);

        $rawSubCategories = $this->getCMSCategories(false, $id_cms_category, $id_lang);
        $rawSubPages = $this->getCMSPages($id_cms_category);

        $subCategories = array_map(function ($category) use ($id_lang) {
            return $this->generateCMSCategoriesMenu($category['id_cms_category'], $id_lang);
        }, $rawSubCategories);

        $subPages = array_map(function ($page) use ($id_lang) {
            return $this->makeNode([
                'type' => 'cms-page',
                'page_identifier' => 'cms-page-' . $page['id_cms'],
                'label' => $page['meta_title'],
                'url' => $this->context->link->getCMSLink(
                    new CMS($page['id_cms'], $id_lang),
                    null, null,
                    $id_lang
                ),
            ]);
        }, $rawSubPages);

        $node = $this->makeNode([
            'type' => 'cms-category',
            'page_identifier' => 'cms-category-' . $id_cms_category,
            'label' => $category->name,
            'url' => $category->getLink(),
            'children' => array_merge($subCategories, $subPages)
        ]);

        return $node;
    }

    protected function makeMenu()
    {
        $root_node = $this->makeNode([
            'label' => null,
            'type'  => 'root',
            'children' => []
        ]);

        $menu_items = $this->getMenuItems();
        $id_lang = (int)$this->context->language->id;
        $id_shop = (int)Shop::getContextShopID();
		
		$number_cols_menus = $this->getNumberColsMenuItems();
        
		foreach ($menu_items as $item) {
            if (!$item) {
                continue;
            }

            preg_match($this->pattern, $item, $value);
            $id = (int)Tools::substr($item, Tools::strlen($value[1]), Tools::strlen($item));
			$key = '';
			$in_number_cols = ((isset($number_cols_menus[$key]) && $number_cols_menus[$key] != '')) ? $number_cols_menus[$key] : '';
			if ($in_number_cols <= 4) {
				$number_col = $in_number_cols;
			} elseif ($in_number_cols > 4) {
				$number_col = 4;
			}
			$width = 240;
            switch (Tools::substr($item, 0, Tools::strlen($value[1]))) {
                case 'CAT':
                    // OPTIMIZATION: Use optimized method with depth limit
                    $nestedCategories = $this->getNestedCategoriesOptimized($id, $id_lang, $this->user_groups);
                    if (!empty($nestedCategories)) {
                        $categories = $this->generateCategoriesMenu($nestedCategories);
                        $root_node['children'] = array_merge($root_node['children'], $categories);
                    }
                    break;

                case 'PRD':
                    $product = new Product((int)$id, true, (int)$id_lang);
                    if ($product->id) {
                        $root_node['children'][] = $this->makeNode([
                            'type' => 'product',
                            'page_identifier' => 'product-' . $product->id,
                            'label' => $product->name,
                            'url' => $product->getLink(),
                        ]);
                    }
                    break;

                case 'CMS':
                    $cms = CMS::getLinks((int)$id_lang, array($id));
                    if (count($cms)) {
                        $root_node['children'][] = $this->makeNode([
                            'type' => 'cms-page',
                            'page_identifier' => 'cms-page-' . $id,
                            'label' => $cms[0]['meta_title'],
                            'url' => $cms[0]['link']
                        ]);
                    }
                    break;

                case 'CMS_CAT':
                    $root_node['children'][] = $this->generateCMSCategoriesMenu((int)$id, (int)$id_lang);
                    break;

                // Case to handle the option to show all Manufacturers
                case 'ALLMAN':
                    // OPTIMIZATION: Limit manufacturers and use cache
                    $cache_id_man = 'cz_verticalmenu_manufacturers_' . (int)$id_lang . '_' . (int)$id_shop;
                    if (Configuration::get('CZ_VERTICALMENU_CACHE_ENABLED') && Cache::isStored($cache_id_man)) {
                        $children = Cache::retrieve($cache_id_man);
                    } else {
                        $manufacturers = Manufacturer::getManufacturers(false, $id_lang, true, 1, 50, false);
                        $children = array_map(function ($manufacturer) use ($id_lang) {
                            return $this->makeNode([
                                'type' => 'manufacturer',
                                'page_identifier' => 'manufacturer-' . $manufacturer['id_manufacturer'],
                                'label' => $manufacturer['name'],
                                'url' => $this->context->link->getManufacturerLink(
                                    (int)$manufacturer['id_manufacturer'],
                                    null,
                                    $id_lang
                                )
                            ]);
                        }, $manufacturers);

                        if (Configuration::get('CZ_VERTICALMENU_CACHE_ENABLED')) {
                            Cache::store($cache_id_man, $children);
                        }
                    }

                    $root_node['children'][] = $this->makeNode([
                        'type' => 'manufacturers',
                        'page_identifier' => 'manufacturers',
                        'label' => $this->l('All manufacturers'),
                        'url' => $this->context->link->getPageLink('manufacturer'),
                        'children' => $children
                    ]);
                    break;

                case 'MAN':
                    $manufacturer = new Manufacturer($id, $id_lang);
                    if ($manufacturer->id) {
                        $root_node['children'][] = $this->makeNode([
                            'type' => 'manufacturer',
                            'page_identifier' => 'manufacturer-' . $manufacturer->id,
                            'label' => $manufacturer->name,
                            'url' => $this->context->link->getManufacturerLink(
                                $manufacturer,
                                null,
                                $id_lang
                            )
                        ]);
                    }
                    break;

                // Case to handle the option to show all Suppliers
                case 'ALLSUP':
                    // OPTIMIZATION: Limit suppliers and use cache
                    $cache_id_sup = 'cz_verticalmenu_suppliers_' . (int)$id_lang . '_' . (int)$id_shop;
                    if (Configuration::get('CZ_VERTICALMENU_CACHE_ENABLED') && Cache::isStored($cache_id_sup)) {
                        $children = Cache::retrieve($cache_id_sup);
                    } else {
                        $suppliers = Supplier::getSuppliers(false, $id_lang, true, 1, 50, false);
                        $children = array_map(function ($supplier) use ($id_lang) {
                            return $this->makeNode([
                                'type' => 'supplier',
                                'page_identifier' => 'supplier-' . $supplier['id_supplier'],
                                'label' => $supplier['name'],
                                'url' => $this->context->link->getSupplierLink(
                                    (int)$supplier['id_supplier'],
                                    null,
                                    $id_lang
                                )
                            ]);
                        }, $suppliers);

                        if (Configuration::get('CZ_VERTICALMENU_CACHE_ENABLED')) {
                            Cache::store($cache_id_sup, $children);
                        }
                    }

                    $root_node['children'][] = $this->makeNode([
                        'type' => 'suppliers',
                        'page_identifier' => 'suppliers',
                        'label' => $this->l('All suppliers'),
                        'url' => $this->context->link->getPageLink('supplier'),
                        'children' => $children
                    ]);
                    break;

                case 'SUP':
                    $supplier = new Supplier($id, $id_lang);
                    if ($supplier->id) {
                        $root_node['children'][] = $this->makeNode([
                            'type' => 'supplier',
                            'page_identifier' => 'supplier-' . $supplier->id,
                            'label' => $supplier->name,
                            'url' => $this->context->link->getSupplierLink(
                                $supplier,
                                null,
                                $id_lang
                            )
                        ]);
                    }
                    break;

                case 'SHOP':
                    $shop = new Shop((int)$id);
                    if (Validate::isLoadedObject($shop)) {
                        $root_node['children'][] = $this->makeNode([
                            'type' => 'shop',
                            'page_identifier' => 'shop-' . $id,
                            'label' => $shop->name,
                            'url' => $shop->getBaseURL(),
                        ]);
                    }
                    break;
                case 'LNK':
                    $link = Cz_VerticalMenuTopLinks::get($id, $id_lang, $id_shop);
                    if (!empty($link)) {
                        if (!isset($link[0]['label']) || ($link[0]['label'] == '')) {
                            $default_language = Configuration::get('PS_LANG_DEFAULT');
                            $link = Cz_VerticalMenuTopLinks::get($link[0]['id_czverticalmenu'], $default_language, (int)Shop::getContextShopID());
                        }
                        $root_node['children'][] = $this->makeNode([
                            'type' => 'link',
                            'page_identifier' => $link[0]['link'],
                            'label' => $link[0]['label'],
                            'url' => $link[0]['link'],
                            'open_in_new_window' => $link[0]['new_window']
                        ]);
                    }
                    break;
            }
        }

        return $this->mapTree(function ($node, $depth) {
            $node['depth'] = $depth;
            return $node;
        }, $root_node);
    }

    protected function mapTree(callable $cb, array $node, $depth = 0)
    {
        $node['children'] = array_map(function ($child) use ($cb, $depth) {
            return $this->mapTree($cb, $child, $depth + 1);
        }, $node['children']);
        return $cb($node, $depth);
    }

    /**
     * OPTIMIZATION: Get nested categories with depth limit and caching
     */
    protected function getNestedCategoriesOptimized($root_category, $id_lang, $groups = null)
    {
        $cache_id = 'cz_verticalmenu_nested_cat_' . (int)$root_category . '_' . (int)$id_lang . '_' . (int)$this->context->shop->id;

        if (Configuration::get('CZ_VERTICALMENU_CACHE_ENABLED') && Cache::isStored($cache_id)) {
            return Cache::retrieve($cache_id);
        }

        $max_depth = CZ_VERTICALMENU_MAX_DEPTH;
        $id_shop = (int)$this->context->shop->id;

        // OPTIMIZATION: Use single optimized query instead of recursive calls
        $sql = 'SELECT c.id_category, c.id_parent, c.level_depth, c.active, c.position, c.nleft, c.nright,
                cl.name, cl.link_rewrite, cs.id_shop
                FROM `'._DB_PREFIX_.'category` c
                INNER JOIN `'._DB_PREFIX_.'category_shop` cs ON (c.id_category = cs.id_category AND cs.id_shop = '.(int)$id_shop.')
                LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (c.id_category = cl.id_category AND cl.id_shop = '.(int)$id_shop.' AND cl.id_lang = '.(int)$id_lang.')
                WHERE c.active = 1
                AND c.id_category = '.(int)$root_category.'
                ORDER BY c.level_depth ASC, cs.position ASC
                LIMIT 1';

        $root = Db::getInstance()->getRow($sql);

        if (!$root) {
            return [];
        }

        // Get all children up to max depth using nested set model
        $sql = 'SELECT c.id_category, c.id_parent, c.level_depth, c.active, c.position,
                cl.name, cl.link_rewrite, cs.id_shop
                FROM `'._DB_PREFIX_.'category` c
                INNER JOIN `'._DB_PREFIX_.'category_shop` cs ON (c.id_category = cs.id_category AND cs.id_shop = '.(int)$id_shop.')
                LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (c.id_category = cl.id_category AND cl.id_shop = '.(int)$id_shop.' AND cl.id_lang = '.(int)$id_lang.')
                WHERE c.active = 1
                AND c.nleft > '.(int)$root['nleft'].'
                AND c.nright < '.(int)$root['nright'].'
                AND c.level_depth <= '.((int)$root['level_depth'] + (int)$max_depth).'
                ORDER BY c.level_depth ASC, cs.position ASC';

        $categories = Db::getInstance()->executeS($sql);

        if (!$categories) {
            $categories = [];
        }

        // Build tree structure
        $tree = [];
        $indexed = [];

        // Add root
        $indexed[$root['id_category']] = $root;
        $indexed[$root['id_category']]['children'] = [];
        $tree[$root['id_category']] = &$indexed[$root['id_category']];

        foreach ($categories as $category) {
            $indexed[$category['id_category']] = $category;
            $indexed[$category['id_category']]['children'] = [];

            if (isset($indexed[$category['id_parent']])) {
                $indexed[$category['id_parent']]['children'][$category['id_category']] = &$indexed[$category['id_category']];
            }
        }

        if (Configuration::get('CZ_VERTICALMENU_CACHE_ENABLED')) {
            Cache::store($cache_id, $tree);
        }

        return $tree;
    }

    protected function generateCategoriesOption($categories, $items_to_skip = null)
    {
        $html = '';

        foreach ($categories as $key => $category) {
            if (isset($items_to_skip) /*&& !in_array('CAT'.(int)$category['id_category'], $items_to_skip)*/) {
                $shop = (object) Shop::getShop((int)$category['id_shop']);
                $html .= '<option value="CAT'.(int)$category['id_category'].'">'
                    .str_repeat('&nbsp;', $this->spacer_size * (int)$category['level_depth']).$category['name'].' ('.$shop->name.')</option>';
            }

            if (isset($category['children']) && !empty($category['children'])) {
                $html .= $this->generateCategoriesOption($category['children'], $items_to_skip);
            }
        }
        return $html;
    }

    protected function generateCategoriesMenu($categories, $is_children = 0, $cat_images = null, $current_depth = 0)
    {
        $nodes = [];
        $max_depth = CZ_VERTICALMENU_MAX_DEPTH;

        // OPTIMIZATION: Stop recursion if max depth reached
        if ($current_depth >= $max_depth) {
            return $nodes;
        }

        // OPTIMIZATION: Load category images only once at the top level
        if ($is_children == 0 && $cat_images === null) {
            $cat_images = @scandir(_PS_CAT_IMG_DIR_);
            if ($cat_images === false) {
                $cat_images = [];
            }
        }

        foreach ($categories as $key => $category) {
            $node = $this->makeNode([]);

            // OPTIMIZATION: Use context link instead of creating Category object
            if ($category['level_depth'] > 1) {
                $link = $this->context->link->getCategoryLink(
                    $category['id_category'],
                    isset($category['link_rewrite']) ? $category['link_rewrite'] : null
                );
            } else {
                $link = $this->context->link->getPageLink('index');
            }

            $node['url'] = $link;
            $node['type'] = 'category';
            $node['page_identifier'] = 'czcategory-' . $category['id_category'];

            /* Whenever a category is not active we shouldnt display it to customer */
            if ((bool)$category['active'] === false) {
                continue;
            }

            $current = $this->page_name == 'category' && (int)Tools::getValue('id_category') == (int)$category['id_category'];
            $node['current'] = $current;
            $node['label']   = $category['name'];
            $node['image_urls']  = [];

            if (isset($category['children']) && !empty($category['children']) && $current_depth < ($max_depth - 1)) {
                // OPTIMIZATION: Pass depth parameter to limit recursion
                $node['children'] = $this->generateCategoriesMenu($category['children'], 1, $cat_images, $current_depth + 1);

                // OPTIMIZATION: Use pre-loaded $cat_images instead of calling scandir again
                if (!empty($cat_images) && count(preg_grep('/^'.$category['id_category'].'-([0-9])?_thumb.jpg/i', $cat_images)) > 0) {
                    foreach ($cat_images as $file) {
                        if (preg_match('/^'.$category['id_category'].'-([0-9])?_thumb.jpg/i', $file) === 1) {
                            $image_url = $this->context->link->getMediaLink(_THEME_CAT_DIR_.$file);
                            $node['image_urls'][] = $image_url;
                        }
                    }
                }
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    protected function getCMSOptions($parent = 0, $depth = 1, $id_lang = false, $items_to_skip = null, $id_shop = false)
    {
        $html = '';
        $id_lang = $id_lang ? (int)$id_lang : (int)Context::getContext()->language->id;
        $id_shop = ($id_shop !== false) ? $id_shop : Context::getContext()->shop->id;
        $categories = $this->getCMSCategories(false, (int)$parent, (int)$id_lang, (int)$id_shop);
        $pages = $this->getCMSPages((int)$parent, (int)$id_shop, (int)$id_lang);

        $spacer = str_repeat('&nbsp;', $this->spacer_size * (int)$depth);

        foreach ($categories as $category) {
            if (isset($items_to_skip) && !in_array('CMS_CAT'.$category['id_cms_category'], $items_to_skip)) {
                $html .= '<option value="CMS_CAT'.$category['id_cms_category'].'" style="font-weight: bold;">'.$spacer.$category['name'].'</option>';
            }
            $html .= $this->getCMSOptions($category['id_cms_category'], (int)$depth + 1, (int)$id_lang, $items_to_skip);
        }

        foreach ($pages as $page) {
            if (isset($items_to_skip) && !in_array('CMS'.$page['id_cms'], $items_to_skip)) {
                $html .= '<option value="CMS'.$page['id_cms'].'">'.$spacer.$page['meta_title'].'</option>';
            }
        }

        return $html;
    }

    protected function getCacheId($name = null)
    {
        $page_name = in_array($this->page_name, array('category', 'supplier', 'manufacturer', 'cms', 'product')) ? $this->page_name : 'index';
        return parent::getCacheId().'|'.$page_name.($page_name != 'index' ? '|'.(int)Tools::getValue('id_'.$page_name) : '');
    }

    protected function getCMSCategories($recursive = false, $parent = 1, $id_lang = false, $id_shop = false)
    {
        $id_lang = $id_lang ? (int)$id_lang : (int)Context::getContext()->language->id;
        $id_shop = ($id_shop !== false) ? $id_shop : Context::getContext()->shop->id;
        $join_shop = '';
        $where_shop = '';

        if (Tools::version_compare(_PS_VERSION_, '1.6.0.12', '>=') == true) {
            $join_shop = ' INNER JOIN `'._DB_PREFIX_.'cms_category_shop` cs
			ON (bcp.`id_cms_category` = cs.`id_cms_category`)';
            $where_shop = ' AND cs.`id_shop` = '.(int)$id_shop.' AND cl.`id_shop` = '.(int)$id_shop;
        }

        if ($recursive === false) {
            $sql = 'SELECT bcp.`id_cms_category`, bcp.`id_parent`, bcp.`level_depth`, bcp.`active`, bcp.`position`, cl.`name`, cl.`link_rewrite`
				FROM `'._DB_PREFIX_.'cms_category` bcp'.
                $join_shop.'
				INNER JOIN `'._DB_PREFIX_.'cms_category_lang` cl
				ON (bcp.`id_cms_category` = cl.`id_cms_category`)
				WHERE cl.`id_lang` = '.(int)$id_lang.'
				AND bcp.`id_parent` = '.(int)$parent.
                $where_shop;

            return Db::getInstance()->executeS($sql);
        } else {
            $sql = 'SELECT bcp.`id_cms_category`, bcp.`id_parent`, bcp.`level_depth`, bcp.`active`, bcp.`position`, cl.`name`, cl.`link_rewrite`
				FROM `'._DB_PREFIX_.'cms_category` bcp'.
                $join_shop.'
				INNER JOIN `'._DB_PREFIX_.'cms_category_lang` cl
				ON (bcp.`id_cms_category` = cl.`id_cms_category`)
				WHERE cl.`id_lang` = '.(int)$id_lang.'
				AND bcp.`id_parent` = '.(int)$parent.
                $where_shop;

            $results = Db::getInstance()->executeS($sql);
            foreach ($results as $result) {
                $sub_categories = $this->getCMSCategories(true, $result['id_cms_category'], (int)$id_lang);
                if ($sub_categories && count($sub_categories) > 0) {
                    $result['sub_categories'] = $sub_categories;
                }
                $categories[] = $result;
            }

            return isset($categories) ? $categories : false;
        }
    }

    protected function getCMSPages($id_cms_category, $id_shop = false, $id_lang = false)
    {
        $id_shop = ($id_shop !== false) ? (int)$id_shop : (int)Context::getContext()->shop->id;
        $id_lang = $id_lang ? (int)$id_lang : (int)Context::getContext()->language->id;

        $where_shop = '';
        if (Tools::version_compare(_PS_VERSION_, '1.6.0.12', '>=') == true) {
            $where_shop = ' AND cl.`id_shop` = '.(int)$id_shop;
        }

        $sql = 'SELECT c.`id_cms`, cl.`meta_title`, cl.`link_rewrite`
			FROM `'._DB_PREFIX_.'cms` c
			INNER JOIN `'._DB_PREFIX_.'cms_shop` cs
			ON (c.`id_cms` = cs.`id_cms`)
			INNER JOIN `'._DB_PREFIX_.'cms_lang` cl
			ON (c.`id_cms` = cl.`id_cms`)
			WHERE c.`id_cms_category` = '.(int)$id_cms_category.'
			AND cs.`id_shop` = '.(int)$id_shop.'
			AND cl.`id_lang` = '.(int)$id_lang.
            $where_shop.'
			AND c.`active` = 1
			ORDER BY `position`';

        return Db::getInstance()->executeS($sql);
    }

    public function hookActionObjectCategoryAddAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectCategoryUpdateAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectCategoryDeleteAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectCmsUpdateAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectCmsDeleteAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectCmsAddAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectSupplierUpdateAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectSupplierDeleteAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectSupplierAddAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectManufacturerUpdateAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectManufacturerDeleteAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectManufacturerAddAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectProductDeleteAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookActionObjectProductAddAfter($params)
    {
        $this->clearMenuCache();
    }

    public function hookCategoryUpdate($params)
    {
        $this->clearMenuCache();
    }

    protected function getCacheDirectory()
    {
        return _PS_CACHE_DIR_ . DIRECTORY_SEPARATOR . 'cz_verticalmenu';
    }

    protected function clearMenuCache()
    {
        $dir = $this->getCacheDirectory();

        if (!is_dir($dir)) {
            return;
        }

        // OPTIMIZATION: Use glob instead of scandir for better performance
        $json_files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if ($json_files !== false) {
            foreach ($json_files as $file) {
                @unlink($file);
            }
        }
    }

    public function hookActionShopDataDuplication($params)
    {
        $czverticalmenu = Db::getInstance()->executeS('
			SELECT *
			FROM '._DB_PREFIX_.'czverticalmenu
			WHERE id_shop = '.(int)$params['old_id_shop']
            );

        foreach ($czverticalmenu as $id => $link) {
            Db::getInstance()->execute('
				INSERT IGNORE INTO '._DB_PREFIX_.'czverticalmenu (id_czverticalmenu, id_shop, new_window)
				VALUES (null, '.(int)$params['new_id_shop'].', '.(int)$link['new_window'].')');

            $czverticalmenu[$id]['new_id_czverticalmenu'] = Db::getInstance()->Insert_ID();
        }

        foreach ($czverticalmenu as $id => $link) {
            $lang = Db::getInstance()->executeS('
					SELECT id_lang, '.(int)$params['new_id_shop'].', label, link
					FROM '._DB_PREFIX_.'czverticalmenu_lang
					WHERE id_czverticalmenu = '.(int)$link['id_czverticalmenu'].' AND id_shop = '.(int)$params['old_id_shop']);

            foreach ($lang as $l) {
                Db::getInstance()->execute('
					INSERT IGNORE INTO '._DB_PREFIX_.'czverticalmenu_lang (id_czverticalmenu, id_lang, id_shop, label, link)
					VALUES ('.(int)$link['new_id_czverticalmenu'].', '.(int)$l['id_lang'].', '.(int)$params['new_id_shop'].', '.(int)$l['label'].', '.(int)$l['link'].' )');
            }
        }
    }

    public function renderForm()
    {
        $shops = Shop::getContextListShopID();

        if (count($shops) == 1) {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('Menu Top Link'),
                        'icon' => 'icon-link'
                    ),
                    'input' => array(
                        array(
                            'type' => 'link_choice',
                            'label' => '',
                            'name' => 'link',
                            'lang' => true,
                        )
                    ),
                    'submit' => array(
                        'name' => 'submitCzverticalmenu',
                        'title' => $this->l('Save')
                    )
                ),
            );
        } else {
            $fields_form = array(
                'form' => array(
                    'legend' => array(
                        'title' => $this->l('Menu Top Link'),
                        'icon' => 'icon-link'
                    ),
                    'info' => '<div class="alert alert-warning">'.
                        $this->l('All active products combinations quantities will be changed').'</div>',
                    'submit' => array(
                        'name' => 'submitCzverticalmenu',
                        'title' => $this->l('Save')
                    )
                ),
            );
        }

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).
            '&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
            'choices' => $this->renderChoicesSelect(),
            'selected_links' => $this->makeMenuOption(),
			//'MOD_CZVERTICALMENU_COLS' => $this->getNumberColsMenu(),
        );
        return $helper->generateForm(array($fields_form));
    }

    public function renderAddForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => (Tools::getIsset('updateczverticalmenu') && !Tools::getValue('updateczverticalmenu')) ?
                        $this->l('Update link') : $this->l('Add a new link'),
                    'icon' => 'icon-link'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Label'),
                        'name' => 'label',
                        'lang' => true,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Link'),
                        'name' => 'link',
                        'lang' => true,
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('New window'),
                        'name' => 'new_window',
                        'is_bool' => true,
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    )
                ),
                'submit' => array(
                    'name' => 'submitCzverticalmenuLinks',
                    'title' => $this->l('Add')
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->identifier = $this->identifier;
        $helper->fields_value = $this->getAddLinkFieldsValues();

        if (Tools::getIsset('updateczverticalmenu') && !Tools::getValue('updateczverticalmenu')) {
            $fields_form['form']['submit'] = array(
                'name' => 'updateczverticalmenu',
                'title' => $this->l('Update')
            );
        }

        if (Tools::isSubmit('updateczverticalmenu')) {
            $fields_form['form']['input'][] = array('type' => 'hidden', 'name' => 'updatelink');
            $fields_form['form']['input'][] = array('type' => 'hidden', 'name' => 'id_czverticalmenu');
            $helper->fields_value['updatelink'] = '';
        }

        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).
            '&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->languages = $this->context->controller->getLanguages();
        $helper->default_form_language = (int)$this->context->language->id;

        return $helper->generateForm(array($fields_form));
    }

    public function renderChoicesSelect()
    {
        $spacer = str_repeat('&nbsp;', $this->spacer_size);
        $items = $this->getMenuItems();

        $html = '<select multiple="multiple" id="availableItems" style="width: 300px; height: 160px;">';
        $html .= '<optgroup label="'.$this->l('CMS').'">';
        $html .= $this->getCMSOptions(0, 1, $this->context->language->id, $items);
        $html .= '</optgroup>';

        // BEGIN SUPPLIER
        $html .= '<optgroup label="'.$this->l('Supplier').'">';
        // Option to show all Suppliers
        $html .= '<option value="ALLSUP0">'.$this->l('All suppliers').'</option>';
        // OPTIMIZATION: Limit to 100 suppliers in admin dropdown
        $suppliers = Supplier::getSuppliers(false, $this->context->language->id, true, 1, 100);
        foreach ($suppliers as $supplier) {
            if (!in_array('SUP'.$supplier['id_supplier'], $items)) {
                $html .= '<option value="SUP'.$supplier['id_supplier'].'">'.$spacer.$supplier['name'].'</option>';
            }
        }
        $html .= '</optgroup>';

        // BEGIN Manufacturer
        $html .= '<optgroup label="'.$this->l('Manufacturer').'">';
        // Option to show all Manufacturers
        $html .= '<option value="ALLMAN0">'.$this->l('All manufacturers').'</option>';
        // OPTIMIZATION: Limit to 100 manufacturers in admin dropdown
        $manufacturers = Manufacturer::getManufacturers(false, $this->context->language->id, true, 1, 100);
        foreach ($manufacturers as $manufacturer) {
            if (!in_array('MAN'.$manufacturer['id_manufacturer'], $items)) {
                $html .= '<option value="MAN'.$manufacturer['id_manufacturer'].'">'.$spacer.$manufacturer['name'].'</option>';
            }
        }
        $html .= '</optgroup>';

        // BEGIN Categories
        $shop = new Shop((int)Shop::getContextShopID());
        $html .= '<optgroup label="'.$this->l('Categories').'">';

        $shops_to_get = Shop::getContextListShopID();

        foreach ($shops_to_get as $shop_id) {
            $html .= $this->generateCategoriesOption($this->customGetNestedCategories($shop_id, null, (int)$this->context->language->id, false), $items);
        }
        $html .= '</optgroup>';

        // BEGIN Shops
        if (Shop::isFeatureActive()) {
            $html .= '<optgroup label="'.$this->l('Shops').'">';
            $shops = Shop::getShopsCollection();
            foreach ($shops as $shop) {
                if (!$shop->setUrl() && !$shop->getBaseURL()) {
                    continue;
                }

                if (!in_array('SHOP'.(int)$shop->id, $items)) {
                    $html .= '<option value="SHOP'.(int)$shop->id.'">'.$spacer.$shop->name.'</option>';
                }
            }
            $html .= '</optgroup>';
        }

        // BEGIN Products
        $html .= '<optgroup label="'.$this->l('Products').'">';
        $html .= '<option value="PRODUCT" style="font-style:italic">'.$spacer.$this->l('Choose product ID').'</option>';
        $html .= '</optgroup>';

        // BEGIN Menu Top Links
        $html .= '<optgroup label="'.$this->l('Menu Top Links').'">';
        $links = Cz_VerticalMenuTopLinks::gets($this->context->language->id, null, (int)Shop::getContextShopID());
        foreach ($links as $link) {
            if ($link['label'] == '') {
                $default_language = Configuration::get('PS_LANG_DEFAULT');
                $link = Cz_VerticalMenuTopLinks::get($link['id_czverticalmenu'], $default_language, (int)Shop::getContextShopID());
                if (!in_array('LNK'.(int)$link[0]['id_czverticalmenu'], $items)) {
                    $html .= '<option value="LNK'.(int)$link[0]['id_czverticalmenu'].'">'.$spacer.Tools::safeOutput($link[0]['label']).'</option>';
                }
            } elseif (!in_array('LNK'.(int)$link['id_czverticalmenu'], $items)) {
                $html .= '<option value="LNK'.(int)$link['id_czverticalmenu'].'">'.$spacer.Tools::safeOutput($link['label']).'</option>';
            }
        }
        $html .= '</optgroup>';
        $html .= '</select>';
        return $html;
    }


    public function customGetNestedCategories($shop_id, $root_category = null, $id_lang = false, $active = false, $groups = null, $use_shop_restriction = true, $sql_filter = '', $sql_sort = '', $sql_limit = '')
    {
        if (isset($root_category) && !Validate::isInt($root_category)) {
            die(Tools::displayError());
        }

        if (!Validate::isBool($active)) {
            die(Tools::displayError());
        }

        if (isset($groups) && Group::isFeatureActive() && !is_array($groups)) {
            $groups = (array)$groups;
        }

        $cache_id = 'Category::getNestedCategories_'.md5((int)$shop_id.(int)$root_category.(int)$id_lang.(int)$active.(int)$active
            .(isset($groups) && Group::isFeatureActive() ? implode('', $groups) : ''));

        if (!Cache::isStored($cache_id)) {
            $result = Db::getInstance()->executeS('
							SELECT c.*, cl.*
				FROM `'._DB_PREFIX_.'category` c
				INNER JOIN `'._DB_PREFIX_.'category_shop` category_shop ON (category_shop.`id_category` = c.`id_category` AND category_shop.`id_shop` = "'.(int)$shop_id.'")
				LEFT JOIN `'._DB_PREFIX_.'category_lang` cl ON (c.`id_category` = cl.`id_category` AND cl.`id_shop` = "'.(int)$shop_id.'")
				WHERE 1 '.$sql_filter.' '.($id_lang ? 'AND cl.`id_lang` = '.(int)$id_lang : '').'
				'.($active ? ' AND (c.`active` = 1 OR c.`is_root_category` = 1)' : '').'
				'.(isset($groups) && Group::isFeatureActive() ? ' AND cg.`id_group` IN ('.implode(',', $groups).')' : '').'
				'.(!$id_lang || (isset($groups) && Group::isFeatureActive()) ? ' GROUP BY c.`id_category`' : '').'
				'.($sql_sort != '' ? $sql_sort : ' ORDER BY c.`level_depth` ASC').'
				'.($sql_sort == '' && $use_shop_restriction ? ', category_shop.`position` ASC' : '').'
				'.($sql_limit != '' ? $sql_limit : '')
            );

            $categories = array();
            $buff = array();

            foreach ($result as $row) {
                $current = &$buff[$row['id_category']];
                $current = $row;

                if ($row['id_parent'] == 0) {
                    $categories[$row['id_category']] = &$current;
                } else {
                    $buff[$row['id_parent']]['children'][$row['id_category']] = &$current;
                }
            }

            Cache::store($cache_id, $categories);
        }

        return Cache::retrieve($cache_id);
    }

    public function getAddLinkFieldsValues()
    {
        $links_label_edit = '';
        $labels_edit = '';
        $new_window_edit = '';

        if (Tools::isSubmit('updateczverticalmenu')) {
            $link = Cz_VerticalMenuTopLinks::getLinkLang(Tools::getValue('id_czverticalmenu'), (int)Shop::getContextShopID());

            foreach ($link['link'] as $key => $label) {
                $link['link'][$key] = Tools::htmlentitiesDecodeUTF8($label);
            }

            $links_label_edit = $link['link'];
            $labels_edit = $link['label'];
            $new_window_edit = $link['new_window'];
        }

        $fields_values = array(
            'new_window' => Tools::getValue('new_window', $new_window_edit),
            'id_czverticalmenu' => Tools::getValue('id_czverticalmenu'),
        );

        if (Tools::getValue('submitAddmodule')) {
            foreach (Language::getLanguages(false) as $lang) {
                $fields_values['label'][$lang['id_lang']] = '';
                $fields_values['link'][$lang['id_lang']] = '';
            }
        } else {
            foreach (Language::getLanguages(false) as $lang) {
                $fields_values['label'][$lang['id_lang']] = Tools::getValue('label_'.(int)$lang['id_lang'], isset($labels_edit[$lang['id_lang']]) ?
                    $labels_edit[$lang['id_lang']] : '');
                $fields_values['link'][$lang['id_lang']] = Tools::getValue('link_'.(int)$lang['id_lang'], isset($links_label_edit[$lang['id_lang']]) ?
                    $links_label_edit[$lang['id_lang']] : '');
            }
        }

        return $fields_values;
    }

    public function renderList()
    {
        $shops = Shop::getContextListShopID();
        $links = array();

        foreach ($shops as $shop_id) {
            $links = array_merge($links, Cz_VerticalMenuTopLinks::gets((int)$this->context->language->id, null, (int)$shop_id));
        }

        $fields_list = array(
            'id_czverticalmenu' => array(
                'title' => $this->l('Link ID'),
                'type' => 'text',
            ),
            'name' => array(
                'title' => $this->l('Shop name'),
                'type' => 'text',
            ),
            'label' => array(
                'title' => $this->l('Label'),
                'type' => 'text',
            ),
            'link' => array(
                'title' => $this->l('Link'),
                'type' => 'link',
            ),
            'new_window' => array(
                'title' => $this->l('New window'),
                'type' => 'bool',
                'align' => 'center',
                'active' => 'status',
            )
        );

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->identifier = 'id_czverticalmenu';
        $helper->table = 'czverticalmenu';
        $helper->actions = array('edit', 'delete');
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->title = $this->l('Link list');
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        return $helper->generateList($links, $fields_list);
    }

    private function getCurrentPageIdentifier()
    {
        $controllerName = Dispatcher::getInstance()->getController();
        if ($controllerName === 'cms' && ($id = Tools::getValue('id_cms'))) {
            return 'cms-page-' . $id;
        } else if ($controllerName === 'category' && ($id = Tools::getValue('id_category'))) {
            return 'category-' . $id;
        } else if ($controllerName === 'cms' && ($id = Tools::getValue('id_cms_category'))) {
            return 'cms-category-' . $id;
        } else if ($controllerName === 'manufacturer' && ($id = Tools::getValue('id_manufacturer'))) {
            return 'manufacturer-' . $id;
        } else if ($controllerName === 'manufacturer') {
            return 'manufacturers';
        } else if ($controllerName === 'supplier' && ($id = Tools::getValue('id_supplier'))) {
            return 'supplier-' . $id;
        } else if ($controllerName === 'supplier') {
            return 'suppliers';
        } else if ($controllerName === 'product' && ($id = Tools::getValue('id_product'))) {
            return 'product-' . $id;
        } else if ($controllerName === 'index') {
            return 'shop-' . $this->context->shop->id;
        } else {
            $scheme = 'http';
            if (array_key_exists('REQUEST_SCHEME', $_SERVER)) {
                $scheme = $_SERVER['REQUEST_SCHEME'];
            }
            return "$scheme://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        }
    }

    public function getWidgetVariables($hookName, array $configuration)
    {
        $id_lang = $this->context->language->id;
        $id_shop = $this->context->shop->id;

        $key = self::MENU_JSON_CACHE_KEY . '_' . $id_lang . '_' . $id_shop . '.json';
        $cacheDir = $this->getCacheDirectory();
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . $key;
        $menu = Tools::jsonDecode(@Tools::file_get_contents($cacheFile), true);
        if (!is_array($menu) || json_last_error() !== JSON_ERROR_NONE) {
            $menu = $this->makeMenu();
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir);
            }
            file_put_contents($cacheFile, Tools::jsonEncode($menu));
        }

        $page_identifier = $this->getCurrentPageIdentifier();
        // Mark the current page
        return $this->mapTree(function (array $node) use ($page_identifier) {
            $node['current'] = ($page_identifier === $node['page_identifier']);
            return $node;
        }, $menu);
    }
	
	public function hookdisplayHeader($params)
    {
        $this->context->controller->registerStylesheet('modules-czverticalmenu', 'modules/'.$this->name.'/views/css/cz_verticalmenu.css', ['media' => 'all', 'priority' => 150]);
    }
	
    public function renderWidget($hookName, array $configuration)
    {
        $this->smarty->assign([
            'verticalmenu' => $this->getWidgetVariables($hookName, $configuration)
        ]);

        return $this->fetch('module:cz_verticalmenu/views/templates/hook/cz_verticalmenu.tpl');
    }
	
	private function getNumberColsMenuItems()
	{
		$items_cols = Tools::getValue('MOD_CZVERTICALMENU_COLS');

		if (is_array($items_cols) && count($items_cols)) {
			return $items_cols;
		} else {
			$shops = Shop::getContextListShopID();
			$conf = null;

			if (count($shops) > 1) {
				foreach ($shops as $key => $shop_id) {
					$shop_group_id = Shop::getGroupFromShop($shop_id);
					$conf .= ($key > 1 ? ',' : '').((string)Configuration::get('MOD_CZVERTICALMENU_COLS', null, $shop_group_id, $shop_id));
				}
			} else {
				$shop_id = (int)$shops[0];
				$shop_group_id = Shop::getGroupFromShop($shop_id);
				$conf = Configuration::get('MOD_CZVERTICALMENU_COLS', null, $shop_group_id, $shop_id);
			}

			if (Tools::strlen($conf)) {
				return explode(',', $conf);
			} else {
				return array();
			}
		}
	}
	
	private function getNumberColsMenu()
	{
		$items_menu = $this->getMenuItems();
		$number_rols_menu = $this->getNumberColsMenuItems();
		$count_items_menu = count($items_menu);
		$icon_values = array();
		if ($number_rols_menu && $number_rols_menu != '')
			$icon_values = $number_rols_menu;
		else {
			if ($count_items_menu) {
				for ($i = 0; $i < $count_items_menu; $i++)
					$icon_values[] = '';
			}
		}

		$html = '<div id = "number_cols_megamenu" style="max-height: 186px; overflow-y: scroll; width: 300px;">';
		if (!empty($icon_values)) {
			$count_icon_values = count($icon_values);
			$icon_values = ($count_items_menu < $count_icon_values) ? array_slice($icon_values, 0, $count_items_menu) : $icon_values;
			for ($i = 0; $i < $count_icon_values; $i++) {
				$html .= '<select class="number_cols_czmegamenu" name="number_cols_'.$i.'" id="number_cols_'.$i.'">';
				for ($j = 1; $j <= 12; $j++) {
					$html .= '<option value="'.$j.'" ';
					if ($icon_values[$i] == $j)
						$html .= 'selected';
					$html .= '>'.$j.'</option>';
				}
				$html .= '</select>';
			}
		}
		$html .= '</div>';
		return $html;
	}
	
}
