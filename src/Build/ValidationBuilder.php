<?php
declare(strict_types=1);

namespace Componenta\Validation\App\Build;

use Componenta\App\Build\ApplicationBuilderInterface;
use Componenta\App\Build\BuildContext;
use Componenta\ClassFinder\ClassIteratorInterface;

final readonly class ValidationBuilder implements ApplicationBuilderInterface
{
    public const string ARTIFACT_ID = 'validation.rules';
    public const int SCHEMA_VERSION = 1;

    public function __construct(private ClassIteratorInterface $classes) {}

    public function build(BuildContext $build): void
    {
        $extractor = new ValidatorMapExtractor();
        $map = [];
        foreach ($this->classes as $info) {
            if ($info->isClass || $info->isEnum) {
                $class = $info->reflector;
                $entry = $extractor->extract($class);
                if ($entry !== null) {
                    $map[$class->getName()] = $entry;
                }
            }
        }
        ksort($map, SORT_STRING);
        $build->write(self::ARTIFACT_ID, $map, self::SCHEMA_VERSION);
    }
}
