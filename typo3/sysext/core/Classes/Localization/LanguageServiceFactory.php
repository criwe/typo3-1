<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Core\Localization;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

class LanguageServiceFactory
{
    protected Locales $locales;
    protected LocalizationFactory $localizationFactory;
    protected FrontendInterface $runtimeCache;

    public function __construct(
        Locales $locales,
        LocalizationFactory $localizationFactory,
        FrontendInterface $runtimeCache
    ) {
        $this->locales = $locales;
        $this->localizationFactory = $localizationFactory;
        $this->runtimeCache = $runtimeCache;
    }

    /**
     * Factory method to create a language service object.
     *
     * @param Locale|string $locale the locale
     */
    public function create(Locale|string $locale): LanguageService
    {
        $obj = new LanguageService($this->locales, $this->localizationFactory, $this->runtimeCache);
        $obj->init($locale instanceof Locale ? $locale : $this->locales->createLocale($locale));
        return $obj;
    }

    public function createFromUserPreferences(?AbstractUserAuthentication $user): LanguageService
    {
        if ($user && ($user->user['lang'] ?? false)) {
            return $this->create($this->locales->createLocale($user->user['lang']));
        }
        return $this->create('en');
    }

    public function createFromSiteLanguage(SiteLanguage $language): LanguageService
    {
        $languageService = $this->create($language->getLocale() ?: $language->getTypo3Language());
        // Always disable debugging for frontend
        $languageService->debugKey = false;
        return $languageService;
    }
}
