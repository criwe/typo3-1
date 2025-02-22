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

/**
 * A representation of
 *    language key (based on ISO 639-1 / ISO 639-2)
 *   - the optional four-letter script code that can follow the language code according to the Unicode ISO 15924 Registry (e.g. HANS in zh_HANS)
 *   - region / country (based on ISO 3166-1)
 * separated with a "-".
 *
 * This conforms to IETF - RFC 5646 (see https://datatracker.ietf.org/doc/rfc5646/) in a simplified form.
 */
class Locale implements \Stringable
{
    protected string $locale;
    protected string $languageCode;
    protected ?string $languageScript = null;
    protected ?string $countryCode = null;

    /**
     * List of language dependencies for an actual language. This setting is used for local variants of a language
     * that depend on their "main" language, like Brazilian Portuguese or Canadian French.
     *
     * @var array<int, string>
     */
    protected array $dependencies = [];

    public function __construct(
        string $locale = 'en',
        array $dependencies = []
    ) {
        $locale = $this->normalize($locale);
        if (str_contains($locale, '-')) {
            [$this->languageCode, $tail] = explode('-', $locale, 2);
            if (str_contains($tail, '-')) {
                [$this->languageScript, $this->countryCode] = explode('-', $tail);
            } elseif (strlen($tail) === 4) {
                $this->languageScript = $tail;
            } else {
                $this->countryCode = $tail ?: null;
            }
            $this->languageCode = strtolower($this->languageCode);
            $this->languageScript = $this->languageScript ? ucfirst(strtolower($this->languageScript)) : null;
            $this->countryCode = $this->countryCode ? strtoupper($this->countryCode) : null;
        } else {
            $this->languageCode = strtolower($locale);
        }

        $this->locale = $this->languageCode . ($this->languageScript ? '-' . $this->languageScript : '') . ($this->countryCode ? '-' . $this->countryCode : '');
        $this->dependencies = array_map(fn ($dep) => $this->normalize($dep), $dependencies);
    }

    public function getName(): string
    {
        return $this->locale;
    }

    /**
     * @return string
     */
    public function getLanguageCode(): string
    {
        return $this->languageCode;
    }

    public function getLanguageScriptCode(): ?string
    {
        return $this->languageScript;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    protected function normalize(string $locale): string
    {
        if ($locale === 'default') {
            return 'en';
        }
        if (str_contains($locale, '_')) {
            $locale = str_replace('_', '-', $locale);
        }

        if (str_contains($locale, '.')) {
            [$locale] = explode('.', $locale);
        }
        return $locale;
    }

    public function __toString(): string
    {
        return $this->locale;
    }
}
