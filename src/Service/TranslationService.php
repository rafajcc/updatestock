<?php
namespace Module\UpdateStock\Service;

use Context;

class TranslationService
{
    private $loaded = [];

    public function translate($key, $params = [])
    {
        $locale = $this->getLocale();
        $translations = $this->load($locale);

        if (isset($translations[$key])) {
            $result = $translations[$key];
        } else {
            $result = $key;
        }

        if (!empty($params)) {
            foreach ($params as $param => $value) {
                $result = str_replace("%$param%", $value, $result);
            }
        }

        return $result;
    }

    private function load($locale)
    {
        if (!isset($this->loaded[$locale])) {
            $path = _PS_MODULE_DIR_ . 'updatestock/translations/' . $locale . '.php';
            if (file_exists($path)) {
                $this->loaded[$locale] = require $path;
            } else {
                $this->loaded[$locale] = [];
            }
        }
        return $this->loaded[$locale];
    }

    private function getLocale()
    {
        $context = Context::getContext();
        if ($context && $context->language) {
            return $context->language->iso_code;
        }
        return 'en';
    }
}
