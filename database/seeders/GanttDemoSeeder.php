<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use Database\Seeders\Concerns\SeedsActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A Projects demo app built entirely from metas:
 *
 *   Projects (app)
 *   ├─ Timeline   -> gantt chart, grouped by project (opens the task form)
 *   ├─ Tasks      -> datagrid (opens the task form)
 *   └─ Projects   -> datagrid (opens the project form)
 *
 * The gantt meta's json_config names which fields hold the dates, title, progress, group
 * and colour. Sample tasks are placed around the week the seeder runs. Safe to run again:
 * metas are matched by code and their params rebuilt; sample records are only added to
 * empty tables.
 */
class GanttDemoSeeder extends Seeder
{
    use SeedsActions;

    private const PROJECT_STATUSES = ['planning', 'active', 'on hold', 'done'];
    private const TASK_STATUSES = ['planned', 'in progress', 'blocked', 'done'];

    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();

        DB::transaction(function () {
            $this->sampleData();
            $this->metas();
        });
    }

    private function metas(): void
    {
        $projectForm = $this->meta('pm_project_form', 'Project form', 'form', 'pm_projects', 'Add or edit a project.');
        $this->params($projectForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true]],
            ['owner', 'Owner', 'string', ['is_criteria' => true]],
            ['status', 'Status', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'planning', 'json_config' => ['options' => self::PROJECT_STATUSES]]],
        ]);

        $projectGrid = $this->meta('pm_projects_grid', 'Projects', 'datagrid', 'pm_projects', 'What the team is working on.');
        $this->actions($projectGrid, $projectForm);
        $this->params($projectGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Project', 'string', ['is_criteria' => true]],
            ['owner', 'Owner', 'string', ['is_criteria' => true]],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true]],
        ]);

        $project = ['is_select' => true, 'ref_meta_id' => $projectGrid->id, 'render_type' => 'lookup', 'json_config' => ['label_field' => 'name']];

        $taskForm = $this->meta('pm_task_form', 'Task form', 'form', 'pm_tasks', 'Plan or update a task.');
        $this->params($taskForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Task', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['project_id', 'Project', 'integer', ['is_required' => true] + $project],
            ['assignee', 'Assignee', 'string', ['is_criteria' => true]],
            ['status', 'Status', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'planned', 'json_config' => ['options' => self::TASK_STATUSES]]],
            ['start_date', 'Start', 'date', ['is_required' => true]],
            ['end_date', 'Finish', 'date', ['is_required' => true]],
            ['progress', 'Progress (%)', 'integer', ['is_required' => true, 'min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $taskGrid = $this->meta('pm_tasks_grid', 'Tasks', 'datagrid', 'pm_tasks', 'Every task across projects.');
        $this->actions($taskGrid, $taskForm);
        $this->params($taskGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Task', 'string', ['is_criteria' => true]],
            ['project_id', 'Project', 'integer', $project],
            ['assignee', 'Assignee', 'string', ['is_criteria' => true]],
            ['start_date', 'Start', 'date'],
            ['end_date', 'Finish', 'date'],
            ['progress', 'Progress (%)', 'integer'],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true]],
        ]);

        $timeline = $this->meta('pm_timeline', 'Timeline', 'gantt', 'pm_tasks', 'Tasks by project over the coming weeks.', $taskForm, [
            'start_field' => 'start_date',
            'end_field' => 'end_date',
            'title_field' => 'name',
            'progress_field' => 'progress',
            'group_field' => 'project_id',
            'detail_field' => 'assignee',
            'color_field' => 'status',
            'colors' => ['planned' => 'gray', 'in progress' => 'blue', 'blocked' => 'red', 'done' => 'green'],
        ]);
        $this->params($timeline, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Task', 'string', ['is_criteria' => true]],
            ['project_id', 'Project', 'integer', $project],
            ['assignee', 'Assignee', 'string'],
            ['start_date', 'Start', 'date'],
            ['end_date', 'Finish', 'date'],
            ['progress', 'Progress', 'integer'],
            ['status', 'Status', 'string'],
        ]);

        $app = $this->meta('pm_app', 'Projects', 'app', null, 'Project plans, tasks and progress.');
        $this->params($app, [
            ['timeline', 'Timeline', 'string', ['icon_cls' => 'chart', 'ref_meta_id' => $timeline->id]],
            ['tasks', 'Tasks', 'string', ['icon_cls' => 'file', 'ref_meta_id' => $taskGrid->id]],
            ['projects', 'Projects', 'string', ['icon_cls' => 'folder', 'ref_meta_id' => $projectGrid->id]],
        ]);
    }

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?array $config = null): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'description' => $description,
            'ref_meta_id' => $opens?->id,
            'json_config' => $config,
            'is_active' => true,
        ]);
    }

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes]. */
    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();

        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            MetaParam::create([
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
            ] + ($row[3] ?? []));
        }
    }

    private function sampleData(): void
    {
        $now = now();

        if (! DB::table('pm_projects')->exists()) {
            DB::table('pm_projects')->insert(array_map(fn ($p) => $p + ['created_at' => $now, 'updated_at' => $now], [
                ['name' => 'Website relaunch', 'owner' => 'Anu', 'status' => 'active'],
                ['name' => 'Mobile app 2.0', 'owner' => 'Bat-Erdene', 'status' => 'active'],
                ['name' => 'New office move', 'owner' => 'Munkh', 'status' => 'planning'],
            ]));
        }

        if (DB::table('pm_tasks')->exists()) {
            return;
        }

        $project = DB::table('pm_projects')->pluck('id', 'name');
        // Day offsets from this week's Monday.
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
        $day = fn (int $n) => $monday->copy()->addDays($n)->toDateString();

        $tasks = [
            ['Website relaunch', 'Content audit', 'Enkhjin', -14, -5, 100, 'done'],
            ['Website relaunch', 'Wireframes', 'Anu', -7, 2, 80, 'in progress'],
            ['Website relaunch', 'Visual design', 'Anu', 0, 11, 25, 'in progress'],
            ['Website relaunch', 'Frontend build', 'Saraa', 7, 32, 0, 'planned'],
            ['Website relaunch', 'Copywriting', 'Enkhjin', 3, 16, 10, 'blocked'],
            ['Website relaunch', 'Launch', 'Anu', 35, 35, 0, 'planned'],
            ['Mobile app 2.0', 'User research', 'Oyunaa', -10, -1, 100, 'done'],
            ['Mobile app 2.0', 'API changes', 'Bat-Erdene', -3, 18, 40, 'in progress'],
            ['Mobile app 2.0', 'Offline mode', 'Tuvshin', 10, 38, 0, 'planned'],
            ['Mobile app 2.0', 'Beta testing', 'Oyunaa', 39, 52, 0, 'planned'],
            ['New office move', 'Find a location', 'Munkh', -4, 9, 60, 'in progress'],
            ['New office move', 'Sign the lease', 'Ganbold', 12, 15, 0, 'planned'],
            ['New office move', 'Fit-out and furniture', 'Munkh', 18, 46, 0, 'planned'],
            ['New office move', 'Moving day', 'Munkh', 49, 50, 0, 'planned'],
        ];

        DB::table('pm_tasks')->insert(array_map(fn ($t) => [
            'project_id' => $project[$t[0]],
            'name' => $t[1],
            'assignee' => $t[2],
            'start_date' => $day($t[3]),
            'end_date' => $day($t[4]),
            'progress' => $t[5],
            'status' => $t[6],
            'created_at' => $now,
            'updated_at' => $now,
        ], $tasks));
    }
}
