<?php
namespace Module\UpdateStock\Twig;

use Module\UpdateStock\Service\TranslationService;

if (!class_exists('Twig_Extension') && class_exists('Twig\Extension\AbstractExtension')) {
    class_alias('Twig\Extension\AbstractExtension', 'Twig_Extension');
}
if (!class_exists('Twig_SimpleFilter') && class_exists('Twig\TwigFilter')) {
    class_alias('Twig\TwigFilter', 'Twig_SimpleFilter');
}

class ModuleTranslationExtension extends \Twig_Extension
{
    private $translationService;

    public function __construct(TranslationService $translationService)
    {
        $this->translationService = $translationService;
    }

    public function getFilters()
    {
        return [
            new \Twig_SimpleFilter('modtrans', [$this, 'modtrans']),
        ];
    }

    public function modtrans($string, $params = [])
    {
        return $this->translationService->translate($string, $params);
    }

    public function getName()
    {
        return 'module_translation_extension';
    }
}
