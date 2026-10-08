<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use Tests\TestCase;

class LocalizationResourcesTest extends TestCase
{
    public function test_all_interface_languages_have_the_same_keys_and_placeholders(): void
    {
        $resources = [];
        foreach (['de', 'fr', 'it'] as $locale) {
            $resources[$locale] = json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach (glob(lang_path($locale.'/*.php')) as $path) {
                foreach (Arr::dot(require $path) as $key => $value) {
                    $resources[$locale][basename($path, '.php').'.'.$key] = $value;
                }
            }
            ksort($resources[$locale]);
        }
        foreach (['fr', 'it'] as $locale) {
            $this->assertSame(array_keys($resources['de']), array_keys($resources[$locale]), 'Missing '.$locale.' resources');
            foreach ($resources['de'] as $key => $value) {
                $this->assertNotSame('', $resources[$locale][$key], $locale.': '.$key);
                preg_match_all('/:[a-z_]+/i', $value, $expected);
                preg_match_all('/:[a-z_]+/i', $resources[$locale][$key], $actual);
                sort($expected[0]);
                sort($actual[0]);
                $this->assertSame($expected[0], $actual[0], $locale.': '.$key);
            }
        }
    }
}
