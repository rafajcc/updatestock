<?php
/**
 * Update Stock Module for PrestaShop
 *
 * @author    Vera Technology
 * @copyright 2026 Vera Technology
 * @license   MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UpdateStock extends Module
{
    public function __construct()
    {
        $this->name = 'updatestock';
        $this->tab = 'administration';
        $this->version = '1.0.9';
        $this->author = 'Vera Technology';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Update Stock from Text Files');
        $this->description = $this->l('Update product physical quantity by uploading text files with EAN codes.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        return parent::install() &&
            $this->installTab();
    }

    public function uninstall()
    {
        return $this->uninstallTab() &&
            parent::uninstall();
    }

    /**
     * Install a new Tab in the Back Office
     */
    protected function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'UpdateStockController';
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Stock Update Inventory';
        }
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->module = $this->name;

        return $tab->add();
    }

    /**
     * Uninstall the Tab
     */
    protected function uninstallTab()
    {
        $id_tab = (int) Tab::getIdFromClassName('UpdateStockController');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return $tab->delete();
        }
        return true;
    }
}
