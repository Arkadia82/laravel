<?php

namespace Arkadia\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Genera automáticamente los CRUD Controllers de Laravel Backpack
 * (y sus Requests) para tus modelos y añade la ruta "Route::crud()"
 * en un archivo de rutas Backpack determinado.
 *
 * Uso:
 *   php artisan arkadia:backpack:crud --model=User
 *   php artisan arkadia:backpack:crud --all
 *
 * Para cambiar dónde se añaden las rutas,
 * modificar la propiedad $routeFile.
 */
class GenerateBackpackCrudCommand extends Command
{
    protected $signature = 'arkadia:backpack:crud
                            {--model= : Nombre de un modelo (App\\Models\\Foo) o sin el prefijo, ej. User}
                            {--table= : Tabla concreta (si no, se asume que coincide con --model) }
                            {--all : Genera para todas las tablas (ignora --model)}
                            {--connection= : Conexión de base de datos (opcional)}
                            {--debug : Activa modo debug}';

    protected $description = 'Generate Backpack CRUD controllers & requests for the specified model(s), and add Route::crud.';

    // Rutas de destino de Controllers/Requests
    protected $controllersPath = 'app/Http/Controllers/Admin';
    protected $requestsPath    = 'app/Http/Requests';

    // Namespaces
    protected $adminNamespace  = 'App\\Http\\Controllers\\Admin';
    protected $requestNamespace= 'App\\Http\\Requests';
    protected $modelNamespace  = 'App\\Models';

    // Archivo en el que se añadirán las rutas:
    protected $routeFile = 'routes/backpack/custom.php'; // Ajusta según tu proyecto

    public function handle()
    {
        $this->info('Starting Backpack CRUD generation...');
        $modelOption = $this->option('model');
        $tableOption = $this->option('table');
        $all         = $this->option('all');

        // Si --all, iteramos sobre todas las tablas
        if ($all) {
            $tables = $this->getAllTables();
            foreach ($tables as $table) {
                $modelName = Str::studly(Str::singular($table));
                $this->generateCrudFor($modelName, $table);
            }
            return 0;
        }

        // Caso: un solo modelo
        if (!$modelOption) {
            $this->error('Please specify --model=ModelName or use --all');
            return 1;
        }

        // Deducimos la tabla si no se pasa la opción --table
        $table = $tableOption ?: Str::snake(Str::plural($modelOption));
        $this->generateCrudFor($modelOption, $table);

        return 0;
    }

    /**
     * Genera el CRUD Controller + Request, y añade la ruta en la config Backpack.
     */
    protected function generateCrudFor($modelName, $table)
    {
        // Ajustar el nombre del modelo (ej: "User" => "App\Models\User")
        if (!Str::startsWith($modelName, $this->modelNamespace)) {
            $modelFqn = $this->modelNamespace . '\\' . Str::studly($modelName);
        } else {
            $modelFqn = $modelName; // ya viene con "App\Models\..."
        }

        // Nombre "limpio": "User", "Category", etc.
        $modelClassName = class_basename($modelFqn);

        // Path del Controller final
        $controllerFile = $this->controllersPath . '/' . $modelClassName . 'CrudController.php';
        // Path del Request
        $requestFile    = $this->requestsPath . '/' . $modelClassName . 'Request.php';

        $this->comment("Generating CRUD for Model=$modelClassName Table=$table");

        // Recoger columnas
        $columns = $this->getTableColumns($table);
        if (empty($columns)) {
            $this->warn("  -> Table [$table] has no columns or doesn't exist. Skipping fields & columns addition.");
        }

        // 1. Generar la Request
        $requestCode = $this->buildRequestClass($modelClassName, $columns);
        $this->writeFile($requestFile, $requestCode);

        // 2. Generar el CrudController
        $controllerCode = $this->buildCrudController($modelClassName, $modelFqn, $table, $columns);
        $this->writeFile($controllerFile, $controllerCode);

        // 3. Añadir la ruta (Route::crud(...)) al final del archivo $routeFile
        // El "slug" en la ruta normalmente es la versión kebab-case o lower-case del modelo
        // p.ej. "user" => /admin/user
        $slug = Str::snake($modelClassName);
        $controllerNamespace = $this->adminNamespace . '\\' . $modelClassName . 'CrudController';
        $this->addRoute($slug, $controllerNamespace);

        $this->info("  -> CRUD generated: $controllerFile");
    }

    /**
     * Crea el contenido de la clase Request (ej. UserRequest).
     * Añade validaciones mínimas basadas en el 'describe' de la tabla.
     */
    protected function buildRequestClass($modelClass, array $columns)
    {
        $rulesArray = [];
        foreach ($columns as $col) {
            $name = $col['Field'];
            // Ignora PK "id", timestamps, etc.
            if (in_array($name, ['id','created_at','updated_at','deleted_at'])) {
                continue;
            }
            // Eje: si la columna es nullable => 'nullable', si no => 'required'
            $required = ($col['Null'] === 'NO' && $col['Default'] === null) ? 'required' : 'nullable';
            // Añado una validación muy simple según tipo
            if (Str::contains($col['Type'], 'int')) {
                $rulesArray[$name] = "$required|integer";
            } elseif (Str::contains($col['Type'], 'date')) {
                $rulesArray[$name] = "$required|date";
            } elseif (Str::contains($col['Type'], 'tinyint(1)')) {
                $rulesArray[$name] = "$required|boolean";
            } else {
                $rulesArray[$name] = "$required|string";
            }
        }

        $rulesString = '';
        foreach ($rulesArray as $field => $rule) {
            $rulesString .= "            '$field' => '$rule',\n";
        }
        if (!$rulesString) {
            $rulesString = "            // no fields to validate\n";
        }

        $stub = <<<PHP
<?php

namespace {$this->requestNamespace};

use Illuminate\\Foundation\\Http\\FormRequest;

class {$modelClass}Request extends FormRequest
{
    public function authorize()
    {
        // By default, allow all
        return true;
    }

    public function rules()
    {
        return [
$rulesString
        ];
    }
}
PHP;

        return $stub;
    }

    /**
     * Crea el contenido del CrudController (ej. UserCrudController).
     */
    protected function buildCrudController($modelClass, $modelFqn, $table, array $columns)
    {
        // Generar addColumns:
        $columnsCode = '';
        foreach ($columns as $col) {
            $fieldName = $col['Field'];
            if (in_array($fieldName, ['created_at','updated_at','deleted_at'])) {
                continue; // saltamos
            }
            $type = $this->inferBackpackColumnType($col);
            $columnsCode .= "            \$this->crud->addColumn([\n"
                           ."                'name' => '$fieldName',\n"
                           ."                'type' => '$type',\n"
                           ."            ]);\n";
        }

        // Generar addFields:
        $fieldsCode = '';
        foreach ($columns as $col) {
            $fieldName = $col['Field'];
            if (in_array($fieldName, ['id','created_at','updated_at','deleted_at'])) {
                continue; // saltamos
            }
            $type = $this->inferBackpackColumnType($col);
            $fieldsCode .= "            \$this->crud->addField([\n"
                          ."                'name' => '$fieldName',\n"
                          ."                'type' => '$type',\n"
                          ."            ]);\n";
        }

        $controllerName = $modelClass.'CrudController';
        $requestName    = $modelClass.'Request';
        $requestFull    = $this->requestNamespace.'\\'.$requestName;
        $namespace      = $this->adminNamespace;

        $stub = <<<PHP
<?php

namespace $namespace;

use Backpack\\CRUD\\app\\Http\\Controllers\\CrudController;
use $requestFull;

class $controllerName extends CrudController
{
    public function setup()
    {
        \$this->crud->setModel($modelFqn::class);
        \$this->crud->setRoute(config('backpack.base.route_prefix') . '/'.strtolower('$modelClass'));
        \$this->crud->setEntityNameStrings(strtolower('$modelClass'), strtolower(Str::plural('$modelClass')));

        // Columns:
$columnsCode

        // Fields:
$fieldsCode
    }

    public function store()
    {
        \$this->crud->setValidation($requestName::class);
        return parent::store();
    }

    public function update()
    {
        \$this->crud->setValidation($requestName::class);
        return parent::update();
    }
}
PHP;

        return $stub;
    }

    /**
     * Inserta "Route::crud('slug', 'Controller')" en $this->routeFile,
     * si no existe ya.
     */
    protected function addRoute($slug, $controllerNamespace)
    {
        $routePath = base_path($this->routeFile);
        if (! file_exists($routePath)) {
            $this->warn("Route file [$this->routeFile] not found. Cannot add Route::crud automatically.");
            return;
        }

        $routeLine = "Route::crud('$slug', '$controllerNamespace');";
        $content   = file_get_contents($routePath);

        // Chequeo naive para no duplicar
        if (Str::contains($content, $routeLine)) {
            $this->comment("  -> Route for [$slug] already exists in [$this->routeFile]. Skipping.");
            return;
        }

        // Append al final, con un salto de línea
        $newContent = rtrim($content)."\n\n".$routeLine."\n";
        file_put_contents($routePath, $newContent);

        $this->comment("  -> Added route to [$this->routeFile]: $routeLine");
    }

    /**
     * Heurística muy sencilla para Backpack 'type' en Column/Field
     */
    protected function inferBackpackColumnType(array $col)
    {
        $type = $col['Type']; // p.ej. "varchar(255)", "int(10)", "tinyint(1)"

        // tinyint(1) => checkbox
        if (Str::contains($type, 'tinyint(1)')) {
            return 'checkbox';
        }
        // date/datetime => date
        if (Str::contains($type, 'date') || Str::contains($type, 'time')) {
            return 'date';
        }
        // int => number
        if (Str::contains($type, 'int')) {
            return 'number';
        }
        // text => textarea
        if (Str::contains($type, 'text')) {
            return 'textarea';
        }
        // por defecto => text
        return 'text';
    }

    /**
     * Lee las columnas de la tabla con DESCRIBE.
     */
    protected function getTableColumns($table)
    {
        $connection = $this->option('connection') ?: config('database.default');
        if (!Schema::connection($connection)->hasTable($table)) {
            $this->warn("  -> Table [$table] does not exist in [$connection] connection");
            return [];
        }

        $cols = DB::connection($connection)->select("DESCRIBE `{$table}`");
        $columns = [];
        foreach ($cols as $c) {
            $columns[] = (array) $c;
        }
        return $columns;
    }

    protected function getAllTables()
    {
        $connection = $this->option('connection') ?: config('database.default');
        $dbName     = DB::connection($connection)->getDatabaseName();
        $all        = DB::connection($connection)->select("SHOW TABLES");
        $key        = "Tables_in_$dbName";

        $tables = collect($all)->map(function($item) use($key){
            return $item->$key ?? collect($item)->first();
        })->reject(function($table){
            // ignorar migrations y otras
            return in_array($table, ['migrations','failed_jobs','password_resets']);
        })->values();

        return $tables;
    }

    /**
     * Escribe el archivo en disco, evitando sobrescribir si ya existe.
     */
    protected function writeFile($fullPath, $content)
    {
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($fullPath)) {
            $this->warn("File [$fullPath] already exists; skipping overwrite.");
            return;
        }
        file_put_contents($fullPath, $content);
        $this->comment("  -> Created file: $fullPath");
    }
}
