<?php

namespace Arkadia\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Comando para generar migraciones desde la DB existente.
 * Uso: php artisan arkadia:migrations
 */
class GenerateMigrationsCommand extends Command
{
    protected $signature = 'arkadia:migrations
                            {--connection= : Nombre de la conexión de base de datos (si no, usa la por defecto)}
                            {--path=database/migrations : Carpeta destino donde se guardarán las migraciones}
                            {--ignore=migrations,failed_jobs : Tablas que ignorar, separadas por coma}';

    protected $description = 'Genera migraciones .php para cada tabla existente en la DB.';

    // Convenciones básicas para mapear tipos MySQL -> tipos Laravel
    protected $typeMappings = [
        // MySQL => (método Blueprint, longitudPorDefecto)
        'int'             => ['integer',      null],
        'tinyint'         => ['tinyInteger',  null],
        'smallint'        => ['smallInteger', null],
        'mediumint'       => ['mediumInteger',null],
        'bigint'          => ['bigInteger',   null],
        'varchar'         => ['string',       255],
        'char'            => ['char',         255],
        'text'            => ['text',         null],
        'longtext'        => ['longText',     null],
        'mediumtext'      => ['mediumText',   null],
        'json'            => ['json',         null],
        'jsonb'           => ['jsonb',        null],
        'datetime'        => ['dateTime',     null],
        'timestamp'       => ['timestamp',    null],
        'date'            => ['date',         null],
        'time'            => ['time',         null],
        'decimal'         => ['decimal',      '8,2'], // por defecto, ajusta según tus necesidades
        'double'          => ['double',       null],
        'float'           => ['float',        null],
        'boolean'         => ['boolean',      null],
        'enum'            => ['enum',         null],  // Requiere tratamiento especial
    ];

    public function handle()
    {
        $this->info("Generando migraciones de la BD existente...");

        $connectionName = $this->option('connection') ?: config('database.default');
        $ignoreTables   = array_filter(explode(',', $this->option('ignore') ?: 'migrations,failed_jobs'));
        $path           = base_path($this->option('path'));
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }

        // Listar tablas
        $tables = $this->getAllTables($connectionName)->reject(function($table) use ($ignoreTables){
            return in_array($table, $ignoreTables);
        })->values();

        // Para cada tabla, generamos un archivo de migración
        foreach ($tables as $table) {
            // Recoger columnas, llaves, foreign keys, etc.
            $columns       = $this->getColumns($table, $connectionName);
            $indexes       = $this->getIndexes($table, $connectionName);
            $foreignKeys   = $this->getForeignKeys($table, $connectionName);

            // Construir el contenido de la migración
            $migrationCode = $this->buildMigrationForTable($table, $columns, $indexes, $foreignKeys);

            // Nombrar el archivo con timestamp y snake_case
            $timestamp = Carbon::now()->format('Y_m_d_His');
            $filename  = $timestamp.'_create_'.$table.'_table.php';

            file_put_contents($path.'/'.$filename, $migrationCode);

            $this->info("Creada migración para tabla: {$table} -> {$filename}");
            // Para evitar colisión en timestamps en bucles rápidos
            usleep(100000); // 0.1 s
        }

        $this->info("Migraciones generadas en: $path");
        return 0;
    }

    /**
     * Obtiene el listado de tablas de la DB (ignorando migrations).
     */
    protected function getAllTables($connectionName)
    {
        $schema = DB::connection($connectionName)->getDatabaseName();
        $rows   = DB::connection($connectionName)->select("SHOW TABLES");
        $key    = "Tables_in_{$schema}";

        return collect($rows)->map(function($r) use ($key){
            return $r->$key ?? collect($r)->first();
        });
    }

    /**
     * Retorna información de columnas a partir de information_schema.COLUMNS,
     * para no limitarnos sólo a "DESCRIBE".
     */
    protected function getColumns($table, $connectionName)
    {
        $dbName = DB::connection($connectionName)->getDatabaseName();
        $sql = "
            SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                   COLUMN_KEY, EXTRA, CHARACTER_MAXIMUM_LENGTH
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ";
        return DB::connection($connectionName)->select($sql, [$dbName, $table]);
    }

    /**
     * Retorna info de índices (KEY_COLUMN_USAGE no siempre indica todos).
     * Vamos a usar "SHOW INDEX FROM" para simplificar.
     */
    protected function getIndexes($table, $connectionName)
    {
        $rows = DB::connection($connectionName)->select("SHOW INDEX FROM `{$table}`");
        // Estructura simplificada: agrupar index_name -> columns
        // unique, primary, etc.
        $indexes = [];
        foreach ($rows as $row) {
            $keyName  = $row->Key_name;
            $colName  = $row->Column_name;
            $nonUnique= $row->Non_unique; // 0 => unique
            $indexes[$keyName]['columns'][] = $colName;
            $indexes[$keyName]['unique'] = ($nonUnique == 0);
        }
        return $indexes;
    }

    /**
     * Retorna info de foreign keys desde information_schema.KEY_COLUMN_USAGE.
     */
    protected function getForeignKeys($table, $connectionName)
    {
        $dbName = DB::connection($connectionName)->getDatabaseName();
        $sql = "
            SELECT KCU.CONSTRAINT_NAME, KCU.COLUMN_NAME,
                   KCU.REFERENCED_TABLE_NAME, KCU.REFERENCED_COLUMN_NAME,
                   RC.UPDATE_RULE, RC.DELETE_RULE
            FROM information_schema.KEY_COLUMN_USAGE KCU
            JOIN information_schema.REFERENTIAL_CONSTRAINTS RC
                 ON RC.CONSTRAINT_NAME = KCU.CONSTRAINT_NAME
                 AND RC.CONSTRAINT_SCHEMA = KCU.TABLE_SCHEMA
            WHERE KCU.TABLE_SCHEMA = ?
              AND KCU.TABLE_NAME   = ?
              AND KCU.REFERENCED_TABLE_NAME IS NOT NULL
        ";
        return DB::connection($connectionName)->select($sql, [$dbName, $table]);
    }

    /**
     * Genera el código de la migración para una tabla concreta.
     */
    protected function buildMigrationForTable($table, $columns, $indexes, $foreignKeys)
    {
        $className = Str::studly("Create_{$table}_table");
        // Estructura base
        $migration = "<?php\n\n";
        $migration .= "use Illuminate\\Database\\Migrations\\Migration;\n";
        $migration .= "use Illuminate\\Database\\Schema\\Blueprint;\n";
        $migration .= "use Illuminate\\Support\\Facades\\Schema;\n\n";
        $migration .= "return new class extends Migration {\n\n";

        // up()
        $migration .= "    public function up()\n";
        $migration .= "    {\n";
        $migration .= "        Schema::create('$table', function (Blueprint \$table) {\n";

        // Definir columnas
        foreach ($columns as $col) {
            $migration .= $this->buildColumnLine($col);
        }

        // Definir índices (incluyendo primary si no está en la columna).
        $migration .= $this->buildIndexLines($columns, $indexes);

        // Definir foreign keys
        $migration .= $this->buildForeignKeyLines($foreignKeys);

        $migration .= "        });\n";
        $migration .= "    }\n\n";

        // down()
        $migration .= "    public function down()\n";
        $migration .= "    {\n";
        $migration .= "        Schema::dropIfExists('$table');\n";
        $migration .= "    }\n";
        $migration .= "};\n";

        return $migration;
    }

    /**
     * Construye la línea que define la columna en Blueprint.
     */
    protected function buildColumnLine($col)
    {
        $colName = $col->COLUMN_NAME;
        $colType = strtolower($col->DATA_TYPE);  // p.ej. int, varchar, text, etc.
        $colKey  = $col->COLUMN_KEY;            // PRI, MUL, UNI
        $extra   = strtolower($col->EXTRA);      // auto_increment, etc.
        $length  = $col->CHARACTER_MAXIMUM_LENGTH; // p.ej. 255
        $isNull  = $col->IS_NULLABLE === 'YES';
        $default = $col->COLUMN_DEFAULT;         // valor por defecto

        // Chequear si es PK con auto_increment
        if ($colKey === 'PRI' && $extra === 'auto_increment') {
            // Ej: $table->increments('id');
            return "            \$table->increments('$colName');\n";
        }

        // Traducir data type a método de Blueprint
        list($method, $fallbackLen) = $this->typeMappings[$colType] ?? ['string', 255];

        // Si la longitud es nula, usar fallback si aplica
        $useLength = $length ?: $fallbackLen;

        // Construimos la llamada: $table->string('nombre', 100)
        $line = "            \$table->{$method}('$colName'";
        if ($useLength) {
            // En caso de decimal(8,2), $useLength = '8,2'
            if (Str::contains($useLength, ',')) {
                // p.ej. decimal(8,2)
                $parts = explode(',', $useLength);
                $line .= ", {$parts[0]}, {$parts[1]}";
            } else {
                $line .= ", $useLength";
            }
        }
        $line .= ")";

        // Not nullable => ->nullable(false) no existe, es default
        if ($isNull) {
            $line .= "->nullable()";
        }

        // Default value
        if ($default !== null) {
            // Manejo especial si es 'CURRENT_TIMESTAMP'
            if ($default === 'CURRENT_TIMESTAMP') {
                $line .= "->useCurrent()";
            } else {
                // Si es string, comillas
                $defVal = is_numeric($default) ? $default : "'".str_replace("'", "\\'", $default)."'";
                $line .= "->default($defVal)";
            }
        }

        // Si es unique y no lo capturamos en $indexes
        // (aunque lo normal es capturarlo en getIndexes)
        // if ($colKey === 'UNI') {
        //     $line .= "->unique()";
        // }

        $line .= "; \n";
        return $line;
    }

    /**
     * Construye las líneas para índices, si no los tratamos en buildColumnLine.
     */
    protected function buildIndexLines($columns, $indexes)
    {
        // Revisa si la PK auto_increment ya está, o si es compuesta
        // p.ej. $table->primary(['foo','bar']);
        $result = '';
        foreach ($indexes as $keyName => $info) {
            // Salteamos la PK si se definió con increments
            // Normalmente la PK "PRIMARY" la detectamos en buildColumnLine,
            // pero si es PK compuesta, lo definimos aquí
            if ($keyName === 'PRIMARY') {
                // si hay mas de 1 col -> $table->primary(['col1','col2']);
                if (count($info['columns']) > 1) {
                    $cols = "['".implode("','",$info['columns'])."']";
                    $result .= "            \$table->primary($cols);\n";
                }
                continue;
            }

            // unique / index
            $cols = $info['columns'];
            if (count($cols) == 1) {
                $colName = $cols[0];
                // si es unique
                if ($info['unique']) {
                    $result .= "            \$table->unique('$colName', '{$keyName}');\n";
                } else {
                    $result .= "            \$table->index('$colName', '{$keyName}');\n";
                }
            } else {
                // multiple columns
                $colsArr = "['".implode("','",$cols)."']";
                if ($info['unique']) {
                    $result .= "            \$table->unique($colsArr, '{$keyName}');\n";
                } else {
                    $result .= "            \$table->index($colsArr, '{$keyName}');\n";
                }
            }
        }

        return $result;
    }

    /**
     * Construye líneas para las foreign keys con ->foreign('col')->references('col')...->on('table')
     * y si corresponde ->onDelete() / ->onUpdate()
     */
    protected function buildForeignKeyLines($foreignKeys)
    {
        $result = '';
        foreach ($foreignKeys as $fk) {
            $col         = $fk->COLUMN_NAME;
            $refTable    = $fk->REFERENCED_TABLE_NAME;
            $refColumn   = $fk->REFERENCED_COLUMN_NAME;
            $onUpdate    = $fk->UPDATE_RULE; // CASCADE, RESTRICT, etc.
            $onDelete    = $fk->DELETE_RULE;

            $result .= "            \$table->foreign('$col')->references('$refColumn')->on('$refTable')";

            if (strtolower($onDelete) !== 'no action') {
                $result .= "->onDelete('".strtolower($onDelete)."')";
            }
            if (strtolower($onUpdate) !== 'no action') {
                $result .= "->onUpdate('".strtolower($onUpdate)."')";
            }
            $result .= ";\n";
        }
        return $result;
    }
}
