<?php declare(strict_types=1);

use IxDFCodingStandard\PhpCsFixer\Config;
use PhpCsFixer\Finder;

// tests/fixtures is analyser input, not project code: the fake Laravel/Nova declarations must keep
// mirroring the vendor source they stand in for, and the scenario files' line numbers are asserted
// on. Formatting them would change what is being tested, so they are left alone.
//
// `.phpstub` files are plain PHP under a different extension (Psalm requires the extension to treat
// them as stub declarations rather than project code), so they are matched alongside `*.php`.
$finder = Finder::create()
    ->in(__DIR__)
    ->exclude(['tests/fixtures', 'vendor'])
    ->name(['*.php', '*.phpstub'])
    ->ignoreDotFiles(false)
    ->ignoreVCS(true)
    ->ignoreVCSIgnored(true);

// `final_public_method_for_abstract_class` only sees this repo's own files, so it cannot know that
// laravel/nova itself overrides several of the abstract methods our stubs describe (e.g. `Textarea`
// overrides `FieldElement::showOnIndex()`). Marking a stubbed vendor method final would make Psalm
// report a real Nova app's legitimate override as invalid, i.e. reintroduce the exact kind of false
// positive this plugin exists to remove. No abstract classes currently live in src/, so disabling it
// project-wide changes nothing there today.
return Config::create(__DIR__, ruleOverrides: ['final_public_method_for_abstract_class' => false], finder: $finder);
