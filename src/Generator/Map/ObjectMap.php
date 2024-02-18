<?php

namespace Battis\OpenAPI\Generator\Map;

use Battis\DataUtilities\Path;
use Battis\OpenAPI\Client\BaseObject;
use Battis\OpenAPI\Generator\Exceptions\ConfigurationException;
use Battis\OpenAPI\Generator\Exceptions\GeneratorException;
use Battis\OpenAPI\Generator\Exceptions\SchemaException;
use Battis\OpenAPI\Generator\Sanitize;
use Battis\OpenAPI\Generator\TypeMap;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;

/**
 * @api
 */
class ObjectMap extends BaseMap
{
    public function simpleNamespace(): string
    {
        return "Objects";
    }

    /**
     * @var array<string, ObjectClass> $classes
     */
    private $classes = [];

    /**
     * @param array{
     *     spec: \cebe\openapi\spec\OpenApi,
     *     basePath: string,
     *     baseNamespace: string,
     *     baseType?: string,
     *   } $config
     */
    public function __construct($config)
    {
        $config['baseType'] ??= BaseObject::class;
        parent::__construct($config);
        assert(is_a($this->baseType, BaseObject::class, true), new ConfigurationException("\$baseType must be instance of " . BaseObject::class));
        $this->basePath = Path::join($this->basePath, $this->simpleNamespace());
        $this->baseNamespace = Path::join("\\", [$this->baseNamespace, $this->simpleNamespace()]);
    }

    public function generate(): void
    {
        $map = TypeMap::getInstance();
        $sanitize = Sanitize::getInstance();

        assert(
            $this->spec->components && $this->spec->components->schemas,
            new SchemaException("#/components/schemas not defined")
        );

        foreach (array_keys($this->spec->components->schemas) as $name) {
            $ref = "#/components/schemas/$name";
            $nameParts = array_map(fn(string $p) => $sanitize->clean($p), explode('.', $name));
            $map->registerSchema($ref, Path::join("\\", [$this->baseNamespace, $nameParts]));
            $this->log("Mapped $ref => " . $map->getTypeFromSchema($ref));
        }

        foreach ($this->spec->components->schemas as $name => $schema) {
            if ($schema instanceof Reference) {
                $schema = $schema->resolve();
                /** @var Schema $schema (because we just resolved it)*/
            }
            $class = ObjectClass::fromSchema("#/components/schemas/$name", $schema, $this);
            $this->log("Generated " . $class->getType());
            $map->registerClass($class);
            $this->classes[$name] = $class;
        }
    }

    public function writeFiles()
    {
        foreach($this->classes as $class) {
            $filePath = Path::join($this->basePath, $class->getPath(), $class->getName(). ".php");
            @mkdir(dirname($filePath), 0744, true);
            assert(!file_exists($filePath), new GeneratorException("$filePath exists and cannot be overwritten"));
            file_put_contents($filePath, $class);
            $this->log("Wrote " . $class->getType() . " to $filePath");
        }
        shell_exec(Path::join(getcwd(), '/vendor/bin/php-cs-fixer') . " fix " . $this->basePath);
    }
}
