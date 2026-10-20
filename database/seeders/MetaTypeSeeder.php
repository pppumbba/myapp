<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MetaTypeSeeder extends Seeder
{
    /**
     * The kinds of screen a meta can describe. The frontend draws a preview for each code.
     */
    public function run(): void
    {
        $types = [
            ['code' => 'app', 'name' => 'App', 'description' => 'An application with a menu that links to its screens.'],
            ['code' => 'datagrid', 'name' => 'Data grid', 'description' => 'A table of records with columns, sorting and filters.'],
            ['code' => 'form', 'name' => 'Form', 'description' => 'Fields for creating or editing one record.'],
            ['code' => 'calendar', 'name' => 'Calendar', 'description' => 'Records shown as events on a calendar.'],
            ['code' => 'gantt', 'name' => 'Gantt chart', 'description' => 'Records shown as bars on a timeline, like project tasks.'],
            ['code' => 'tabs', 'name' => 'Tabs', 'description' => 'Several screens grouped under tabs.'],
            ['code' => 'get', 'name' => 'Get', 'description' => 'A query that decides the values a form opens with.'],
            ['code' => 'workflow', 'name' => 'Workflow', 'description' => 'Statuses and the moves between them, for the records of a table.'],
        ];

        // meta_types has no timestamps, so upsert through the query builder. Safe to run again.
        DB::table('meta_types')->upsert(
            array_map(fn ($t) => $t + ['is_active' => true], $types),
            ['code'],
            ['name', 'description', 'is_active'],
        );
    }
}
