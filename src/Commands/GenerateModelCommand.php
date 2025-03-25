<?php

namespace Arkadia\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Comando para generar modelos automáticamente desde la base de datos,
 * construyendo relaciones a partir de las foreign keys definidas.
 */
class GenerateModelCommand extends Command
{
    protected $signature = 'arkadia:models
                            {--name= : Model name. Si se pasa, sólo 1 tabla se requiere en --table }
                            {--table= : Nombre de la tabla o lista de tablas separadas por coma (,)}
                            {--base= : Base model name. Por defecto Arkadia\Laravel\Models\BaseModel }
                            {--prefix= : Prefijo de las tablas }
                            {--connection= : Conexión de base de datos. Si se omite, se usa la conexión por defecto del .env}
                            {--debug : Activa modo debug }
                            {--folder= : Carpeta en la que se crearán los modelos, por defecto app/Models}
                            {--namespace= : Namespace para los modelos, por defecto App\Models}
                            {--all : Genera para todas las tablas}';

    protected $description = 'Generate and extend models from the database';

    // Opciones y variables de configuración
    public $options = [];
    public $defaults;
    public $rules;
    public $properties;
    public $modelRelations;
    protected $currentTable;
    protected $prefixes = [];
    protected $modelPrimaryKey = 'id';

    // Variables para el contenido de los stubs
    public $fieldsFillable;
    public $fieldsHidden;
    public $fieldsCast;
    public $fieldsDate;
    public $columns;

    public function __construct()
    {
        parent::__construct();

        $this->options = [
            'name'       => '',
            'connection' => '',
            'table'      => '',
            'base'       => '',
            'folder'     => app_path(),
            'debug'      => false,
            'all'        => false,
            'prefix'     => '',
            'namespace'  => 'App\\Models',
        ];
    }

    public function handle()
    {
        $this->doComment('Starting Model Generate Command', true);
        $this->getOptions();

        $this->prefixes = $this->findPrefixes();

        $tables = [];
        $path = $this->options['folder'];
        $modelStub = file_get_contents($this->getStub());
        $basemodelStub = file_get_contents($this->getBaseStub());

        if (strlen($this->options['table']) <= 0 && $this->options['all'] == false) {
            $this->error('No --table specified or --all');
            return;
        }
        if (strlen($this->options['name']) > 0 && strpos($this->options['table'], ',') !== false) {
            $this->error('If name is set, pass only 1 table');
            return;
        }

        // Crear carpeta destino si no existe
        if ($this->options['folder'] != app_path() && !is_dir($this->options['folder'])) {
            mkdir($this->options['folder'], 0755, true);
        }

        // Obtener lista de tablas
        if ($this->options['all']) {
            $tables = $this->getAllTables();
        } else {
            $tables = explode(',', $this->options['table']);
        }

        // Procesar cada tabla
        foreach ($tables as $table) {
            $table = (string) $table; // forzamos string

            $this->currentTable = $table;
            $this->rules = null;
            $this->properties = null;
            $this->modelRelations = null;

            $stub     = $modelStub;
            $basestub = $basemodelStub;

            $tablename = $this->options['name'] !== ''
                ? $this->options['name']
                : $this->getTableWithoutPrefix($table);

            $classname = $this->options['name'] !== ''
                ? $this->options['name']
                : Str::studly(Str::singular($tablename));

            $fullPath = $this->options['folder'] . '/' . $classname . '.php';

            $this->doComment("Generating file: $classname.php", true);

            $model = [
                'table'    => $table,
                'fillable' => $this->getSchema($table),
                'guardable'=> [],
                'hidden'   => [],
                'casts'    => [],
            ];

            $columns = $this->describeTable($table);
            $this->findPrimaryKey($columns);
            $this->columns = collect($columns);

            $this->resetFields();

            // Reemplazos en el stub del modelo principal
            $stub = $this->replaceClassName($stub, $tablename);
            $stub = $this->replaceModuleInformation($stub, $model);
            $stub = $this->replaceConnection($stub, $this->options['connection']);
            $stub = $this->replaceLabel($stub, $columns);

            // Reemplazos en el stub base
            $basestub = $this->replaceClassName($basestub, $tablename);
            $basestub = $this->replaceBaseClassName($basestub, $this->options['base']);
            $basestub = $this->replaceModuleInformation($basestub, $model);
            $basestub = $this->replaceRulesAndProperties($basestub, $this->columns, $table);
            $basestub = $this->replaceConnection($basestub, $this->options['connection']);
            $basestub = $this->replacePrimaryKey($basestub, $columns);
            $basestub = $this->replaceUsTimestamps($basestub);
            $basestub = $this->replaceFields($basestub, $this->fieldsFillable);

            if (!file_exists($fullPath)) {
                $this->doComment('Writing base model: ' . $fullPath, true);
            } else {
                $this->doComment('Updating base model: ' . $fullPath, true);
            }

            file_put_contents($fullPath, $basestub);
        }

        $this->info('Complete');
    }

    /**
     * Obtiene el listado de columnas (schema) de la tabla.
     */
    public function getSchema($tableName)
    {
        $this->doComment('Retrieving table definition for: ' . $tableName);
        if (strlen($this->options['connection']) <= 0) {
            return Schema::getColumnListing($tableName);
        } else {
            return Schema::connection($this->options['connection'])->getColumnListing($tableName);
        }
    }

    /**
     * Retorna la descripción de cada columna (usando "describe table").
     */
    public function describeTable($tableName)
    {
        $this->doComment('Retrieving column information for: ' . $tableName);
        $query = 'describe ' . $tableName;
        if (strlen($this->options['connection']) <= 0) {
            return DB::select($query);
        } else {
            return DB::connection($this->options['connection'])->select($query);
        }
    }

    /**
     * Reemplaza la sección de primaryKey en el stub base, en caso
     * de que la PK no sea 'id' o sea de tipo distinto a integer.
     */
    public function replacePrimaryKey($stub, $columns)
    {
        $replacement = "";

        if ($this->modelPrimaryKey !== 'id') {
            $replacement .= "\n    protected \$primaryKey = '" . $this->modelPrimaryKey . "';\n";
        }

        foreach ($columns as $column) {
            if ($column->Key === 'PRI' && $this->modelPrimaryKey == $column->Field) {
                $type = $this->getPhpType($column->Type);
                if ($type != 'integer') {
                    $replacement .= "\n    protected \$keyType = '$type';\n";
                    $replacement .= "\n    public \$incrementing = false;\n";
                }
            }
        }

        return str_replace('{{primarykey}}', $replacement, $stub);
    }

    public function replaceClassName($stub, $tableName)
    {
        return str_replace('{{class}}', Str::studly(Str::singular($tableName)), $stub);
    }

    public function replaceBaseClassName($stub, $baseclass)
    {
        return str_replace('{{baseclass}}', Str::studly($baseclass), $stub);
    }

    /**
     * Procesa variables del stub para tabla, fillable, hidden, casts, dates, etc.
     */
    public function replaceModuleInformation($stub, $modelInformation)
    {
        $stub = str_replace('{{table}}', $modelInformation['table'], $stub);

        // Reconstruimos arrays con un formato multilinea
        $fillable = [];
        $hidden   = [];
        $casts    = [];
        $dates    = [];

        foreach ($modelInformation['fillable'] as $field) {
            // ignoramos 'id' en fillable
            if ($field !== 'id') {
                $fillable[] = $field;
            }
        }

        // Usamos la columna Type para identificar boolean, date, etc.
        foreach ($modelInformation['fillable'] as $field) {
            $columnsFiltered = $this->columns->where('Field', $field);
            if ($columnsFiltered && $columnsFiltered->count() > 0) {
                $type = strtolower($columnsFiltered->first()->Type);
                switch ($type) {
                    case 'timestamp':
                    case 'datetime':
                    case 'date':
                        $dates[] = $field;
                        break;
                    case 'tinyint(1)':
                        $casts[$field] = 'boolean';
                        break;
                }
            }
        }

        // Guardamos en this->fieldsFillable (que usaremos luego en replaceFields)
        $this->fieldsFillable = $this->buildMultilineArray($fillable);

        // Para $hidden no estamos usando nada en este ejemplo, quedará vacío
        $this->fieldsHidden = $this->buildMultilineArray($hidden);

        // Casts -> generamos formato `'campo' => 'tipo'`
        $castsArray = [];
        foreach ($casts as $k => $v) {
            // p.ej. "campo => boolean"
            $castsArray[] = "$k => $v";
        }
        $this->fieldsCast = $this->buildMultilineArray($castsArray);

        // Fechas
        $this->fieldsDate = $this->buildMultilineArray($dates);

        // Hacemos replace en el stub
        $stub = str_replace('{{fillable}}', $this->fieldsFillable, $stub);
        $stub = str_replace('{{hidden}}', $this->fieldsHidden, $stub);
        $stub = str_replace('{{casts}}', $this->fieldsCast, $stub);
        $stub = str_replace('{{dates}}', $this->fieldsDate, $stub);
        $stub = str_replace('{{modelnamespace}}', $this->options['namespace'], $stub);

        return $stub;
    }

    public function replaceConnection($stub, $database)
    {
        $replacementString = '/**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = \'' . $database . '\';';

        if (strlen($database) <= 0) {
            $stub = str_replace('{{connection}}', '', $stub);
        } else {
            $stub = str_replace('{{connection}}', $replacementString, $stub);
        }

        return $stub;
    }

    public function replaceLabel($stub, $columns)
    {
        $columnsArr = array_map(function ($c) {
            return $c->Field;
        }, $columns);

        // Primero intentamos poner un nameColumn
        $didSetNameColumn = false;
        foreach (['title', 'name', 'key'] as $p) {
            if (in_array($p, $columnsArr)) {
                $stub = str_replace('{{namecolumn}}', "protected static \$nameColumn = '$p';", $stub);
                $didSetNameColumn = true;
                break;
            }
        }
        if (!$didSetNameColumn) {
            $stub = str_replace('{{namecolumn}}', '', $stub);
        }

        // El label
        $priorities = ['title', 'name', 'key', 'id'];
        $first = null;
        foreach ($priorities as $p) {
            if (in_array($p, $columnsArr)) {
                if ($first === null) {
                    $first = $p;
                } else {
                    return str_replace('{{label}}', "\$this->$first ?: \$this->$p", $stub);
                }
            }
        }
        if ($first !== null) {
            return str_replace('{{label}}', "\$this->$first", $stub);
        }
        return str_replace('{{label}}', '', $stub);
    }

    /**
     * En lugar de "adivinar" la fk por convención, consultamos en
     * information_schema para obtener belongsTo y hasMany reales.
     */
    public function replaceRulesAndProperties($stub, $columns, $tableName)
    {
        $this->rules        = '';
        $this->defaults     = '';
        $this->properties   = '';
        $this->modelRelations = '';

        // Arrays para armar multiline
        $rulesArray    = [];
        $defaultsArray = [];

        // 1) Construir las reglas y defaults de columnas
        foreach ($columns as $column) {
            $field = $column->Field;
            $type  = $this->getPhpType($column->Type);

            // Defaults
            $defaultValue = $column->Default;
            if ($type === 'string' && $defaultValue !== null) {
                $defaultValue = "'{$defaultValue}'";
            } elseif ($defaultValue === null) {
                $defaultValue = 'null';
            }
            $defaultsArray[] = "'$field' => " . $defaultValue;

            // Reglas
            $rulesArray[$field] = $this->getRules($column);

            // Propiedades
            $this->properties .= "\n * @property " . $type . " " . $field;
        }

        // 2) Crear las relaciones a partir de las foreign keys definidas
        $relations = $this->buildDbRelations($tableName);

        // Convertir defaults y reglas a multilinea
        $this->defaults = $this->buildAssocArrayMultiline($defaultsArray, 2);
        $this->rules    = $this->buildRulesArrayMultiline($rulesArray);

        // Añadir las relaciones detectadas al docBlock y concatenar
        $this->properties   .= $relations['docBlock'];
        $this->modelRelations = $relations['methods'];

        // Hacer reemplazos finales en el stub
        $stub = str_replace('{{defaults}}',   $this->defaults,     $stub);
        $stub = str_replace('{{rules}}',      $this->rules,        $stub);
        $stub = str_replace('{{properties}}', $this->properties,   $stub);
        $stub = str_replace('{{relations}}',  $this->modelRelations, $stub);

        return $stub;
    }

    /**
     * Construye las relaciones belongsTo y hasMany leyendo las foreign keys
     * de information_schema.KEY_COLUMN_USAGE.
     */
    protected function buildDbRelations($tableName)
    {
        $docBlock = '';
        $methods  = '';

        // 1) BelongsTo: la tabla actual (tableName) es la hija que referencia otra tabla
        $belongsToFks = $this->getBelongsToFks($tableName);
        foreach ($belongsToFks as $fk) {
            $fkColumn    = $fk->COLUMN_NAME;            // user_id
            $refTable    = $this->getTableWithoutPrefix($fk->REFERENCED_TABLE_NAME);  // users -> sin prefijo
            $refColumn   = $fk->REFERENCED_COLUMN_NAME; // id
            $modelName   = Str::studly(Str::singular($refTable)); // "User"
            $relatedModel= $this->options['namespace']."\\".$modelName;
            // Método => user() por ejemplo
            $methodName  = Str::camel(Str::singular($refTable));

            // docBlock
            $docBlock .= "\n * @property \\".$relatedModel." ".$methodName;

            // Generar belongsTo
            $methods .= "\n    public function {$methodName}()\n    {\n";
            $methods .= "        return \$this->belongsTo( \\$relatedModel::class, '$fkColumn', '$refColumn');\n";
            $methods .= "    }\n";
        }

        // 2) HasMany: la tabla actual es referenciada por otras. ("Padre" de varios "hijos")
        $hasManyFks = $this->getHasManyFks($tableName);
        foreach ($hasManyFks as $fk) {
            $childTable  = $fk->TABLE_NAME;   // "posts"
            $childColumn = $fk->COLUMN_NAME;  // "user_id"
            $childTableNoPrefix = $this->getTableWithoutPrefix($childTable);
            $childModelName     = Str::studly(Str::singular($childTableNoPrefix));
            $childModel         = $this->options['namespace']."\\".$childModelName;
            // método => posts()
            $methodName         = Str::camel(Str::plural($childTableNoPrefix));

            $docBlock .= "\n * @property \\".$childModel."[] ".$methodName;

            $methods .= "\n    public function {$methodName}()\n    {\n";
            $methods .= "        return \$this->hasMany( \\$childModel::class, '$childColumn', '".$this->modelPrimaryKey."');\n";
            $methods .= "    }\n";
        }

        return [
            'docBlock' => $docBlock,
            'methods'  => $methods
        ];
    }

    /**
     * Retorna las filas de KEY_COLUMN_USAGE donde la tabla = $tableName
     * y REFERENCED_TABLE_NAME != null => indica belongsTo.
     */
    protected function getBelongsToFks($tableName)
    {
        $dbName = $this->getDatabaseName();
        $sql = "
            SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND TABLE_NAME = ?
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ";
        if (strlen($this->options['connection']) > 0) {
            return DB::connection($this->options['connection'])->select($sql, [$dbName, $tableName]);
        }
        return DB::select($sql, [$dbName, $tableName]);
    }

    /**
     * Retorna las filas de KEY_COLUMN_USAGE donde la tabla referenciada = $tableName
     * => indica que la actual $tableName es padre y la otra tabla es hija (hasMany).
     */
    protected function getHasManyFks($tableName)
    {
        $dbName = $this->getDatabaseName();
        $sql = "
            SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
              AND REFERENCED_TABLE_NAME = ?
              AND REFERENCED_COLUMN_NAME = 'id'
        ";
        if (strlen($this->options['connection']) > 0) {
            return DB::connection($this->options['connection'])->select($sql, [$dbName, $tableName]);
        }
        return DB::select($sql, [$dbName, $tableName]);
    }

    /**
     * Detectar el nombre actual de la DB para queries en information_schema.
     */
    protected function getDatabaseName()
    {
        if (strlen($this->options['connection']) > 0) {
            return config('database.connections.'.$this->options['connection'].'.database')
                ?: DB::connection($this->options['connection'])->getDatabaseName();
        }
        // sin connection => default
        return config('database.connections.mysql.database')
            ?: DB::getDatabaseName();
    }

    /**
     * Identifica la primary key para el modelo (por defecto 'id').
     */
    public function findPrimaryKey($columns): void
    {
        foreach ($columns as $column) {
            if ($column->Key == 'PRI') {
                $this->modelPrimaryKey = $column->Field;
                break;
            }
        }
    }

    public function getStub()
    {
        $this->doComment('Loading model stub');
        return __DIR__ . '/../Templates/model.jig';
    }

    public function getBaseStub()
    {
        $this->doComment('Loading base model stub');
        return __DIR__ . '/../Templates/basemodel.jig';
    }

    public function getOptions()
    {
        $this->options['name']       = $this->option('name') ?: '';
        $this->options['base']       = $this->option('base') ?: '\\Arkadia\\Laravel\\Models\\BaseModel';
        $this->options['debug']      = $this->option('debug') ? true : false;
        $this->options['connection'] = $this->option('connection') ?: '';
        $this->options['folder']     = $this->option('folder')
            ? base_path($this->option('folder'))
            : app_path() . DIRECTORY_SEPARATOR . "Models";
        $this->options['folder']     = rtrim($this->options['folder'], '/');
        $this->options['namespace']  = $this->option('namespace')
            ? str_replace('app', 'App', $this->option('namespace'))
            : 'App\\Models';
        $this->options['namespace']  = rtrim($this->options['namespace'], '/');
        $this->options['namespace']  = str_replace('/', '\\', $this->options['namespace']);
        $this->options['all']        = $this->option('all') ? true : false;
        $this->options['table']      = $this->option('table') ?: '';
        $this->options['prefix']     = $this->option('prefix') ?: '';
    }

    public function doComment($text, $overrideDebug = false)
    {
        if ($this->options['debug'] || $overrideDebug) {
            $this->comment($text);
        }
    }

    private function getTableWithoutPrefix($table)
    {
        if (preg_match('/^([a-zA-Z]*_)[a-zA-Z_]+$/', $table, $re)) {
            if (in_array($re[1], $this->prefixes)) {
                return preg_replace("/^" . $re[1] . "/", '', $table);
            }
        }
        return $table;
    }

    private function countPrefixesInTables($prefix, $tables)
    {
        return count(array_filter($tables, function ($x) use ($prefix) {
            return strpos($x, $prefix) === 0;
        }));
    }

    private function findPrefixes()
    {
        $prefixes = [$this->options['prefix']];
        $ignoredPrefixes = [];
        $tables = $this->getAllTables()->toArray();

        foreach ($tables as $table) {
            if (preg_match('/^([a-zA-Z]*_)[a-zA-Z_]+$/', $table, $re)) {
                if ($this->countPrefixesInTables($re[1], $tables) > 1) {
                    if (
                        !in_array($re[1], $prefixes)
                        && !in_array($re[1], $ignoredPrefixes)
                        && $this->confirm("Found potential prefix '{$re[1]}'. Is this correct, do you want use it?", true)
                    ) {
                        $prefixes[] = $re[1];
                    } else {
                        $ignoredPrefixes[] = $re[1];
                    }
                }
            }
        }
        return $prefixes;
    }

    /**
     * Recupera todas las tablas (excepto migrations).
     */
    public function getAllTables()
    {
        if (strlen($this->options['connection']) <= 0) {
            $tables = collect(DB::select('show tables'));
        } else {
            $tables = collect(DB::connection($this->options['connection'])->select('show tables'));
        }

        $tables = $tables->map(function ($value) {
            return collect($value)->flatten()->first();
        })->reject(function ($value) {
            return $value == 'migrations';
        });

        return $tables;
    }

    public function replaceUsTimestamps($stub)
    {
        return str_replace('{{usetimestamps}}', '', $stub);
    }

    public function replaceFields($stub, $fields = '')
    {
        return str_replace('{{fields}}', $fields, $stub);
    }

    public function resetFields()
    {
        $this->fieldsFillable = '';
        $this->fieldsHidden   = '';
        $this->fieldsCast     = '';
        $this->fieldsDate     = '';
    }

    /**
     * Retorna un string con array multilinea para listar elementos simples:
     * [
     *     'campo1',
     *     'campo2',
     * ]
     */
    private function buildMultilineArray(array $items)
    {
        if (count($items) === 0) {
            return '[]';
        }
        $result = "[\n";
        foreach ($items as $item) {
            $result .= "        '$item',\n";
        }
        $result .= "    ]";
        return $result;
    }

    /**
     * Retorna un string con array multilinea para defaults (asociativo).
     * [
     *     'id' => null,
     *     'campo' => 'algo',
     * ]
     */
    private function buildAssocArrayMultiline(array $items, $indentLevel = 1)
    {
        if (count($items) === 0) {
            return '[]';
        }
        $indent = str_repeat('    ', $indentLevel);
        $result = "[\n";
        foreach ($items as $item) {
            $result .= $indent . $item . ",\n";
        }
        $result .= str_repeat('    ', $indentLevel - 1) . "]";
        return $result;
    }

    /**
     * Retorna un string con array de reglas en multilinea. Ej:
     * return [
     *     'campo' => 'required|...',
     *     'otro' => '[ "required", new \Arkadia\Laravel\Rules\UniqueModel($this) ]'
     * ];
     */
    private function buildRulesArrayMultiline(array $rules)
    {
        if (count($rules) === 0) {
            return '[]';
        }
        $result = "[\n";
        foreach ($rules as $field => $rule) {
            $result .= "            '$field' => $rule,\n";
        }
        $result .= "        ]";
        return $result;
    }

    /**
     * Determina el tipo PHP (boolean, integer, float, string...) basado en column type SQL.
     */
    public function getPhpType($columnType)
    {
        $length = $this->getLength($columnType);
        if ($this->isNumeric($columnType) != null) {
            $type = $this->isInteger($columnType);
            if ($length == '1') {
                return 'boolean';
            } elseif ($type != null) {
                return 'integer';
            }
            return 'float';
        } else {
            if ($columnType == 'longtext') {
                return 'array';
            }
            if (in_array($columnType, ['datetime', 'time', 'date'])) {
                return $columnType;
            }
        }
        return 'string';
    }

    /**
     * Genera la regla de validación basada en metadatos de la columna.
     */
    public function getRules($info)
    {
        $rules = '';

        if ($info->Field == 'id') {
            // si es id entero => nullable, sino required
            $rules = $this->getPhpType($info->Type) == 'integer' ? 'nullable' : 'required';
        } else {
            // si es null => nullable, sino required
            $rules = $info->Null == 'YES' ? 'nullable' : 'required';
        }

        $length = $this->getLength($info->Type);
        if ($this->isNumeric($info->Type) != null) {
            $type = $this->isInteger($info->Type);
            if ($length == '1') {
                $rules .= '|boolean';
            } elseif ($type != null) {
                $rules .= '|numeric|integer';
            } else {
                $rules .= '|numeric';
            }
        } elseif ($this->isDateTime($info->Type) != null) {
            $rules .= '|date';
        } elseif ($info->Type == 'longtext') {
            $rules .= '|array';
        } else {
            // string
            $rules .= '|string';
            if ($length) {
                $rules .= '|max:' . $length;
            }
            if (preg_match("/email/", $info->Field)) {
                $rules .= '|email';
            }
        }

        // si la columna es UNIQUE
        if ($info->Key !== 'UNI') {
            return '"' . $rules . '"';
        }
        // unique con Rule\UniqueModel
        return "[ '" . str_replace('|', "', '", $rules) . "', new \\Arkadia\\Laravel\\Rules\\UniqueModel(\$this) ]";
    }

    protected function getLength($text)
    {
        preg_match("/\d+/", $text, $output_array);
        return count($output_array) == 0 ? null : $output_array[0];
    }

    protected function isInteger($text)
    {
        preg_match("/tinyint|smallint|mediumint|bigint|int/", $text, $output_array);
        return count($output_array) == 0 ? null : $output_array[0];
    }

    protected function isNumeric($text)
    {
        preg_match("/tinyint|smallint|mediumint|bigint|int|decimal|float|double|real|bit|serial/", $text, $output_array);
        return count($output_array) == 0 ? null : $output_array[0];
    }

    protected function isDateTime($text)
    {
        preg_match("/datetime|timestamp|date|time|year/", $text, $output_array);
        return count($output_array) == 0 ? null : $output_array[0];
    }
}
