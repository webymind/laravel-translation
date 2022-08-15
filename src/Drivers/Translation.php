<?php

namespace JoeDixon\Translation\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use JoeDixon\Translation\Events\TranslationAdded;
use JoeDixon\Translation\Scanner;

abstract class Translation
{
    protected Scanner $scanner;

    protected string $sourceLanguage;

    abstract public function map(): Collection|string|null;

    /**
     * Get all languages.
     */
    abstract public function allLanguages(): Collection;

    /**
     * Determine whether the given language exists.
     */
    abstract public function languageExists(string $language): bool;

    /**
     * Add a new language.
     */
    abstract public function addLanguage(string $language, ?string $name = null): void;

    /**
     * Get all translations.
     */
    public function allTranslations(): Collection
    {
        return $this->allLanguages()->mapWithKeys(
            fn ($name, $language) => [$language => $this->allTranslationsFor($language)]
        );
    }

    /**
     * Get all translations for a given language.
     */
    public function allTranslationsFor(string $language): CombinedTranslations
    {
        return new CombinedTranslations(
            $this->allStringKeyTranslationsFor($language),
            $this->allShortKeyTranslationsFor($language),
        );
    }

    /**
     * Get short key translations for a given language.
     */
    abstract public function allShortKeyTranslationsFor(string $language): Collection;

    /**
     * Get all the short key groups for a given language.
     */
    abstract public function allShortKeyGroupsFor(string $language): Collection;

    /**
     * Add a short key translation.
     */
    abstract public function addShortKeyTranslation(string $language, string $group, string $key, string $value = ''): void;

    /**
     * Get string key translations for a given language.
     */
    abstract public function allStringKeyTranslationsFor(string $language): Collection;

    /**
     * Add a string key translation.
     */
    abstract public function addStringKeyTranslation(string $language, string $vendor, string $key, string $value = ''): void;

    /**
     * Find all of the translations in the app without translation for a given language.
     */
    public function findMissingTranslations(string $language): Collection
    {
        return $this->scanner->findTranslations()
            ->map(function ($groups, $type) use ($language) {
                return $groups->map(function ($translations, $group) use ($language, $type) {
                    $all = $this->allTranslationsFor($language)->get($type)->get($group);

                    return $translations->diffKeys($all);
                })->filter(function ($translations) {
                    return $translations->isNotEmpty();
                });
            });
    }

    /**
     * Save all of the translations in the app without translation for a given language.
     */
    public function saveMissingTranslations(?string $language = null): void
    {
        $languages = $language ? [$language => $language] : $this->allLanguages();

        foreach ($languages as $language => $name) {
            $missingTranslations = $this->findMissingTranslations($language);

            foreach ($missingTranslations as $type => $groups) {
                foreach ($groups as $group => $translations) {
                    foreach ($translations as $key => $value) {
                        if (Str::contains($group, 'single')) {
                            $this->addStringKeyTranslation($language, $group, $key);
                        } else {
                            $this->addShortKeyTranslation($language, $group, $key);
                        }
                    }
                }
            }
        }
    }

    /**
     * Get all translations for a given language merged with the source language.
     */
    public function getSourceLanguageTranslationsWith(string $language): CombinedTranslations
    {
        $sourceTranslations = $this->allTranslationsFor($this->sourceLanguage);
        $languageTranslations = $this->allTranslationsFor($language);

        return $sourceTranslations->map(function ($groups, $type) use ($language, $languageTranslations) {
            return $groups->map(function ($translations, $group) use ($type, $language, $languageTranslations) {
                $translations = $translations->toArray();
                array_walk($translations, function (&$value, $key) use ($type, $group, $language, $languageTranslations) {
                    $value = [
                        $this->sourceLanguage => $value,
                        $language => $languageTranslations->get($type, collect())->get($group, collect())->get($key),
                    ];
                });

                return $translations;
            });
        });
    }

    /**
     * Filter all keys and translations for a given language and string.
     */
    public function filterTranslationsFor(string $language, ?string $filter): Collection
    {
        $allTranslations = $this->getSourceLanguageTranslationsWith(($language));
        if (! $filter) {
            return $allTranslations;
        }

        return $allTranslations->map(function ($groups, $type) use ($language, $filter) {
            return $groups->map(function ($keys, $group) use ($language, $filter) {
                return collect($keys)->filter(function ($translations, $key) use ($group, $language, $filter) {
                    return strs_contain([$group, $key, $translations[$language], $translations[$this->sourceLanguage]], $filter);
                });
            })->filter(function ($keys) {
                return $keys->isNotEmpty();
            });
        });
    }

    public function add(Request $request, string $language, bool $isGroupTranslation): void
    {
        $namespace = $request->has('namespace') && $request->get('namespace') ? "{$request->get('namespace')}::" : '';
        $group = $namespace.$request->get('group');
        $key = $request->get('key');
        $value = $request->get('value') ?: '';

        if ($isGroupTranslation) {
            $this->addShortKeyTranslation($language, $group, $key, $value);
        } else {
            $this->addStringKeyTranslation($language, 'string', $key, $value);
        }

        Event::dispatch(new TranslationAdded($language, $group ?: 'string', $key, $value));
    }

    abstract public function allTranslationsFromMap(string $key): Collection;

    public function normalizedKeys(): CombinedTranslations
    {
        return $this->allTranslations()->reduce(function ($carry, $item) {
            $carry->shortKeyTranslations = $carry->shortKeyTranslations->mergeRecursive($item->shortKeyTranslations);
            $carry->stringKeyTranslations = $carry->stringKeyTranslations->mergeRecursive($item->stringKeyTranslations);

            return $carry;
        }, CombinedTranslations::make())->emptyValues();
    }
}
