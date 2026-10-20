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
 * A Learning (LMS) demo app built entirely from metas:
 *
 *   Learning (app)
 *   ├─ Catalog (menu group)
 *   │  ├─ Courses          -> datagrid (opens the course form, lessons as its details)
 *   │  └─ Course timeline  -> gantt chart of course runs, grouped by category
 *   ├─ Class schedule      -> calendar of lectures, workshops and exams
 *   ├─ Enrollments (menu group)
 *   │  ├─ Enroll a student -> enrollment form
 *   │  └─ All enrollments  -> datagrid with progress and grades
 *   └─ People              -> tabs: Students grid, Instructors grid
 *
 * Course dates and sessions are placed around the week the seeder runs. Safe to run again:
 * metas are matched by code and their params rebuilt; sample records are only added to
 * empty tables.
 */
class LmsDemoSeeder extends Seeder
{
    use SeedsActions;

    private const CATEGORIES = ['Programming', 'Data', 'Design', 'Business', 'Languages'];
    private const LEVELS = ['beginner', 'intermediate', 'advanced'];
    private const COURSE_STATUSES = ['draft', 'published', 'in progress', 'completed', 'archived'];
    private const LESSON_KINDS = ['video', 'reading', 'quiz', 'assignment', 'live'];
    private const ENROLLMENT_STATUSES = ['enrolled', 'in progress', 'completed', 'dropped'];
    private const SESSION_KINDS = ['lecture', 'workshop', 'exam', 'office hours'];
    private const DEPARTMENTS = ['Engineering', 'Finance', 'Marketing', 'Sales', 'HR', 'Operations'];

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
        // People first: courses, enrollments and sessions look them up.
        $instructorForm = $this->meta('lms_instructor_form', 'Instructor form', 'form', 'lms_instructors', 'Add or edit an instructor.');
        $this->params($instructorForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['email', 'Email', 'string', ['render_type' => 'email']],
            ['expertise', 'Expertise', 'string', ['is_criteria' => true]],
            ['is_active', 'Teaching', 'boolean', ['default_value' => '1']],
        ]);

        $instructorGrid = $this->meta('lms_instructors_grid', 'Instructors', 'datagrid', 'lms_instructors', 'The people who teach our courses.');
        $this->actions($instructorGrid, $instructorForm);
        $this->params($instructorGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_criteria' => true]],
            ['email', 'Email', 'string'],
            ['expertise', 'Expertise', 'string', ['is_criteria' => true]],
            ['is_active', 'Status', 'boolean', ['render_type' => 'active']],
        ]);

        $studentForm = $this->meta('lms_student_form', 'Student form', 'form', 'lms_students', 'Add or edit a student.');
        $this->params($studentForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['email', 'Email', 'string', ['render_type' => 'email', 'is_criteria' => true]],
            ['department', 'Department', 'string', ['render_type' => 'select', 'json_config' => ['options' => self::DEPARTMENTS]]],
            ['joined_on', 'Joined', 'date'],
            ['is_active', 'Active', 'boolean', ['default_value' => '1']],
        ]);

        $studentGrid = $this->meta('lms_students_grid', 'Students', 'datagrid', 'lms_students', 'Everyone who can enroll in a course.');
        $this->actions($studentGrid, $studentForm);
        $this->params($studentGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['name', 'Name', 'string', ['is_criteria' => true]],
            ['email', 'Email', 'string', ['is_criteria' => true]],
            ['department', 'Department', 'string', ['is_criteria' => true]],
            ['joined_on', 'Joined', 'date'],
            ['is_active', 'Status', 'boolean', ['render_type' => 'active']],
        ]);

        $people = $this->meta('lms_people', 'People', 'tabs', null, 'Students and instructors in one place.');
        $this->params($people, [
            ['students', 'Students', 'string', ['ref_meta_id' => $studentGrid->id]],
            ['instructors', 'Instructors', 'string', ['ref_meta_id' => $instructorGrid->id]],
        ]);

        $instructor = ['is_select' => true, 'ref_meta_id' => $instructorGrid->id, 'render_type' => 'lookup', 'json_config' => ['label_field' => 'name']];
        $student = ['is_select' => true, 'ref_meta_id' => $studentGrid->id, 'render_type' => 'lookup', 'json_config' => ['label_field' => 'name']];

        // A course's lessons are edited as lines on the course form.
        $lessonForm = $this->meta('lms_lesson_form', 'Lesson', 'form', 'lms_lessons', 'One lesson in a course.');
        $this->params($lessonForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['course_id', 'Course', 'integer', ['is_show' => false]],
            ['title', 'Lesson', 'string', ['is_required' => true]],
            ['kind', 'Type', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'video', 'json_config' => ['options' => self::LESSON_KINDS]]],
            ['duration_minutes', 'Minutes', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '30']],
        ]);

        $courseForm = $this->meta('lms_course_form', 'Course form', 'form', 'lms_courses', 'Describe a course and plan its lessons.');
        $this->params($courseForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['code', 'Course code', 'string', ['is_required' => true, 'is_unique' => true, 'is_criteria' => true]],
            ['title', 'Title', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['category', 'Category', 'string', ['render_type' => 'select', 'json_config' => ['options' => self::CATEGORIES]]],
            ['level', 'Level', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'beginner', 'json_config' => ['options' => self::LEVELS]]],
            ['instructor_id', 'Instructor', 'integer', $instructor],
            ['status', 'Status', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'draft', 'json_config' => ['options' => self::COURSE_STATUSES]]],
            ['start_date', 'Starts', 'date'],
            ['end_date', 'Ends', 'date'],
            ['capacity', 'Seats', 'integer', ['is_required' => true, 'min_value' => '1', 'default_value' => '20']],
            ['description', 'Description', 'text', ['render_type' => 'textarea']],
            ['lessons', 'Lessons', 'detail', ['ref_meta_id' => $lessonForm->id, 'json_config' => ['foreign_key' => 'course_id']]],
        ]);

        $courseGrid = $this->meta('lms_courses_grid', 'Courses', 'datagrid', 'lms_courses', 'Every course in the catalog.');
        $this->actions($courseGrid, $courseForm);
        $this->params($courseGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['title', 'Course', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string', ['is_criteria' => true]],
            ['level', 'Level', 'string', ['is_criteria' => true]],
            ['instructor_id', 'Instructor', 'integer', $instructor],
            ['start_date', 'Starts', 'date'],
            ['capacity', 'Seats', 'integer'],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true]],
        ]);

        $course = ['is_select' => true, 'ref_meta_id' => $courseGrid->id, 'render_type' => 'lookup', 'json_config' => ['label_field' => 'title']];

        $timeline = $this->meta('lms_course_timeline', 'Course timeline', 'gantt', 'lms_courses', 'When each course runs, by category.', $courseForm, [
            'start_field' => 'start_date',
            'end_field' => 'end_date',
            'title_field' => 'title',
            'group_field' => 'category',
            'detail_field' => 'level',
            'item_label' => 'course',
            'color_field' => 'status',
            'colors' => ['draft' => 'gray', 'published' => 'purple', 'in progress' => 'blue', 'completed' => 'green', 'archived' => 'gray'],
        ]);
        $this->params($timeline, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['title', 'Course', 'string', ['is_criteria' => true]],
            ['category', 'Category', 'string'],
            ['level', 'Level', 'string'],
            ['start_date', 'Starts', 'date'],
            ['end_date', 'Ends', 'date'],
            ['status', 'Status', 'string'],
        ]);

        $sessionForm = $this->meta('lms_session_form', 'Class session form', 'form', 'lms_sessions', 'Schedule a lecture, workshop or exam.');
        $this->params($sessionForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['title', 'Title', 'string', ['is_required' => true, 'is_criteria' => true]],
            ['course_id', 'Course', 'integer', ['is_required' => true] + $course],
            ['kind', 'Type', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'lecture', 'json_config' => ['options' => self::SESSION_KINDS]]],
            ['starts_at', 'Starts', 'datetime', ['is_required' => true]],
            ['ends_at', 'Ends', 'datetime'],
            ['location', 'Location', 'string'],
        ]);

        $schedule = $this->meta('lms_schedule', 'Class schedule', 'calendar', 'lms_sessions', 'Lectures, workshops and exams.', $sessionForm, [
            'start_field' => 'starts_at',
            'end_field' => 'ends_at',
            'title_field' => 'title',
            'item_label' => 'class',
            'color_field' => 'kind',
            'colors' => ['lecture' => 'blue', 'workshop' => 'purple', 'exam' => 'red', 'office hours' => 'green'],
        ]);
        $this->params($schedule, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['title', 'Title', 'string', ['is_criteria' => true]],
            ['course_id', 'Course', 'integer', $course],
            ['kind', 'Type', 'string'],
            ['starts_at', 'Starts', 'datetime'],
            ['ends_at', 'Ends', 'datetime'],
        ]);

        $enrollmentForm = $this->meta('lms_enrollment_form', 'Enrollment form', 'form', 'lms_enrollments', 'Sign a student up for a course, or update how they are doing.');
        $this->params($enrollmentForm, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['student_id', 'Student', 'integer', ['is_required' => true] + $student],
            ['course_id', 'Course', 'integer', ['is_required' => true] + $course],
            ['enrolled_on', 'Enrolled on', 'date', ['is_required' => true]],
            ['status', 'Status', 'string', ['is_required' => true, 'render_type' => 'select', 'default_value' => 'enrolled', 'json_config' => ['options' => self::ENROLLMENT_STATUSES]]],
            ['progress', 'Progress (%)', 'integer', ['is_required' => true, 'min_value' => '0', 'max_value' => '100', 'default_value' => '0']],
            ['grade', 'Grade', 'decimal', ['min_value' => '0', 'max_value' => '100']],
        ]);

        $enrollmentGrid = $this->meta('lms_enrollments_grid', 'Enrollments', 'datagrid', 'lms_enrollments', 'Who is taking what, and how far along they are.');
        $this->actions($enrollmentGrid, $enrollmentForm);
        $this->params($enrollmentGrid, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['student_id', 'Student', 'integer', $student],
            ['course_id', 'Course', 'integer', $course],
            ['enrolled_on', 'Enrolled', 'date'],
            ['progress', 'Progress (%)', 'integer'],
            ['grade', 'Grade', 'decimal'],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true]],
        ]);

        $app = $this->meta('lms_app', 'Learning', 'app', null, 'Courses, classes, students and progress.');
        $this->params($app, [
            ['catalog', 'Catalog', 'string', ['icon_cls' => 'folder', 'children' => [
                ['courses', 'Courses', 'string', ['ref_meta_id' => $courseGrid->id]],
                ['course_timeline', 'Course timeline', 'string', ['ref_meta_id' => $timeline->id]],
            ]]],
            ['class_schedule', 'Class schedule', 'string', ['icon_cls' => 'calendar', 'ref_meta_id' => $schedule->id]],
            ['enrollments', 'Enrollments', 'string', ['icon_cls' => 'file', 'children' => [
                ['enroll', 'Enroll a student', 'string', ['ref_meta_id' => $enrollmentForm->id]],
                ['all_enrollments', 'All enrollments', 'string', ['ref_meta_id' => $enrollmentGrid->id]],
            ]]],
            ['people', 'People', 'string', ['icon_cls' => 'users', 'ref_meta_id' => $people->id]],
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

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes (children nest)]. */
    private function params(Meta $meta, array $rows, ?int $parentId = null): void
    {
        if ($parentId === null) {
            MetaParam::where('main_meta_id', $meta->id)->delete();
        }

        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            $extra = $row[3] ?? [];
            $children = $extra['children'] ?? [];
            unset($extra['children']);

            $param = MetaParam::create([
                'main_meta_id' => $meta->id,
                'parent_id' => $parentId,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
            ] + $extra);

            if ($children) {
                $this->params($meta, $children, $param->id);
            }
        }
    }

    private function sampleData(): void
    {
        $now = now();
        $stamp = fn (array $rows) => array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $rows);
        // Dates are day offsets from this week's Monday.
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
        $day = fn (int $n) => $monday->copy()->addDays($n)->toDateString();
        $at = fn (int $n, string $time) => $monday->copy()->addDays($n)->setTimeFromTimeString($time)->toDateTimeString();

        if (! DB::table('lms_instructors')->exists()) {
            DB::table('lms_instructors')->insert($stamp([
                ['name' => 'Saraa Batbold', 'email' => 'saraa@learning.example', 'expertise' => 'Web development', 'is_active' => true],
                ['name' => 'Ganbold Erdene', 'email' => 'ganbold@learning.example', 'expertise' => 'Data analysis', 'is_active' => true],
                ['name' => 'Anu Tsogt', 'email' => 'anu@learning.example', 'expertise' => 'Product design', 'is_active' => true],
                ['name' => 'Emma Clarke', 'email' => 'emma@learning.example', 'expertise' => 'English', 'is_active' => true],
                ['name' => 'Munkh Dorj', 'email' => 'munkh@learning.example', 'expertise' => 'Leadership', 'is_active' => false],
            ]));
        }

        if (! DB::table('lms_students')->exists()) {
            $names = [
                ['Bat-Erdene', 'Engineering'], ['Oyunaa', 'Finance'], ['Tuvshin', 'Engineering'], ['Enkhjin', 'Marketing'],
                ['Nomin', 'Sales'], ['Temuulen', 'Operations'], ['Khulan', 'HR'], ['Bilguun', 'Engineering'],
                ['Solongo', 'Marketing'], ['Erdenebat', 'Finance'], ['Ariunaa', 'Sales'], ['Dulguun', 'Operations'],
            ];
            DB::table('lms_students')->insert($stamp(array_map(fn ($s, $i) => [
                'name' => $s[0],
                'email' => strtolower(str_replace('-', '', $s[0])).'@company.example',
                'department' => $s[1],
                'joined_on' => $day(-200 + $i * 15),
                'is_active' => $i !== 11,
            ], $names, array_keys($names))));
        }

        if (! DB::table('lms_courses')->exists()) {
            $teacher = DB::table('lms_instructors')->pluck('id', 'name');
            DB::table('lms_courses')->insert($stamp([
                ['code' => 'PRG-101', 'title' => 'JavaScript foundations', 'category' => 'Programming', 'level' => 'beginner', 'instructor_id' => $teacher['Saraa Batbold'], 'start_date' => $day(-21), 'end_date' => $day(20), 'capacity' => 25, 'status' => 'in progress', 'description' => 'Variables to async code, with a small project at the end.'],
                ['code' => 'PRG-201', 'title' => 'React in practice', 'category' => 'Programming', 'level' => 'intermediate', 'instructor_id' => $teacher['Saraa Batbold'], 'start_date' => $day(28), 'end_date' => $day(69), 'capacity' => 20, 'status' => 'published', 'description' => 'Build and ship a real app with React and an API.'],
                ['code' => 'DAT-101', 'title' => 'Excel for analysis', 'category' => 'Data', 'level' => 'beginner', 'instructor_id' => $teacher['Ganbold Erdene'], 'start_date' => $day(-42), 'end_date' => $day(-7), 'capacity' => 30, 'status' => 'completed', 'description' => 'Formulas, pivot tables and charts for everyday reports.'],
                ['code' => 'DAT-210', 'title' => 'SQL and dashboards', 'category' => 'Data', 'level' => 'intermediate', 'instructor_id' => $teacher['Ganbold Erdene'], 'start_date' => $day(-7), 'end_date' => $day(34), 'capacity' => 20, 'status' => 'in progress', 'description' => 'Query company data and turn it into dashboards people use.'],
                ['code' => 'DES-110', 'title' => 'UX design basics', 'category' => 'Design', 'level' => 'beginner', 'instructor_id' => $teacher['Anu Tsogt'], 'start_date' => $day(7), 'end_date' => $day(41), 'capacity' => 15, 'status' => 'published', 'description' => 'Research, wireframes and usability testing.'],
                ['code' => 'BUS-150', 'title' => 'Leading a team', 'category' => 'Business', 'level' => 'advanced', 'instructor_id' => $teacher['Munkh Dorj'], 'start_date' => $day(56), 'end_date' => $day(76), 'capacity' => 12, 'status' => 'draft', 'description' => 'For new managers: feedback, one-to-ones and planning.'],
                ['code' => 'LAN-120', 'title' => 'Business English', 'category' => 'Languages', 'level' => 'intermediate', 'instructor_id' => $teacher['Emma Clarke'], 'start_date' => $day(-14), 'end_date' => $day(48), 'capacity' => 18, 'status' => 'in progress', 'description' => 'Meetings, emails and presentations in English.'],
            ]));
        }

        $course = DB::table('lms_courses')->pluck('id', 'code');

        if (! DB::table('lms_lessons')->exists()) {
            $lessons = [
                'PRG-101' => [['Welcome and setup', 'video', 15], ['Values and variables', 'video', 40], ['Functions', 'reading', 30], ['Arrays and objects', 'video', 45], ['Quiz: the basics', 'quiz', 20], ['Async and fetch', 'live', 90], ['Final project', 'assignment', 240]],
                'PRG-201' => [['Thinking in components', 'video', 35], ['State and effects', 'video', 50], ['Talking to an API', 'live', 90], ['Ship it', 'assignment', 300]],
                'DAT-101' => [['Formulas that save time', 'video', 30], ['Pivot tables', 'video', 45], ['Charts people read', 'reading', 25], ['Monthly report', 'assignment', 120]],
                'DAT-210' => [['SELECT and WHERE', 'video', 40], ['Joins', 'video', 50], ['Grouping and totals', 'quiz', 20], ['Build a dashboard', 'assignment', 180]],
                'DES-110' => [['What UX is', 'reading', 20], ['Interviewing users', 'live', 90], ['Wireframes', 'video', 45], ['Usability test', 'assignment', 150]],
                'BUS-150' => [['Your first 90 days', 'reading', 30], ['Giving feedback', 'live', 90]],
                'LAN-120' => [['Small talk and meetings', 'live', 60], ['Writing clear emails', 'reading', 30], ['Presenting', 'live', 60], ['Vocabulary check', 'quiz', 20]],
            ];
            $rows = [];
            foreach ($lessons as $code => $list) {
                foreach ($list as [$title, $kind, $minutes]) {
                    $rows[] = ['course_id' => $course[$code], 'title' => $title, 'kind' => $kind, 'duration_minutes' => $minutes];
                }
            }
            DB::table('lms_lessons')->insert($stamp($rows));
        }

        if (! DB::table('lms_enrollments')->exists()) {
            $student = DB::table('lms_students')->pluck('id', 'name');
            $enrollments = [
                ['Bat-Erdene', 'PRG-101', -25, 65, 'in progress', null],
                ['Tuvshin', 'PRG-101', -24, 80, 'in progress', null],
                ['Bilguun', 'PRG-101', -22, 40, 'in progress', null],
                ['Solongo', 'PRG-101', -23, 10, 'dropped', null],
                ['Oyunaa', 'DAT-101', -45, 100, 'completed', 92],
                ['Erdenebat', 'DAT-101', -44, 100, 'completed', 78.5],
                ['Nomin', 'DAT-101', -43, 100, 'completed', 85],
                ['Oyunaa', 'DAT-210', -9, 20, 'in progress', null],
                ['Temuulen', 'DAT-210', -8, 15, 'in progress', null],
                ['Enkhjin', 'DES-110', -3, 0, 'enrolled', null],
                ['Solongo', 'DES-110', -2, 0, 'enrolled', null],
                ['Bat-Erdene', 'PRG-201', -1, 0, 'enrolled', null],
                ['Ariunaa', 'LAN-120', -16, 45, 'in progress', null],
                ['Nomin', 'LAN-120', -15, 50, 'in progress', null],
                ['Khulan', 'LAN-120', -15, 30, 'in progress', null],
            ];
            DB::table('lms_enrollments')->insert($stamp(array_map(fn ($e) => [
                'student_id' => $student[$e[0]],
                'course_id' => $course[$e[1]],
                'enrolled_on' => $day($e[2]),
                'progress' => $e[3],
                'status' => $e[4],
                'grade' => $e[5],
            ], $enrollments)));
        }

        if (! DB::table('lms_sessions')->exists()) {
            $sessions = [
                ['PRG-101', 'JavaScript: async and fetch', 'lecture', $at(0, '10:00'), $at(0, '11:30'), 'Room 301'],
                ['DAT-210', 'SQL: joins', 'lecture', $at(1, '14:00'), $at(1, '15:30'), 'Room 204'],
                ['LAN-120', 'Business English: meetings', 'workshop', $at(1, '09:00'), $at(1, '10:00'), 'Online'],
                ['PRG-101', 'Office hours with Saraa', 'office hours', $at(2, '16:00'), $at(2, '17:00'), 'Room 301'],
                ['DAT-210', 'Dashboard workshop', 'workshop', $at(3, '13:00'), $at(3, '16:00'), 'Lab 2'],
                ['LAN-120', 'Business English: presenting', 'workshop', $at(3, '09:00'), $at(3, '10:00'), 'Online'],
                ['PRG-101', 'JavaScript midterm', 'exam', $at(4, '10:00'), $at(4, '11:00'), 'Room 301'],
                ['DES-110', 'UX kickoff', 'lecture', $at(7, '10:00'), $at(7, '11:30'), 'Studio'],
                ['DAT-210', 'SQL: grouping', 'lecture', $at(8, '14:00'), $at(8, '15:30'), 'Room 204'],
                ['DES-110', 'Interviewing users', 'workshop', $at(9, '13:00'), $at(9, '15:00'), 'Studio'],
                ['LAN-120', 'Business English: emails', 'workshop', $at(8, '09:00'), $at(8, '10:00'), 'Online'],
                ['PRG-101', 'Project check-in', 'office hours', $at(10, '16:00'), $at(10, '17:00'), 'Room 301'],
                ['PRG-101', 'JavaScript: final demo day', 'exam', $at(18, '10:00'), $at(18, '13:00'), 'Auditorium'],
                ['DAT-210', 'SQL quiz', 'exam', $at(15, '14:00'), $at(15, '14:45'), 'Room 204'],
                ['DES-110', 'Wireframes', 'lecture', $at(14, '10:00'), $at(14, '11:30'), 'Studio'],
                ['DAT-101', 'Excel: final reports review', 'workshop', $at(-8, '13:00'), $at(-8, '15:00'), 'Lab 2'],
                ['PRG-101', 'JavaScript: arrays and objects', 'lecture', $at(-7, '10:00'), $at(-7, '11:30'), 'Room 301'],
                ['LAN-120', 'Business English: small talk', 'workshop', $at(-6, '09:00'), $at(-6, '10:00'), 'Online'],
            ];
            DB::table('lms_sessions')->insert($stamp(array_map(fn ($s) => [
                'course_id' => $course[$s[0]],
                'title' => $s[1],
                'kind' => $s[2],
                'starts_at' => $s[3],
                'ends_at' => $s[4],
                'location' => $s[5],
            ], $sessions)));
        }
    }
}
