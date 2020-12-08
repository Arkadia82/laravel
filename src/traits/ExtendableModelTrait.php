<?php
/**
 * Created by Arkadia82.
 * User: Jordi Martínez
 * Email: jomasi1982@gmail.com
 */

namespace Arkadia\Laravel\Traits;

use BadMethodCallException;
use Closure;
use Illuminate\Support\Str;

trait ExtendableModelTrait
{
    protected static $externalMethods = [];

    protected static $externalStaticMethods = [];

    /**
     * @param $name
     * @param Closure $closure
     */
    public static function addMethod( $name, Closure $closure ){
        static::$externalMethods[$name] = $closure;
    }

    /**
     * @param $name
     * @param Closure $closure
     */
    public static function addStaticMethod( $name, Closure $closure ){
        static::$externalStaticMethods[$name] = $closure;
    }

    /**
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (isset(static::$externalMethods[$method])) {
            $closure = Closure::bind(static::$externalMethods[$method], $this, static::class);
            return call_user_func_array($closure, $parameters);
        }

        if (method_exists($this, '__callAfter')) {
            return $this->__callAfter($method, $parameters);
        }

        if (method_exists(parent::class, '__call')) {
            return parent::__call($method, $parameters);
        }
        throw new BadMethodCallException('Method ' . static::class . '::' . $method . '() not found');
    }

    /**
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        if (isset(static::$externalStaticMethods[$method])) {
            $closure = Closure::bind(static::$externalStaticMethods[$method], null, static::class);
            return call_user_func_array($closure, $parameters);
        }

        if (method_exists(static::class, '__callStaticAfter')) {
            return static::__callStaticAfter($method, $parameters);
        }

        if (method_exists(parent::class, '__callStatic')) {
            return parent::__callStatic($method, $parameters);
        }
        throw new BadMethodCallException('Method ' . static::class . '::' . $method . '() not found');
    }

    /**
     * Override original behavior.
     *
     * @see \Illuminate\Database\Eloquent\Model::hasGetMutator()
     * @param $key
     * @return bool
     */
    public function hasGetMutator($key)
    {
        if (isset(static::$externalMethods['get' . Str::studly($key) . 'Attribute'])) {
            return true;
        }
        // Keep parent functionality.
        return parent::hasGetMutator($key);
    }
    /**
     * Override original behavior.
     *
     * @see \Illuminate\Database\Eloquent\Model::hasSetMutator()
     * @param $key
     * @return bool
     */
    public function hasSetMutator($key)
    {
        if (isset(static::$externalMethods['set' . Str::studly($key) . 'Attribute'])) {
            return true;
        }
        // Keep parent functionality.
        return parent::hasSetMutator($key);
    }
    /**
     * Override original behavior.
     *
     * @see \Illuminate\Database\Eloquent\Model::getRelationValue()
     * @param  string $key
     * @return mixed
     */
    public function getRelationValue($key)
    {
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }
        if (isset(static::$externalMethods[$key])) {
            return $this->getRelationshipFromMethod($key);
        }
        if (method_exists($this, $key)) {
            return $this->getRelationshipFromMethod($key);
        }
    }
}