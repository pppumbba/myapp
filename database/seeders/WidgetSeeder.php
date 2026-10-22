<?php

namespace Database\Seeders;

use App\Models\Widget;
use Illuminate\Database\Seeder;

/**
 * The page builder's built-in widget library. Each entry names a renderer and the props it
 * takes; the builder draws a settings form from the props. Admins can add their own
 * entries (e.g. a "Sales chart" preset of the chart renderer) on the Widgets page.
 *
 * Safe to run again: entries are matched by code. Entries admins added are left alone.
 */
class WidgetSeeder extends Seeder
{
    public function run(): void
    {
        $align = ['key' => 'align', 'label' => 'Alignment', 'type' => 'select', 'options' => ['left', 'center', 'right'], 'default' => 'left'];
        $link = [
            ['key' => 'meta_id', 'label' => 'Opens screen', 'type' => 'meta', 'hint' => 'A grid, form, report… opened in a window.'],
            ['key' => 'url', 'label' => 'Or opens link', 'type' => 'url'],
        ];

        $widgets = [
            // Layout
            ['section', 'Section', 'Layout', 'section', 'layout', 'A box that holds other widgets, with an optional title and background.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text'],
                ['key' => 'background', 'label' => 'Background', 'type' => 'select', 'options' => ['none', 'card', 'subtle', 'brand'], 'default' => 'card'],
            ]],
            ['divider', 'Divider', 'Layout', 'divider', 'minus', 'A thin line between parts of the page.', [
                ['key' => 'label', 'label' => 'Text on the line', 'type' => 'text'],
            ]],
            ['spacer', 'Spacer', 'Layout', 'spacer', 'expand', 'Empty space.', [
                ['key' => 'size', 'label' => 'Height', 'type' => 'select', 'options' => ['small', 'medium', 'large'], 'default' => 'medium'],
            ]],

            // Content
            ['hero', 'Banner', 'Content', 'hero', 'star', 'A large title and message with a button, to open a page.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => 'Welcome'],
                ['key' => 'subtitle', 'label' => 'Message', 'type' => 'textarea'],
                ['key' => 'button_label', 'label' => 'Button', 'type' => 'text'],
                ...$link,
                ['key' => 'image', 'label' => 'Image', 'type' => 'image'],
                ['key' => 'tone', 'label' => 'Colour', 'type' => 'select', 'options' => ['brand', 'dark', 'light'], 'default' => 'brand'],
            ]],
            ['heading', 'Heading', 'Content', 'heading', 'type', 'A title, with an optional line under it.', [
                ['key' => 'text', 'label' => 'Text', 'type' => 'text', 'default' => 'Heading'],
                ['key' => 'subtitle', 'label' => 'Line under it', 'type' => 'text'],
                ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['large', 'medium', 'small'], 'default' => 'medium'],
                $align,
            ]],
            ['text', 'Text', 'Content', 'text', 'align-left', 'Paragraphs of text. A blank line starts a new paragraph.', [
                ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'default' => 'Write something here.'],
                ['key' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['small', 'normal', 'large'], 'default' => 'normal'],
                $align,
            ]],
            ['image', 'Image', 'Content', 'image', 'image', 'A picture from a web address.', [
                ['key' => 'src', 'label' => 'Image address', 'type' => 'image'],
                ['key' => 'alt', 'label' => 'Description', 'type' => 'text', 'hint' => 'Read out to people who can’t see the image.'],
                ['key' => 'caption', 'label' => 'Caption', 'type' => 'text'],
                ['key' => 'height', 'label' => 'Height (px)', 'type' => 'number', 'default' => 240],
                ['key' => 'fit', 'label' => 'Fit', 'type' => 'select', 'options' => ['cover', 'contain'], 'default' => 'cover'],
            ]],
            ['callout', 'Callout', 'Content', 'callout', 'info', 'A coloured note that stands out.', [
                ['key' => 'tone', 'label' => 'Kind', 'type' => 'select', 'options' => ['info', 'success', 'warning', 'error'], 'default' => 'info'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'text', 'label' => 'Text', 'type' => 'textarea', 'default' => 'Something worth knowing.'],
            ]],
            ['list', 'Feature list', 'Content', 'list', 'list', 'Short points, each with a title and a line of text.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'columns', 'label' => 'Columns', 'type' => 'select', 'options' => ['1', '2', '3'], 'default' => '2'],
                ['key' => 'items', 'label' => 'Points', 'type' => 'list', 'default' => [['title' => 'First point', 'text' => 'Say why it matters.']], 'fields' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'text', 'label' => 'Text', 'type' => 'text'],
                ]],
            ]],

            // Navigation
            ['button', 'Button', 'Navigation', 'button', 'cursor', 'A button that opens a screen or a link.', [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Open'],
                ...$link,
                ['key' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['primary', 'secondary', 'link'], 'default' => 'primary'],
                $align,
            ]],
            ['links', 'Quick links', 'Navigation', 'links', 'link', 'Tiles that open screens or links.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'items', 'label' => 'Links', 'type' => 'list', 'default' => [], 'fields' => [
                    ['key' => 'label', 'label' => 'Label', 'type' => 'text'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
                    ...$link,
                ]],
            ]],

            // Data
            ['stat', 'Figure', 'Data', 'stat', 'hash', 'One number written in by hand, with a label and a change.', [
                ['key' => 'label', 'label' => 'Label', 'type' => 'text', 'default' => 'Figure'],
                ['key' => 'value', 'label' => 'Value', 'type' => 'text', 'default' => '0'],
                ['key' => 'change', 'label' => 'Change', 'type' => 'text', 'hint' => 'e.g. +12%'],
                ['key' => 'trend', 'label' => 'Trend', 'type' => 'select', 'options' => ['up', 'down', 'flat'], 'default' => 'flat'],
                ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
            ]],
            ['chart', 'Chart', 'Data', 'chart', 'chart', 'A chart meta, drawn live.', [
                ['key' => 'meta_id', 'label' => 'Chart', 'type' => 'meta', 'meta_types' => ['chart']],
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'hint' => 'Leave blank to use the chart’s own.'],
                ['key' => 'height', 'label' => 'Height', 'type' => 'select', 'options' => ['small', 'medium', 'large'], 'default' => 'medium'],
            ]],
            ['records', 'Record list', 'Data', 'records', 'list', 'Records from a grid, each drawn by lines you write with {field} placeholders.', [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'meta_id', 'label' => 'Records from', 'type' => 'meta', 'meta_types' => ['datagrid', 'statement', 'calendar', 'gantt'], 'hint' => 'A grid; its filters and permissions apply.'],
                ['key' => 'limit', 'label' => 'How many', 'type' => 'number', 'default' => 5],
                ['key' => 'sort', 'label' => 'Order by field', 'type' => 'text', 'hint' => 'A field of the grid; blank keeps its usual order.'],
                ['key' => 'dir', 'label' => 'Order', 'type' => 'select', 'options' => ['desc', 'asc'], 'default' => 'desc'],
                ['key' => 'layout', 'label' => 'Look', 'type' => 'select', 'options' => ['list', 'cards', 'table'], 'default' => 'list'],
                ['key' => 'columns', 'label' => 'Cards per row', 'type' => 'select', 'options' => ['1', '2', '3'], 'default' => '2'],
                ['key' => 'lines', 'label' => 'Each record shows', 'type' => 'list', 'default' => [['template' => '{name}', 'style' => 'title']], 'fields' => [
                    ['key' => 'template', 'label' => 'Text', 'type' => 'text', 'hint' => 'e.g. {po_number} · {supplier_name}'],
                    ['key' => 'style', 'label' => 'Style', 'type' => 'select', 'options' => ['title', 'text', 'muted', 'value', 'badge'], 'default' => 'text'],
                    ['key' => 'label', 'label' => 'Column heading', 'type' => 'text', 'hint' => 'For the table look.'],
                ]],
                ['key' => 'open_meta_id', 'label' => 'A record opens', 'type' => 'meta'],
                ['key' => 'open_params', 'label' => 'Passing', 'type' => 'text', 'default' => 'id=id', 'hint' => 'parameter=field, comma separated.'],
                ['key' => 'empty_text', 'label' => 'When there are none', 'type' => 'text', 'default' => 'Nothing here yet.'],
            ]],
            ['datagrid', 'Data grid', 'Data', 'meta', 'table', 'A grid of records, with its filters and buttons.', [
                ['key' => 'meta_id', 'label' => 'Grid', 'type' => 'meta', 'meta_types' => ['datagrid']],
            ]],
            ['form', 'Form', 'Data', 'meta', 'form', 'A form to add a record.', [
                ['key' => 'meta_id', 'label' => 'Form', 'type' => 'meta', 'meta_types' => ['form']],
            ]],
            ['screen', 'Any screen', 'Data', 'meta', 'layers', 'Any meta: a calendar, statement, dashboard, tabs…', [
                ['key' => 'meta_id', 'label' => 'Screen', 'type' => 'meta'],
            ]],
            ['iframe', 'Web page', 'Data', 'iframe', 'globe', 'Another web page or video, shown in a frame.', [
                ['key' => 'url', 'label' => 'Address', 'type' => 'url'],
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => 'Embedded page'],
                ['key' => 'height', 'label' => 'Height (px)', 'type' => 'number', 'default' => 400],
            ]],
        ];

        foreach ($widgets as $i => [$code, $name, $category, $renderer, $icon, $description, $props]) {
            Widget::updateOrCreate(['code' => $code], [
                'name' => $name,
                'category' => $category,
                'renderer' => $renderer,
                'icon' => $icon,
                'description' => $description,
                'props' => $props,
                'is_active' => true,
                'order_number' => $i + 1,
            ]);
        }
    }
}
