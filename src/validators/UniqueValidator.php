<?php
/**
 * Created by Arkadia82.
 * User: Jordi Martínez
 * Email: jomasi1982@gmail.com
 */

namespace Arkadia82\Laravel\Validators;

use Illuminate\Support\Facades\Validator;

class UniqueValidator
{
    public function validate( $attribute, $value, $parameters, $validator )
    {
        $table = $parameters[0];
        $field = !empty( $parameters[1] ) ? $parameters[1] : $attribute;

        if( request()->method() == "POST" ) {
            $rules = [ $attribute => "unique:$table,$field" ];
        } else {
            $v = $parameters[2];
            $pk = !empty( $parameters[3] ) ? $parameters[3] : 'id';
            $rules = [ $attribute => "unique:$table,$field,$v,$pk" ];
        }

        return Validator::make( [ $attribute => $value ], $rules, [] )->passes();
    }

}
