<?php declare(strict_types=1);

namespace Burba\StrictJson;

use Burba\StrictJson\Internal\ConstructorParameterFetcher;

class StrictJsonBuilder
{
    private $parameter_adapters = [];
    private $class_adapters = [];

    public function addParameterAdapter(string $class_name, string $parameter_name, Adapter $adapter): self
    {
        $this->parameter_adapters[$class_name][$parameter_name] = $adapter;
        return $this;
    }

    /**
     * @param string $class_name
     * @param string $parameter_name
     * @param string|Type $array_item_type
     * @return StrictJsonBuilder
     */
    public function addParameterArrayAdapter(string $class_name, string $parameter_name, $array_item_type): self
    {
        $type = $array_item_type instanceof Type ? $array_item_type : Type::ofClass($array_item_type);
        return $this->addParameterAdapter($class_name, $parameter_name, new ArrayAdapter($type));
    }

    public function addClassAdapter(string $class_name, Adapter $adapter): self
    {
        $this->class_adapters[$class_name] = $adapter;
        return $this;
    }

    public function build(): StrictJson
    {
        return new StrictJson(
            $this->class_adapters,
            $this->parameter_adapters,
            new ConstructorParameterFetcher($this->class_adapters, $this->parameter_adapters)
        );
    }
}
