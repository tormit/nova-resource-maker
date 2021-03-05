<?php

namespace Inani\NovaResourceMaker\Commands;

use Inani\NovaResourceMaker\Helpers\CanGenerateRelationShips;
use Inani\NovaResourceMaker\Helpers\FieldsBuilder;
use Inani\NovaResourceMaker\Helpers\Querable;
use Inani\NovaResourceMaker\Helpers\Tagable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;
use ReflectionClass;

class MakeNovaResource extends Command
{
    use Tagable,
        Querable,
        CanGenerateRelationShips;

    protected $fields = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nova-resource-fields:generate {model?} {--ignoreTimestamps=1}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the array of fields';

    protected $builder;

    protected static $ignoredTimestamps = [
        'created_at',
        'updated_at',
    ];

    /**
     * Create a new command instance.
     * @param FieldsBuilder $builder
     */
    public function __construct(FieldsBuilder $builder)
    {
        parent::__construct();
        $this->builder = $builder;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if ($this->hasArgument('model')) {
            return $this->autoGenerate($this->argument('model'));
        }

        $model = $this->ask("What is the name of the Model?");
        $this->checkModelAndFindFields($model);

        do {
            $selected = $this->choice(
                "Select the field to include",
                $this->getExistingFields()
            );
            $this->workOnTheCurrentField($selected, $this->popElement($selected));
        } while ($this->confirm('Do you wish to continue?') && count($this->fields) > 0);

        $this->buildRelationShips($model);
        $this->build();
    }

    /**
     * Check if the Model exists
     *
     * @param $model
     * @return bool
     */
    private function checkIfModelExists($model)
    {
        if (!class_exists($model)) {
            return false;
        }

        $class = new ReflectionClass($model);
        if (!$class->isSubclassOf(Model::class)) {
            return false;
        }

        return true;
    }


    public function workOnTheCurrentField($name, $column)
    {
        if ($this->getOptionsByType($column['type']) != null) {
            // Get the field Type
            $option_key = $this->choice(
                "These are the options for the {$name} ({$column['type']})?",
                $this->getOptionsByType($column['type'])
            );
        }
        if (!isset($option_key)) {
            $option_key = $column['type'];
        }
        $this->builder->add($name, $option_key);

        // Get rules
        if ($this->confirm("Do you want to attach rules?")) {
            $rules = $this->ask("Type the name of the rule separated by a |");
            $this->builder->addRules($rules);
        }
        // Get visibility
        if ($this->confirm("Is there any special exception on the visibility of the field?")) {

            list($headers, $available_methods) = $this->builder->drawAvailableRules();
            $this->table($headers, $available_methods);
            do {
                $methods = $this->ask("Type the name of the rule separated by a |");

            } while ($this->builder->addMethods($methods) == false);
        }

        // Sortable
        if ($this->confirm("Is it sortable?")) {
            $this->builder->sortable();
        }
    }

    public function workOnTheFieldAuto(string $name, array $column): void
    {
        if ($this->option('ignoreTimestamps') == 1 && in_array($name, self::$ignoredTimestamps)) {
            return;
        }
        $columnType = $column['type'];
        $columnType = preg_replace('/[0-9(),]*/i', '', $columnType);
        $type = \Illuminate\Support\Arr::first($this->getOptionsByType($columnType));
        if (!$type) {
            return;
        }
        $this->builder->add($name, $type);

        // Get rules
        $rules = [];
        if ($column['nullable'] === 'NO') {
            $rules[] = 'required';
        }
        if ($columnType === 'varchar') {
            $rules[] = 'max:191';
        }

        $this->builder->addRules(implode('|', $rules));
    }

    /**
     *  Build the fields array
     */
    public function build()
    {
        $this->builder->build();
    }

    private function autoGenerate(string $model): int
    {
        $this->checkModelAndFindFields($model);
        foreach ($this->getExistingFields() as $field) {
            $this->workOnTheFieldAuto($field, $this->popElement($field));
        }

        $this->buildRelationShips($model);
        $this->build();

        return 0;
    }

    private function checkModelAndFindFields(string &$model): void
    {
        // check if model created as laravel default Models
        // if true set Fullname space
        if (file_exists(app_path($model . '.php'))) {
            $model = '\\App\\' . $model;
        }

        if (!$this->checkIfModelExists($model)) {
            throw new \Exception("Model {$model} doesn't exist!");
        }


        // Get all fields and reverse the order
        $this->fields = array_reverse(
            $this->tagThem(
                $this->getColumnList(new $model())
            )
        );


        $this->fields = array_merge($this->fields, $this->buildRelationShips($model));

        if (count($this->fields) == 0) {
            throw new \Exception("No Columns found for the model {$model}");
        }
    }
}
