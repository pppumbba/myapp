<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaParam;
use App\Models\MetaType;
use App\Models\Widget;
use Illuminate\Database\Seeder;

/**
 * The Procurement app's Welcome page, built in the page builder's way: a page meta whose
 * params place widget metas (each one a library widget with its settings in json_config).
 *
 *   Banner                        -> opens a new purchase request
 *   Section "At a glance"         -> four figure charts
 *   Quick links | Callout         -> the main screens | a tip about Excel
 *   Record list | Contact card    -> the latest orders | the procurement desk
 *   Chart                         -> spend by week
 *
 * Contact card is a composite widget added to the library here: its parts (a section with a
 * heading, text and a button) are laid out on its template meta, and {{name}}, {{role}},
 * {{details}} and {{link}} in their settings take the card's own settings.
 *
 * Needs the Procurement demo (ProcurementDemoSeeder calls this). Run on its own, it only
 * touches the page, its own widgets and the app's Welcome menu item.
 */
class ProcurementPageSeeder extends Seeder
{
    private array $types = [];

    private Meta $page;

    private int $order = 0;

    public function run(): void
    {
        $this->call([MetaTypeSeeder::class, WidgetSeeder::class]);
        $this->types = MetaType::pluck('id', 'code')->all();

        $codes = ['proc_app', 'proc_request_form', 'proc_orders_grid', 'proc_requests_grid', 'proc_suppliers_grid', 'proc_chart_spend', 'proc_chart_orders', 'proc_chart_order_value', 'proc_chart_requests', 'proc_chart_spend_by_week', 'proc_order_report'];
        $m = Meta::whereIn('code', $codes)->pluck('id', 'code');
        if ($m->count() < count($codes)) {
            $this->command?->warn('Run ProcurementDemoSeeder first: the page links to its screens and charts.');

            return;
        }

        $this->contactCard();

        $this->page = Meta::updateOrCreate(['code' => 'proc_home_page'], [
            'name' => 'Procurement welcome page',
            'label_name' => 'Welcome',
            'type_id' => $this->types['page'],
            'description' => 'Where the Procurement app starts.',
            'is_active' => true,
        ]);

        // Rebuilt each run: the page's params, then its own widgets.
        MetaParam::where('main_meta_id', $this->page->id)->delete();
        Meta::where('ref_meta_id', $this->page->id)->where('type_id', $this->types['widget'])->delete();

        $this->place('hero', 12, [
            'title' => 'Buying, from request to delivery',
            'subtitle' => 'Ask for what your team needs, follow orders through approval, and see where the money goes.',
            'button_label' => 'New purchase request',
            'meta_id' => $m['proc_request_form'],
            'tone' => 'brand',
        ]);

        $glance = $this->place('section', 12, ['title' => 'At a glance', 'subtitle' => 'All time; open the Overview to pick a period.', 'background' => 'none']);
        foreach (['proc_chart_spend', 'proc_chart_orders', 'proc_chart_order_value', 'proc_chart_requests'] as $chart) {
            $this->place('chart', 3, ['meta_id' => $m[$chart], 'height' => 'small'], $glance);
        }

        $this->place('links', 8, ['title' => 'Get started', 'items' => [
            ['label' => 'Purchase requests', 'description' => 'Every request, from draft to ordered.', 'meta_id' => $m['proc_requests_grid']],
            ['label' => 'Purchase orders', 'description' => 'Orders placed with suppliers.', 'meta_id' => $m['proc_orders_grid']],
            ['label' => 'Suppliers', 'description' => 'Companies we buy from.', 'meta_id' => $m['proc_suppliers_grid']],
            ['label' => 'New request', 'description' => 'Ask for something to be bought.', 'meta_id' => $m['proc_request_form']],
        ]]);
        $this->place('callout', 4, [
            'tone' => 'info',
            'title' => 'Working in Excel?',
            'text' => "Every grid has an Export button.\n\nItems and requests also have Import: download the template, fill it in, and check the file before it is saved.",
        ]);

        $this->place('records', 8, [
            'title' => 'Latest orders',
            'meta_id' => $m['proc_orders_grid'],
            'limit' => 4,
            'sort' => 'order_date',
            'dir' => 'desc',
            'layout' => 'list',
            'lines' => [
                ['template' => '{po_number}', 'style' => 'title', 'label' => 'Order'],
                ['template' => '{supplier_name} · ordered {order_date}', 'style' => 'muted', 'label' => 'Supplier'],
                ['template' => '{total_amount}', 'style' => 'value', 'label' => 'Total'],
            ],
            'open_meta_id' => $m['proc_order_report'],
            'open_params' => 'id=id',
        ]);
        $this->place('contact_card', 4, [
            'name' => 'Procurement desk',
            'role' => 'Questions about an order or a supplier',
            'details' => 'procurement@bluesky.example
Mon–Fri, 9:00–17:00',
            'link' => $m['proc_request_form'],
        ]);

        $this->place('chart', 12, ['meta_id' => $m['proc_chart_spend_by_week'], 'height' => 'medium']);

        MetaParam::updateOrCreate(
            ['main_meta_id' => $m['proc_app'], 'field_path' => 'welcome', 'parent_id' => null],
            ['param_name' => 'welcome', 'label_name' => 'Welcome', 'data_type' => 'string', 'icon_cls' => 'home', 'ref_meta_id' => $this->page->id, 'order_number' => 0, 'is_show' => true],
        );
    }

    /**
     * A composite library entry: a card made of a section holding a heading, text and a
     * button. Its parts live on its template meta; {{key}} in them takes the card's setting.
     */
    private function contactCard(): void
    {
        $card = Widget::updateOrCreate(['code' => 'contact_card'], [
            'name' => 'Contact card',
            'category' => 'Content',
            'renderer' => 'composite',
            'icon' => 'info',
            'description' => 'Who to ask: a name, what they help with, how to reach them, and a button.',
            'props' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'default' => 'Name'],
                ['key' => 'role', 'label' => 'Helps with', 'type' => 'text'],
                ['key' => 'details', 'label' => 'How to reach', 'type' => 'textarea'],
                ['key' => 'link', 'label' => 'Button opens', 'type' => 'meta'],
            ],
            'is_active' => true,
            'order_number' => 100,
        ]);
        $template = Meta::updateOrCreate(['code' => 'widget_contact_card_parts'], [
            'name' => 'Contact card (parts)',
            'type_id' => $this->types['widget_template'],
            'description' => 'The widgets the Contact card widget is made of.',
            'is_active' => true,
        ]);
        $card->update(['template_meta_id' => $template->id]);

        MetaParam::where('main_meta_id', $template->id)->delete();
        Meta::where('ref_meta_id', $template->id)->where('type_id', $this->types['widget'])->delete();

        $box = $this->place('section', 12, ['background' => 'card'], null, $template);
        $this->place('heading', 12, ['text' => '{{name}}', 'subtitle' => '{{role}}', 'size' => 'small'], $box, $template);
        $this->place('text', 12, ['text' => '{{details}}', 'size' => 'small'], $box, $template);
        $this->place('button', 12, ['label' => 'Ask for something', 'meta_id' => '{{link}}', 'style' => 'secondary'], $box, $template);
    }

    /** Makes a widget meta from the library entry and places it on the page (or on $on). */
    private function place(string $widgetCode, int $w, array $config, ?MetaParam $section = null, ?Meta $on = null): MetaParam
    {
        $on ??= $this->page;
        $widget = Widget::where('code', $widgetCode)->firstOrFail();
        $this->order++;
        $code = "{$on->code}_{$widgetCode}_{$this->order}";

        $meta = Meta::create([
            'code' => $code,
            'name' => "{$widget->name} on {$on->name}",
            'type_id' => $this->types['widget'],
            'widget_id' => $widget->id,
            'ref_meta_id' => $on->id,
            'json_config' => $widget->defaults() ? array_merge($widget->defaults(), $config) : $config,
            'is_active' => true,
        ]);

        return MetaParam::create([
            'main_meta_id' => $on->id,
            'parent_id' => $section?->id,
            'order_number' => $this->order,
            'field_path' => $code,
            'param_name' => $code,
            'data_type' => 'widget',
            'ref_meta_id' => $meta->id,
            'is_show' => true,
            'json_config' => ['w' => $w],
        ]);
    }
}
