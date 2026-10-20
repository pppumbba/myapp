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
 * A Calendar demo app built entirely from metas:
 *
 *   Calendar (app)
 *   ├─ Team calendar  -> calendar (opens the event form)
 *   ├─ Events         -> datagrid (opens the event form)
 *   └─ Rooms          -> datagrid (opens the room form)
 *
 * The calendar meta's json_config names which fields hold the start, end, title and colour.
 * Sample events are placed around the week the seeder runs, so the calendar is never empty.
 * Safe to run again: metas are matched by code and their params rebuilt; sample records are
 * only added to empty tables.
 */
class CalendarDemoSeeder extends Seeder
{
    use SeedsActions;

    private const CATEGORIES = ['meeting', 'deadline', 'training', 'travel', 'holiday'];

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
        $roomForm = $this->meta('cal_room_form', 'Room form', 'form', 'cal_rooms', 'Add or edit a meeting room.');
        $this->params($roomForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true]],
            ['floor', 'Floor', 'string'],
            ['capacity', 'Seats', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '4']],
            ['is_active', 'Bookable', 'boolean', ['default_value' => '1']],
        ]);

        $roomGrid = $this->meta('cal_rooms_grid', 'Rooms', 'datagrid', 'cal_rooms', 'Meeting rooms events can be booked in.');
        $this->actions($roomGrid, $roomForm);
        $this->params($roomGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Room', 'string', ['is_criteria' => true]],
            ['floor', 'Floor', 'string', ['is_criteria' => true]],
            ['capacity', 'Seats', 'integer'],
            ['is_active', 'Status', 'boolean', ['render_type' => 'active']],
        ]);

        $room = ['is_select' => true, 'ref_meta_id' => $roomGrid->id, 'render_type' => 'lookup', 'json_config' => ['label_field' => 'name']];

        $eventForm = $this->meta('cal_event_form', 'Event form', 'form', 'cal_events', 'Plan or change an event.');
        $this->params($eventForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['title', 'Title', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['category', 'Category', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'meeting', 'json_config' => ['options' => self::CATEGORIES]]],
            ['starts_at', 'Starts', 'datetime', ['is_required' => true]],
            ['ends_at', 'Ends', 'datetime'],
            ['room_id', 'Room', 'integer', $room],
            ['organizer', 'Organizer', 'string', ['is_criteria' => true]],
            ['notes', 'Notes', 'text', ['render_type' => 'textarea']],
        ]);

        $eventGrid = $this->meta('cal_events_grid', 'Events', 'datagrid', 'cal_events', 'Every event, newest first.');
        $this->actions($eventGrid, $eventForm);
        $this->params($eventGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['title', 'Title', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['render_type' => 'status', 'is_criteria' => true]],
            ['starts_at', 'Starts', 'datetime'],
            ['ends_at', 'Ends', 'datetime'],
            ['room_id', 'Room', 'integer', $room],
            ['organizer', 'Organizer', 'string', ['is_criteria' => true]],
        ]);

        $calendar = $this->meta('cal_calendar', 'Team calendar', 'calendar', 'cal_events', 'Meetings, deadlines and time off.', $eventForm, [
            'start_field' => 'starts_at',
            'end_field' => 'ends_at',
            'title_field' => 'title',
            'color_field' => 'category',
            'colors' => ['meeting' => 'blue', 'deadline' => 'red', 'training' => 'purple', 'travel' => 'amber', 'holiday' => 'green'],
        ]);
        $this->params($calendar, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['title', 'Title', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['starts_at', 'Starts', 'datetime'],
            ['ends_at', 'Ends', 'datetime'],
            ['room_id', 'Room', 'integer', $room],
        ]);

        $app = $this->meta('cal_app', 'Calendar', 'app', null, 'Team events, meetings and rooms.');
        $this->params($app, [
            ['team_calendar', 'Team calendar', 'string', ['icon_cls' => 'calendar', 'ref_meta_id' => $calendar->id]],
            ['events', 'Events', 'string', ['icon_cls' => 'file', 'ref_meta_id' => $eventGrid->id]],
            ['rooms', 'Rooms', 'string', ['icon_cls' => 'home', 'ref_meta_id' => $roomGrid->id]],
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

        if (! DB::table('cal_rooms')->exists()) {
            DB::table('cal_rooms')->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], [
                ['name' => 'Khangai', 'floor' => '3', 'capacity' => 12, 'is_active' => true],
                ['name' => 'Altai', 'floor' => '3', 'capacity' => 6, 'is_active' => true],
                ['name' => 'Kherlen', 'floor' => '2', 'capacity' => 4, 'is_active' => true],
                ['name' => 'Terelj', 'floor' => '1', 'capacity' => 30, 'is_active' => true],
                ['name' => 'Orkhon', 'floor' => '2', 'capacity' => 8, 'is_active' => false],
            ]));
        }

        if (DB::table('cal_events')->exists()) {
            return;
        }

        $room = DB::table('cal_rooms')->pluck('id', 'name');
        // Day offsets from this week's Monday; times are local.
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
        $at = fn (int $day, string $time) => $monday->copy()->addDays($day)->setTimeFromTimeString($time)->toDateTimeString();

        $events = [
            ['Weekly planning', 'meeting', $at(0, '09:30'), $at(0, '10:30'), 'Khangai', 'Saraa', 'Priorities for the week.'],
            ['Design review', 'meeting', $at(1, '14:00'), $at(1, '15:00'), 'Altai', 'Anu', null],
            ['Q4 budget due', 'deadline', $at(2, '17:00'), null, null, 'Ganbold', 'Send to finance before end of day.'],
            ['1:1 Bat-Erdene', 'meeting', $at(2, '11:00'), $at(2, '11:30'), 'Kherlen', 'Saraa', null],
            ['Security training', 'training', $at(3, '10:00'), $at(3, '12:00'), 'Terelj', 'IT team', 'Mandatory for new hires.'],
            ['Customer visit, Darkhan', 'travel', $at(4, '08:00'), $at(5, '18:00'), null, 'Munkh', 'Two-day trip.'],
            ['Weekly planning', 'meeting', $at(7, '09:30'), $at(7, '10:30'), 'Khangai', 'Saraa', null],
            ['Sprint demo', 'meeting', $at(8, '16:00'), $at(8, '17:00'), 'Terelj', 'Anu', 'Everyone welcome.'],
            ['Release 2.4', 'deadline', $at(9, '12:00'), null, null, 'Enkhjin', null],
            ['Excel for finance', 'training', $at(10, '13:00'), $at(10, '16:00'), 'Altai', 'Ganbold', null],
            ['Oyunaa off', 'holiday', $at(11, '00:00'), $at(15, '23:59'), null, 'Oyunaa', 'Back on Wednesday.'],
            ['Board meeting', 'meeting', $at(-3, '10:00'), $at(-3, '12:00'), 'Khangai', 'CEO office', null],
            ['Supplier call', 'meeting', $at(-5, '15:00'), $at(-5, '15:30'), 'Kherlen', 'Enkhjin', null],
            ['Tax filing', 'deadline', $at(-8, '17:00'), null, null, 'Ganbold', null],
            ['Weekly planning', 'meeting', $at(14, '09:30'), $at(14, '10:30'), 'Khangai', 'Saraa', null],
            ['Onboarding day', 'training', $at(16, '09:00'), $at(16, '17:00'), 'Terelj', 'HR', 'Three new hires start.'],
            ['Trip to Beijing', 'travel', $at(18, '07:00'), $at(20, '20:00'), null, 'Bat-Erdene', 'Northwind Electronics visit.'],
            ['Year-end party', 'holiday', $at(25, '18:00'), $at(25, '23:00'), 'Terelj', 'HR', null],
        ];

        DB::table('cal_events')->insert(array_map(fn ($e) => [
            'title' => $e[0],
            'category' => $e[1],
            'starts_at' => $e[2],
            'ends_at' => $e[3],
            'room_id' => $e[4] ? $room[$e[4]] : null,
            'organizer' => $e[5],
            'notes' => $e[6],
            'created_at' => $now,
            'updated_at' => $now,
        ], $events));
    }
}
