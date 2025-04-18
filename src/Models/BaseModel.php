<?php
/**
 * Created by Arkadia82.
 * User: Jordi Martínez
 * Email: jomasi1982@gmail.com
 */

namespace Arkadia\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Arkadia\Laravel\Traits\ExtendableModelTrait;

/**
 * Class BaseModel
 * @package Arkadia\Laravel\Models
 */
class BaseModel extends Model
{
    use ExtendableModelTrait;

    protected static $fields = [];

    protected static $messages = [];

    protected $errors = [];

    protected $validation = true;

    /**
     * Name column that will identifies items in data grid
     *
     * @var string
     */
    protected static $nameColumn = 'id';

    /**
     * Returns validator which validates model with $rules.
     * @param null $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function getValidator( $data = null )
    {
        return Validator::make( $data ?: $this->toArray(), $this->getRules(), static::$messages );
    }

    /**
     * Validates model. If validation fails false is returned and sets errors with messages in $error variable.
     * Errors can be received by getErrors() function
     * @param null $data
     * @return bool
     */
    public function validate( $data = null )
    {
        $v = $this->getValidator( $data );

        if( $v->fails() ) {
            $this->setErrors( $v->messages() );
            return false;
        }

        return true;
    }

    /**
     * Saves model to database. Before saving model is validated. If validation fails false is returned.
     * @param array $options
     * @return bool
     */
    public function save( array $options = [] )
    {
        if( $this->validation && !$this->validate() )
            return false;
        return parent::save( $options );
    }


    protected function setErrors( $errors )
    {
        $this->errors = $errors;
    }

    /**
     * Returns errors from validation.
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return array
     */
    public function getRules()
    {
        return [];
    }

    /**
     * @return bool
     */
    public function hasErrors()
    {
        return !empty( $this->errors );
    }

    /**
     * @return array
     */
    public function getCasts()
    {
        return $this->casts;
    }

    /**
     * @return array
     */
    public static function getFields()
    {
        return static::$fields;
    }

    /**
     * @return string
     */
    public static function getNameColumn()
    {
        return static::$nameColumn;
    }

    /**
     * @param bool $validation
     */
    public function setValidation( bool $validation ): void
    {
        $this->validation = $validation;
    }

    /**
     * @return string
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }
}
