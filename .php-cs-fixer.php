<?php declare(strict_types=1);

use IxDFCodingStandard\PhpCsFixer\Config;
use PhpCsFixer\Finder;

// tests/fixtures is analyser input, not project code: the fake Laravel/Nova declarations must keep
// mirroring the vendor source they stand in for, and the scenario files' line numbers are asserted
// on. Formatting them would change what is being tested, so they are left alone.
$finder = Finder::create()
    ->in(__DIR__)
    ->exclude(['tests/fixtures', 'vendor'])
    ->name('*.php')
    ->ignoreDotFiles(false)
    ->ignoreVCS(true)
    ->ignoreVCSIgnored(true);

return Config::create(__DIR__, finder: $finder);
