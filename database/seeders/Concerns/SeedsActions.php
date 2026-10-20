<?php

namespace Database\Seeders\Concerns;

use App\Models\Meta;
use App\Models\MetaAction;

/** Gives a demo grid its toolbar: Add, Edit and Delete through one form (see MetaAction). */
trait SeedsActions
{
    /**
     * Replaces a grid's toolbar buttons. $edit is the get meta that loads the selected row
     * into the form; $add the one that fills a new record. Without them the form loads the
     * row from its own table, or opens empty.
     */
    private function actions(Meta $grid, Meta $form, ?Meta $edit = null, ?Meta $add = null): void
    {
        MetaAction::where('grid_meta_id', $grid->id)->delete();

        foreach ([
            ['add', 'Add', 'plus', $add, false, false],
            ['edit', 'Edit', 'edit', $edit, true, true],
            ['delete', 'Delete', 'trash', null, true, true],
        ] as $i => [$action, $label, $icon, $get, $needsRow, $inRow]) {
            MetaAction::create([
                'grid_meta_id' => $grid->id,
                'action' => $action,
                'label' => $label,
                'icon_cls' => $icon,
                'form_meta_id' => $form->id,
                'get_meta_id' => $get?->id,
                'needs_row' => $needsRow,
                'show_in_row' => $inRow,
                'order_number' => $i + 1,
                'json_config' => $needsRow ? ['params' => ['id' => 'id']] : null,
            ]);
        }
    }
}
