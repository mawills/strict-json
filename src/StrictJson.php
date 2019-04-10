<?php namespace Burba\StrictJson;

use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

class StrictJson
{
    private const SCALAR_TYPES = [
        'int',
        'bool',
        'string',
        'float',
    ];

    /** @var string[string[object]] */
    private $parameter_adapters;
    /** @var array */
    private $type_adapters;

    public function __construct(array $type_adapters = [], array $parameter_adapters = [])
    {
        $this->parameter_adapters = $parameter_adapters;
        $this->type_adapters = $type_adapters;
    }

    public static function builder(): StrictJsonBuilder
    {
        return new StrictJsonBuilder();
    }

    /**
     * @param string $json
     * @param string $class
     *
     * @return object
     * @throws JsonFormatException
     */
    public function map(string $json, string $class)
    {
        $parsed = json_decode($json, true);
        if (json_last_error()) {
            $err = json_last_error_msg();
            throw new JsonFormatException(
                "Unable to parse invalid JSON ($err): $json"
            );
        }

        return $this->mapParsed($parsed, $class);
    }

    /** @noinspection PhpDocMissingThrowsInspection */
    /**
     * @param array|string|int|bool $parsed_json
     * @param string $target_type
     *
     * @return mixed
     * @throws JsonFormatException
     */
    public function mapParsed($parsed_json, string $target_type)
    {
        $target_type = $this->normalize($target_type);

        $adapter = $this->type_adapters[$target_type] ?? null;
        if ($adapter !== null) {
            return $this->mapWithAdapter($adapter, $parsed_json);
        }

        if ($this->isScalarTypeName($target_type)) {
            return $this->mapScalar($parsed_json, $target_type);
        }

        if (class_exists($target_type)) {
            return $this->mapClass($parsed_json, $target_type);
        }

        throw new InvalidConfigurationException("Target type \"$target_type\" is not a scalar type or valid class and has no registered type adapter");
    }

    /**
     * The "Reflection" apis and gettype return primitive names slightly differently, this forces them all to be the
     * same so you can compare them
     *
     * @param string $primitive_type_name
     *
     * @return string
     */
    private function normalize(string $primitive_type_name)
    {
        switch ($primitive_type_name) {
            case 'int':
            case 'integer':
                return 'int';
            case 'double':
            case 'float':
                return 'float';
            case 'bool':
            case 'boolean':
                return 'bool';
            default:
                return $primitive_type_name;
        }
    }

    /**
     * @param $adapter
     * @param $value
     * @return mixed
     * @throws JsonFormatException
     */
    private function mapWithAdapter($adapter, $value)
    {
        try {
            $adapter_class = new ReflectionClass($adapter);
        } catch (ReflectionException $e) {
            $adapter_type_name = gettype($adapter);
            throw new InvalidConfigurationException(
                "Adapter of type \"$adapter_type_name\" is not a valid class",
                $e
            );
        }

        try {
            $adapter_method = $adapter_class->getMethod('fromJson');
        } catch (ReflectionException $e) {
            throw new InvalidConfigurationException(
                "Adapter {$adapter_class->getName()} has no fromJson method",
                $e
            );
        }

        $adapter_method_params = $adapter_method->getParameters();
        if (count($adapter_method_params) !== 2) {
            throw new InvalidConfigurationException(
                "Adapter {$adapter_class->getName()}'s fromJson method has the wrong number of parameters, needs exactly 2'"
            );
        }

        $parsed_json_param = $adapter_method_params[1];
        // If the adapter specifies a required type, make sure the json value matches it. But if no type is specified,
        // allow the adapter to handle all values
        if ($parsed_json_param->getType() !== null) {
            $this->requireCompatibleTypes($adapter_method_params[1], $value);
        }
        try {
            return $adapter_method->invoke($adapter, $this, $value);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (JsonFormatException $e) {
            throw new JsonFormatException("Adapter {$adapter_class->getName()} was unable to adapt the parsed json", $e);
        } catch (Exception $e) {
            throw new InvalidConfigurationException("Adapter {$adapter_class->getName()} threw an exception", $e);
        }
    }

    /**
     * @param ReflectionParameter $parameter
     * @param $value
     * @throws JsonFormatException
     */
    private function requireCompatibleTypes(ReflectionParameter $parameter, $value): void
    {
        if (!$this->typesAreCompatible($parameter, $value)) {
            $json_type = gettype($value);
            throw new JsonFormatException(
                "{$parameter->getName()} has type {$parameter->getType()} in class but has type $json_type in json"
            );
        }
    }

    private function typesAreCompatible(ReflectionParameter $parameter, $json_value): bool
    {
        $parameter_type = $parameter->getType();
        if ($parameter_type === null) {
            // With the current code it's impossible to trigger this state with the public api, because all callers
            // happen to verify that the parameter has a type before calling this
            // @codeCoverageIgnoreStart
            throw new InvalidConfigurationException("Method {$parameter->getDeclaringClass()->getName()}::{$parameter->getDeclaringFunction()->getName()} parameter {$parameter->getName()} does not have a type");
            // @codeCoverageIgnoreEnd
        }

        $parameter_type_name = $this->normalize($parameter->getType()->getName());
        $json_type_name = $this->normalize(gettype($json_value));

        if ($parameter_type_name === $json_type_name) {
            return true;
        } elseif ($parameter->allowsNull() && $json_type_name === 'NULL') {
            return true;
        } elseif (is_object($json_value) && $parameter->getType()->getName() == get_class($json_value)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param string $type_name
     * @return bool
     */
    private function isScalarTypeName(string $type_name): bool
    {
        return in_array($this->normalize($type_name), self::SCALAR_TYPES);
    }

    /**
     * @param $parsed_json
     * @param string $target_type
     * @return mixed
     * @throws JsonFormatException
     */
    private function mapScalar($parsed_json, string $target_type)
    {
        $json_type = $this->normalize(gettype($parsed_json));
        if ($json_type === $target_type) {
            return $parsed_json;
        } else {
            throw new JsonFormatException("Value is of type $json_type, expected type $target_type");
        }
    }

    /** @noinspection PhpDocMissingThrowsInspection */
    /**
     * @param $parsed_json
     * @param string $classname
     * @return object
     * @throws JsonFormatException
     */
    private function mapClass($parsed_json, string $classname): object
    {
        try {
            $class = new ReflectionClass($classname);
            // @codeCoverageIgnoreStart
        } catch (ReflectionException $e) {
            // Right now it's not possible to trigger this exception from the public API, because the only method that
            // calls this checks if $classname is a class first
            throw new InvalidConfigurationException("Type $classname is not a valid class", $e);
            // @codeCoverageIgnoreEnd
        }

        $constructor = $class->getConstructor();
        $parameters = $constructor->getParameters();
        $constructor_args = [];
        foreach ($parameters as $parameter) {
            $parameter_name = $parameter->getName();
            $parameter_type = $parameter->getType();
            if ($parameter_type === null) {
                throw new InvalidConfigurationException("$classname::__construct has parameter named $parameter_name with no specified type");
            }

            if (isset($parsed_json[$parameter_name])) {
                $value = $parsed_json[$parameter_name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                // Guaranteed not to throw because we checked in the if condition
                /** @noinspection PhpUnhandledExceptionInspection */
                $value = $parameter->getDefaultValue();
            } else {
                throw new JsonFormatException("$classname::__construct has non-optional parameter named $parameter_name that does not exist in JSON");
            }

            $adapter = $this->parameter_adapters[$classname][$parameter_name] ?? null;
            if ($adapter !== null) {
                try {
                    $value = $this->mapWithAdapter($adapter, $value);
                } catch (InvalidConfigurationException $e) {
                    if (is_object($adapter)) {
                        $adapter_description = get_class($adapter);
                    } else {
                        $adapter_description = gettype($adapter);
                    }
                    throw new InvalidConfigurationException("Unable to apply adapter for $classname::$parameter_name ($adapter_description)", $e);
                }
            }

            if (!$parameter->getType()->isBuiltin() && $value !== null) {
                $value = $this->mapParsed($value, $parameter->getType()->getName());
            }

            $this->requireCompatibleTypes($parameter, $value);
            $constructor_args[] = $value;
        }

        return $class->newInstanceArgs($constructor_args);
    }
}
