<?php

namespace Arkadia\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenerateModelCommand extends Command
{
    protected $signature = 'Arkadia:modelfromtable
                            {--name= : Model name. If set, only 1 table is required in --table }
                            {--table= : a single table or a list of tables separated by a comma (,)}
                            {--base= : Base model name. Default Arkadia\Laravel\Models\BaseModel }
                            {--prefix= : Table prefix }
                            {--connection= : database connection to use, leave off and it will use the .env connection}
                            {--debug : turns on debugging}
                            {--folder= : by default models are stored in app, but you can change that}
                            {--namespace= : by default the namespace that will be applied to all models is App}
                            {--all : run for all tables}';

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
        $basepath = $path . DIRECTORY_SEPARATOR . "Base";
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

        if ($this->options['folder'] != app_path() && !is_dir($this->options['folder'])) {
            mkdir($this->options['folder'], 0755, true);
        }
        if (!is_dir($basepath)) {
            mkdir($basepath, 0755, true);
        }

        // Obtener lista de tablas (ya se garantiza que sean strings)
        if ($this->options['all']) {
            $tables = $this->getAllTables();
        } else {
            $tables = explode(',', $this->options['table']);
        }

        // Procesar cada tabla
        foreach ($tables as $table) {
            // Forzamos que $table sea string en caso de que no lo sea
            $table = (string)$table;

            $this->currentTable = $table;
            $this->rules = null;
            $this->properties = null;
            $this->modelRelations = null;

            $stub = $modelStub;
            $basestub = $basemodelStub;

            $tablename = $this->options['name'] !== '' ? $this->options['name'] : $table;
            $tablename = $this->getTableWithoutPrefix($tablename);

            $classname = $this->options['name'] !== '' ? $this->options['name'] : Str::studly(Str::singular($tablename));
            $fullPath = "$path/$classname.php";
            $fullBasePath = "$basepath/Base$classname.php";

            $this->doComment("Generating file: $classname.php", true);
            $this->doComment("Generating file: Base/Base$classname.php", true);

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

            $stub = $this->replaceClassName($stub, $tablename);
            $stub = $this->replaceModuleInformation($stub, $model);
            $stub = $this->replaceConnection($stub, $this->options['connection']);
            $stub = $this->replaceLabel($stub, $columns);

            $basestub = $this->replaceClassName($basestub, $tablename);
            $basestub = $this->replaceBaseClassName($basestub, $this->options['base']);
            $basestub = $this->replaceModuleInformation($basestub, $model);
            $basestub = $this->replaceRulesAndProperties($basestub, $this->columns, $tablename);
            $basestub = $this->replaceConnection($basestub, $this->options['connection']);
            $basestub = $this->replacePrimaryKey($basestub, $columns);

            if (!file_exists($fullPath)) {
                $this->doComment('Writing model: ' . $fullPath, true);
                file_put_contents($fullPath, $stub);
            }
            if (!file_exists($fullBasePath))
                $this->doComment('Writing base model: ' . $fullBasePath, true);
            else
                $this->doComment('Updating base model: ' . $fullBasePath, true);

            file_put_contents($fullBasePath, $basestub);
        }

        $this->info('Complete');
    }

    public function getSchema($tableName)
    {
        $this->doComment('Retrieving table definition for: ' . $tableName);
        if (strlen($this->options['connection']) <= 0) {
            return Schema::getColumnListing($tableName);
        } else {
            return Schema::connection($this->options['connection'])->getColumnListing($tableName);
        }
    }

    public function describeTable($tableName)
    {
        $this->doComment('Retrieving column information for: ' . $tableName);
        if (strlen($this->options['connection']) <= 0) {
            return DB::select(DB::raw('describe ' . $tableName));
        } else {
            return DB::connection($this->options['connection'])->select(DB::raw('describe ' . $tableName));
        }
    }

    public function replaceClassName($stub, $tableName)
    {
        return str_replace('{{class}}', Str::studly(Str::singular($tableName)), $stub);
    }

    public function replaceBaseClassName($stub, $baseclass)
    {
        return str_replace('{{baseclass}}', Str::studly($baseclass), $stub);
    }

    public function replaceModuleInformation($stub, $modelInformation)
    {
        $stub = str_replace('{{table}}', $modelInformation['table'], $stub);
        $this->fieldsFillable = '';
        $this->fieldsHidden   = '';
        $this->fieldsCast     = '';
        $this->fieldsDate     = '';

        foreach ($modelInformation['fillable'] as $field) {
            if ($field != 'id') {
                $this->fieldsFillable .= (strlen($this->fieldsFillable) > 0 ? ', ' : '') . "'$field'";
                $fieldsFiltered = $this->columns->where('Field', $field);
                if ($fieldsFiltered && $fieldsFiltered->count() > 0) {
                    $type = strtolower($fieldsFiltered->first()->Type);
                    switch ($type) {
                        case 'timestamp':
                        case 'datetime':
                        case 'date':
                            $this->fieldsDate .= (strlen($this->fieldsDate) > 0 ? ', ' : '') . "'$field'";
                            break;
                        case 'tinyint(1)':
                            $this->fieldsCast .= (strlen($this->fieldsCast) > 0 ? ', ' : '') . "'$field' => 'boolean'";
                            break;
                    }
                }
            } else {
                if ($field != 'id' && $field != 'created_at' && $field != 'updated_at') {
                    $this->fieldsHidden .= (strlen($this->fieldsHidden) > 0 ? ', ' : '') . "'$field'";
                }
            }
        }
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

        foreach (['title', 'name', 'key'] as $p) {
            if (in_array($p, $columnsArr)) {
                $stub = str_replace('{{namecolumn}}', "protected static \$nameColumn = '$p';", $stub);
            }
        }
        $stub = str_replace('{{namecolumn}}', '', $stub);

        $priorities = ['title', 'name', 'key', 'id'];
        $first = null;
        foreach ($priorities as $p) {
            if (in_array($p, $columnsArr)) {
                if ($first !== null) {
                    return str_replace('{{label}}', "\$this->$first ?: \$this->$p", $stub);
                } else {
                    $first = $p;
                }
            }
        }
        if ($first !== null)
            return str_replace('{{label}}', "\$this->$first", $stub);
        return str_replace('{{label}}', '', $stub);
    }

    public function replaceRulesAndProperties($stub, $columns, $tablename)
    {
        $this->rules = '';
        $this->defaults = '';
        $this->properties = '';
        $this->modelRelations = '';

        foreach ($columns as $column) {
            $field = $column->Field;
            $type = $this->getPhpType($column->Type);
            $this->defaults .= (strlen($this->defaults) > 0 ? ', ' : '')
                . "\n\t\t'$field' => " .
                ($type == 'string' && $column->Default !== null ? '\'' : '') .
                ($column->Default !== null ? $column->Default : 'null') .
                ($type == 'string' && $column->Default !== null ? '\'' : '');
            $this->rules .= (strlen($this->rules) > 0 ? ', ' : '') . "\n\t\t\t'$field' => " . $this->getRules($column);
            $this->properties .= "\n * @property " . $type . " " . $field;
            $this->modelRelations .= $this->getRelationTemplate($column, $this->properties, $tablename);
        }
        $this->defaults .= "\n\t";
        $this->rules .= "\n\t\t";
        $this->modelRelations .= $this->getRelationsForModel($this->properties, $tablename);

        $stub = str_replace('{{defaults}}', $this->defaults, $stub);
        $stub = str_replace('{{rules}}', $this->rules, $stub);
        $stub = str_replace('{{properties}}', $this->properties, $stub);
        $stub = str_replace('{{relations}}', $this->modelRelations, $stub);
        return $stub;
    }

    public function getRelationsForModel(&$properties, $tablename)
    {
        $s = '';
        $searchedColumnName = Str::snake(Str::singular($tablename) . "_id");

        foreach ($this->getAllTables() as $table) {
            if (in_array($searchedColumnName, $this->getTableColumns($table))) {
                $tableNameNoPrefix = $this->getTableWithoutPrefix($table);
                $name = $tableNameNoPrefix;
                $relatedModel = $this->options['namespace'] . "\\" . Str::studly(Str::singular($tableNameNoPrefix));
                $properties .= "\n * @property \\" . $relatedModel . "[] " . $name;
                $s .= "\n\tpublic function $name() {\n" .
                    "\t\treturn \$this->hasMany( \\$relatedModel::class, '$searchedColumnName' );\n" .
                    "\t}\n";
            }
        }
        return $s;
    }

    public function getPhpType($columnType)
    {
        $length = $this->getLength($columnType);
        if ($this->isNumeric($columnType) != null) {
            $type = $this->isInteger($columnType);
            if ($length == '1')
                return 'boolean';
            else if ($type != null)
                return 'integer';
            return 'float';
        } else {
            if ($columnType == 'longtext')
                return 'array';
            if (in_array($columnType, ['datetime', 'time', 'date']))
                return $columnType;
        }
        return 'string';
    }

    public function getRules($info)
    {
        $rules = '';
        if ($info->Field == 'id') {
            $rules = $this->getPhpType($info->Type) == 'integer' ? 'nullable' : 'required';
        } else {
            $rules = $info->Null == 'YES' ? 'nullable' : 'required';
        }
        $length = $this->getLength($info->Type);
        if ($this->isNumeric($info->Type) != null) {
            $type = $this->isInteger($info->Type);
            if ($length == '1')
                $rules .= '|boolean';
            else if ($type != null)
                $rules .= '|numeric|integer';
            else
                $rules .= '|numeric';
        } else if ($this->isDateTime($info->Type) != null) {
            $rules .= "|date";
        } else if ($info->Type == 'longtext') {
            $rules .= "|array";
        } else {
            preg_match("/\w+/", $info->Type, $output_array);
            $rules .= "|string" . ($length ? '|max:' . $length : '');
            if (preg_match("/email/", $info->Field))
                $rules .= "|email";
        }
        if ($info->Key !== 'UNI') {
            return '"' . $rules . '"';
        } else {
            return "[ '" . str_replace('|', "', '", $rules) . "', new \\Arkadia\\Laravel\\Rules\\UniqueModel(\$this) ]";
        }
    }

    public function getRelationTemplate($column, &$properties, $currentTablename)
    {
        $foreignKey = $column->Field;
        if (strpos($foreignKey, '_id') === false)
            return '';
        if ($foreignKey != 'id') {
            $tablename = $this->getTableNameByForeignKey($foreignKey);
            if ($tablename != null) {
                $tablename = $this->getTableWithoutPrefix($tablename);
                if ($tablename !== null) {
                    $modelname = Str::singular(Str::studly($tablename));
                    $relatedModel = $this->options['namespace'] . "\\" . $modelname;
                    $name = Str::singular($tablename);
                    $properties .= "\n * @property \\" . $relatedModel . " " . $name;
                    $s = "\tpublic function $name() {\n" .
                        "\t\treturn \$this->belongsTo( \\$relatedModel::class, '$foreignKey' );\n" .
                        "\t}\n";
                    return $s;
                }
            } else if ($foreignKey == 'parent_id') {
                $relatedModel = $this->options['namespace'] . "\\" . Str::singular(Str::studly($currentTablename));
                $properties .= "\n * @property \\" . $relatedModel . " parent";
                return "\tpublic function parent() {\n" .
                    "\t\treturn \$this->belongsTo( static::class, 'parent_id' );\n" .
                    "\t}\n";
            }
        }
        return '';
    }

    protected function getTableNameByForeignKey($foreignKey)
    {
        $tables = $this->getAllTables()->toArray();
        rsort($tables);
        $foreignKey = Str::plural(str_replace('_id', '', $foreignKey));
        $matches = preg_grep("/^[a-zA-Z]*_" . $foreignKey . "/", $tables);
        if ($matches == null)
            return null;
        $matches = array_values($matches);
        if (count($matches) == 1)
            return $matches[0];
        else {
            while (true) {
                $t = $this->ask('Tables that match to foreign keys are: ' . implode(',', $matches) . '. Write full table name that you want to choose');
                if (in_array($t, $matches))
                    return $t;
                $this->error('Bad tablename');
            }
        }
    }

    public function getTableColumns($table)
    {
        return Schema::getColumnListing($table);
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

    /**
     * Devuelve todos los nombres de tablas como una colección de strings.
     */
    public function getAllTables()
    {
        if (strlen($this->options['connection']) <= 0) {
            $tables = collect(DB::select(DB::raw('show tables')));
        } else {
            $tables = collect(DB::connection($this->options['connection'])->select(DB::raw('show tables')));
        }

        $tables = $tables->map(function ($value) {
            return collect($value)->flatten()->first();
        })->reject(function ($value) {
            return $value == 'migrations';
        });

        return $tables;
    }

    public function resetFields()
    {
        $this->fieldsFillable = '';
        $this->fieldsHidden   = '';
        $this->fieldsCast     = '';
        $this->fieldsDate     = '';
    }

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
        return __DIR__ . '/../templates/model.jig';
    }

    public function getBaseStub()
    {
        $this->doComment('Loading base model stub');
        return __DIR__ . '/../templates/basemodel.jig';
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
                    if (!in_array($re[1], $prefixes)) {
                        if (!in_array($re[1], $ignoredPrefixes) && $this->confirm("Found potential prefix '{$re[1]}'. Is this correct, do you want use it?", true))
                            $prefixes[] = $re[1];
                        else
                            $ignoredPrefixes[] = $re[1];
                    }
                }
            }
        }
        return $prefixes;
    }
}
