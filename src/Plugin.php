<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/** Project-local Psalm plugin entry point. */
final class Plugin implements PluginEntryPointInterface
{
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        // Psalm's registerHooksFromClass() uses class_exists(..., autoload: false), so the handler
        // (and the shared helper it references) must already be loaded (mirrors how
        // psalm/plugin-laravel registers its own handlers).
        require_once __DIR__.'/Support/NovaQueryBuilderParamNarrower.php';
        require_once __DIR__.'/Support/StaticClassPropertyResolver.php';
        require_once __DIR__.'/NovaResourceQueryMethodHandler.php';
        require_once __DIR__.'/NovaMakeSignatureHandler.php';
        require_once __DIR__.'/NovaWhenReturnTypeHandler.php';
        require_once __DIR__.'/NovaSuppressHandler.php';

        $registration->registerHooksFromClass(NovaResourceQueryMethodHandler::class);
        $registration->registerHooksFromClass(NovaMakeSignatureHandler::class);
        $registration->registerHooksFromClass(NovaWhenReturnTypeHandler::class);
        $registration->registerHooksFromClass(NovaSuppressHandler::class);

        // Nova stubs that fix vendor signatures Psalm cannot resolve (and template Resource so a
        // resource can declare its model via @extends). Shipped with the package so it stays
        // self-contained.
        $stubsDir = __DIR__.'/../stubs/Nova';
        $registration->addStubFile($stubsDir.'/Actions/Action.phpstub');
        $registration->addStubFile($stubsDir.'/Fields/Field.phpstub');
        $registration->addStubFile($stubsDir.'/Element.phpstub');
        $registration->addStubFile($stubsDir.'/Metrics/PartitionResult.phpstub');
        $registration->addStubFile($stubsDir.'/Panel.phpstub');
        $registration->addStubFile($stubsDir.'/Resource.phpstub');
    }
}
