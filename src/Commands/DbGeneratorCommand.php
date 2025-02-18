<?php

namespace SobhanDev\DbGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DbGeneratorCommand extends Command
{
    protected $signature = 'db:generate {--force : Overwrite existing migrations and seeders}';
    protected $description = 'Generate migrations and seeders for all tables in the database';

    // List of tables to skip
    private $excludedTables = ['failed_jobs', 'jobs', 'personal_access_tokens','migrations'];

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        try {
            // Get all table names from the database
            $tables = DB::select('SHOW TABLES');
            $tableNames = array_map(function ($table) {
                return (array)$table;
            }, $tables);

            foreach ($tableNames as $table) {
                $tableName = array_values($table)[0];

                // Skip excluded tables
                if (in_array($tableName, $this->excludedTables)) {
                    $this->info("Skipping table: $tableName");
                    continue;
                }

                $this->info("Processing table: $tableName");

                // Generate Migration for the table
                $this->generateMigration($tableName);
            }

            // After generating table migrations, generate foreign key migrations
            foreach ($tableNames as $table) {
                $tableName = array_values($table)[0];

                // Skip excluded tables
                if (in_array($tableName, $this->excludedTables)) {
                    continue;
                }

                // Get foreign keys
                $foreignKeys = $this->getForeignKeys($tableName);

                // Generate Foreign Key Migration (if any)
                if (!empty($foreignKeys)) {
                    $this->generateForeignKeyMigration($tableName, $foreignKeys);
                }
            }

            // Generate Seeders for all tables
            foreach ($tableNames as $table) {
                $tableName = array_values($table)[0];

                // Skip excluded tables
                if (in_array($tableName, $this->excludedTables)) {
                    continue;
                }

                // Generate Seeder
                $this->generateSeeder($tableName);
            }

            $this->info('All migrations and seeders have been generated!');
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }

    private function generateMigration($tableName)
    {
        // Get column details using SHOW COLUMNS
        $columns = DB::select("SHOW COLUMNS FROM $tableName");
        $primaryKey = $this->getPrimaryKey($tableName);

        // Get foreign keys for this table
        $foreignKeys = $this->getForeignKeys($tableName);
        $foreignKeyColumns = array_column($foreignKeys, 'column');

        // Generate migration content with Anonymous Class
        $migrationContent = "<?php\n\n";
        $migrationContent .= "use Illuminate\Database\Migrations\Migration;\n";
        $migrationContent .= "use Illuminate\Database\Schema\Blueprint;\n";
        $migrationContent .= "use Illuminate\Support\Facades\Schema;\n\n";
        $migrationContent .= "return new class extends Migration\n";
        $migrationContent .= "{\n";
        $migrationContent .= "    public function up()\n";
        $migrationContent .= "    {\n";
        $migrationContent .= "        Schema::create('$tableName', function (Blueprint \$table) {\n";

        // Add primary key
        if ($primaryKey) {
            $migrationContent .= "            \$table->id('$primaryKey');\n";
        }

        // Add columns (excluding foreign key columns)
        foreach ($columns as $column) {
            $columnName = $column->Field;
            $columnType = $this->convertColumnType($column->Type);

            // Skip primary key column if already added
            if ($columnName === $primaryKey || in_array($columnName, $foreignKeyColumns)) {
                continue;
            }

            $migrationContent .= "            \$table->$columnType('$columnName');\n";
        }

        $migrationContent .= "        });\n";
        $migrationContent .= "    }\n\n";
        $migrationContent .= "    public function down()\n";
        $migrationContent .= "    {\n";
        $migrationContent .= "        Schema::dropIfExists('$tableName');\n";
        $migrationContent .= "    }\n";
        $migrationContent .= "};\n";

        $fileName = date('YmdHis') . "_create_{$tableName}_table.php";
        $filePath = database_path("migrations/{$fileName}");
        if (file_exists($filePath) && !$this->option('force')) {
            $this->warn("Migration for $tableName already exists. Use --force to overwrite.");
            return;
        }

        file_put_contents($filePath, $migrationContent);
        $this->info("Migration for $tableName created.");
    }

    private function generateForeignKeyMigration($tableName, $foreignKeys)
    {
        // Generate migration content with Anonymous Class
        $migrationContent = "<?php\n\n";
        $migrationContent .= "use Illuminate\Database\Migrations\Migration;\n";
        $migrationContent .= "use Illuminate\Database\Schema\Blueprint;\n";
        $migrationContent .= "use Illuminate\Support\Facades\Schema;\n\n";
        $migrationContent .= "return new class extends Migration\n";
        $migrationContent .= "{\n";
        $migrationContent .= "    public function up()\n";
        $migrationContent .= "    {\n";
        $migrationContent .= "        Schema::table('$tableName', function (Blueprint \$table) {\n";

        // Add foreign keys using foreignId
        foreach ($foreignKeys as $foreignKey) {
            $migrationContent .= "            \$table->foreignId('{$foreignKey['column']}')->nullable();\n";
        }

        $migrationContent .= "        });\n";
        $migrationContent .= "    }\n\n";
        $migrationContent .= "    public function down()\n";
        $migrationContent .= "    {\n";
        $migrationContent .= "        Schema::table('$tableName', function (Blueprint \$table) {\n";

        // Drop foreign keys
        foreach ($foreignKeys as $foreignKey) {
            $migrationContent .= "            \$table->dropColumn('{$foreignKey['column']}');\n";
        }

        $migrationContent .= "        });\n";
        $migrationContent .= "    }\n";
        $migrationContent .= "};\n";

        $fileName = date('YmdHis', strtotime('+1 second')) . "_add_foreign_keys_to_{$tableName}_table.php";
        $filePath = database_path("migrations/{$fileName}");
        if (file_exists($filePath) && !$this->option('force')) {
            $this->warn("Foreign key migration for $tableName already exists. Use --force to overwrite.");
            return;
        }

        file_put_contents($filePath, $migrationContent);
        $this->info("Foreign key migration for $tableName created.");
    }

    private function generateSeeder($tableName)
    {
        $rows = DB::table($tableName)->get();

        // Generate seeder content
        $seederContent = "<?php\n\n";
        $seederContent .= "use Illuminate\Database\Seeder;\n";
        $seederContent .= "use Illuminate\Support\Facades\DB;\n\n";
        $seederContent .= "class {$tableName}Seeder extends Seeder\n";
        $seederContent .= "{\n";
        $seederContent .= "    public function run()\n";
        $seederContent .= "    {\n";
        $seederContent .= "        DB::table('$tableName')->truncate();\n";
        foreach ($rows as $row) {
            $values = [];
            foreach ($row as $key => $value) {
                $values[] = "'$key' => '" . addslashes($value) . "'";
            }
            $seederContent .= "        DB::table('$tableName')->insert([" . implode(', ', $values) . "]);\n";
        }
        $seederContent .= "    }\n";
        $seederContent .= "}";

        $fileName = "{$tableName}Seeder.php";
        $filePath = database_path("seeders/{$fileName}");
        if (file_exists($filePath) && !$this->option('force')) {
            $this->warn("Seeder for $tableName already exists. Use --force to overwrite.");
            return;
        }

        file_put_contents($filePath, $seederContent);
        $this->info("Seeder for $tableName created.");
    }

    private function convertColumnType($type)
    {
        // Extract type and length (if any)
        preg_match('/^(\w+)(\((.*)\))?/', $type, $matches);
        $baseType = $matches[1] ?? '';
        $length = $matches[3] ?? '';

        // Map MySQL types to Laravel types
        $mapping = [
            'int' => 'integer',
            'tinyint' => 'boolean',
            'smallint' => 'integer',
            'mediumint' => 'integer',
            'bigint' => 'bigInteger',
            'varchar' => 'string',
            'char' => 'string',
            'text' => 'text',
            'mediumtext' => 'text',
            'longtext' => 'text',
            'date' => 'date',
            'datetime' => 'dateTime',
            'timestamp' => 'timestamp',
            'decimal' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'enum' => 'string', // Convert ENUM to string
        ];

        return $mapping[$baseType] ?? 'string';
    }

    private function getPrimaryKey($tableName)
    {
        $result = DB::select("SHOW KEYS FROM $tableName WHERE Key_name = 'PRIMARY'");
        return $result ? $result[0]->Column_name : null;
    }

    private function getForeignKeys($tableName)
    {
        $result = DB::select("
            SELECT
                COLUMN_NAME AS `column`,
                REFERENCED_TABLE_NAME AS `on_table`,
                REFERENCED_COLUMN_NAME AS `references`
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = '$tableName' AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        $foreignKeys = [];
        foreach ($result as $row) {
            $foreignKeys[] = [
                'column' => $row->column,
                'on_table' => $row->on_table,
                'references' => $row->references,
            ];
        }

        return $foreignKeys;
    }
}
