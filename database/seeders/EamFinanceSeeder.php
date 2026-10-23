<?php

namespace Database\Seeders;

use App\Models\Meta;
use App\Models\MetaAction;
use App\Models\MetaParam;
use App\Models\MetaProcessBehaviour;
use App\Models\MetaProcessTransition;
use App\Models\MetaProcessWorkflow;
use App\Models\MetaSchedule;
use App\Models\MetaType;
use App\Models\Schedule;
use App\Support\NamedSql;
use App\Support\StageQuery;
use Database\Seeders\Concerns\SeedsActions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The finance side of the EAM demo (EamDemoSeeder calls this): fixed asset transactions and
 * the journal they post.
 *
 *   Finance (menu group in the Assets app)
 *   ├─ Asset transactions   -> purchase, capitalization, depreciation, revaluation, devaluation,
 *   │                          sale, disposal; saved as drafts, then "Post" (eam_post_transaction)
 *   ├─ Depreciation preview -> what "Run depreciation" would post for a month, asset by asset
 *   ├─ Run depreciation     -> eam_run_depreciation: one month's straight-line depreciation for
 *   │                          every asset, posted (also monthly, for the month before, on a schedule)
 *   ├─ Book values          -> statement by category: cost, revaluation, depreciation, impairment
 *   ├─ Journal              -> statement by account, debits and credits
 *   └─ Accounts & rules     -> tabs: chart of accounts, posting rules
 *
 * Posting works the amounts out from the asset's balances at that moment:
 *   purchase        +amount to cost, fills an empty purchase date/cost Dr 1500 / Cr 2100 (or the offset account)
 *   capitalization  +amount to cost (an upgrade, a capitalized repair) Dr 1500 / Cr 2100 (or the offset account)
 *   depreciation    +amount to accumulated depreciation              Dr 6100 / Cr 1590
 *   revaluation     new value − book value: first reverses earlier impairment (Dr 1580 / Cr 7200),
 *                   the rest goes to the revaluation surplus          (Dr 1510 / Cr 3200)
 *   devaluation     book value − new value: first uses up the surplus (Dr 3200 / Cr 1510),
 *                   the rest is an impairment loss                     (Dr 6200 / Cr 1580)
 *   sale            takes the asset off the books through 1700 Disposal clearing: cost out
 *                   (Dr 1700 / Cr 1500), depreciation and impairment out (Dr 1590, 1580 / Cr 1700),
 *                   revaluation out (Dr 1700 / Cr 1510), the price in (Dr 1200 or the offset
 *                   account / Cr 1700), price − book value as a gain (Cr 7300) or loss (Dr 6300),
 *                   and the surplus to retained earnings (Dr 3200 / Cr 3300); the asset is disposed
 *   disposal        the same without a price: the book value left is a loss
 * Nothing posts to a disposed asset.
 * Depreciation spreads what's left above salvage over the months of life remaining, so it
 * follows a revaluation or devaluation by itself.
 *
 * The posting SQL is portable; the depreciation run and preview use MySQL date functions.
 */
class EamFinanceSeeder extends Seeder
{
    use SeedsActions;

    public const TXN_TYPES = ['purchase', 'capitalization', 'depreciation', 'revaluation', 'devaluation', 'sale', 'disposal'];

    /** Number prefixes, by type. */
    private const PREFIXES = ['purchase' => 'PUR', 'capitalization' => 'CAP', 'depreciation' => 'DEP', 'revaluation' => 'REV', 'devaluation' => 'DEV', 'sale' => 'SAL', 'disposal' => 'DSP'];

    private const TXN_STATUSES = ['draft', 'posted', 'cancelled'];

    private const ACCOUNTS = [
        ['1010', 'Cash at bank', 'asset'],
        ['1200', 'Trade receivables', 'asset'],
        ['1500', 'Fixed assets – cost', 'asset'],
        ['1510', 'Fixed assets – revaluation', 'asset'],
        ['1580', 'Accumulated impairment', 'asset'],
        ['1590', 'Accumulated depreciation', 'asset'],
        ['1600', 'Construction in progress', 'asset'],
        ['1700', 'Asset disposal clearing', 'asset'],
        ['2100', 'Accounts payable', 'liability'],
        ['3200', 'Revaluation surplus', 'equity'],
        ['3300', 'Retained earnings', 'equity'],
        ['6100', 'Depreciation expense', 'expense'],
        ['6200', 'Impairment loss', 'expense'],
        ['6300', 'Loss on disposal', 'expense'],
        ['7200', 'Impairment reversal gain', 'income'],
        ['7300', 'Gain on disposal', 'income'],
    ];

    private const RULES = [
        ['purchase', 'amount', '1500', '2100', 'The asset bought'],
        ['capitalization', 'amount', '1500', '2100', 'Cost added to the asset'],
        ['depreciation', 'amount', '6100', '1590', 'The month’s depreciation'],
        ['revaluation', 'pl', '1580', '7200', 'Reverses an earlier impairment'],
        ['revaluation', 'reserve', '1510', '3200', 'Increase above cost, to the surplus'],
        ['devaluation', 'reserve', '3200', '1510', 'Decrease taken from the surplus'],
        ['devaluation', 'pl', '6200', '1580', 'Decrease beyond the surplus, an impairment loss'],
        ...self::DERECOGNITION['sale'],
        ['sale', 'amount', '1200', '1700', 'The sale price'],
        ...self::DERECOGNITION['disposal'],
    ];

    /** Sales and disposals take the asset off the books the same way. */
    private const DERECOGNITION = [
        'sale' => [
            ['sale', 'cost', '1700', '1500', 'Cost taken off'],
            ['sale', 'depreciation', '1590', '1700', 'Depreciation taken off'],
            ['sale', 'impairment', '1580', '1700', 'Impairment taken off'],
            ['sale', 'reserve', '1700', '1510', 'Revaluation taken off'],
            ['sale', 'gain', '1700', '7300', 'Price above book value'],
            ['sale', 'loss', '6300', '1700', 'Price below book value'],
            ['sale', 'surplus', '3200', '3300', 'Surplus to retained earnings'],
        ],
        'disposal' => [
            ['disposal', 'cost', '1700', '1500', 'Cost taken off'],
            ['disposal', 'depreciation', '1590', '1700', 'Depreciation taken off'],
            ['disposal', 'impairment', '1580', '1700', 'Impairment taken off'],
            ['disposal', 'reserve', '1700', '1510', 'Revaluation taken off'],
            ['disposal', 'loss', '6300', '1700', 'Book value written off'],
            ['disposal', 'surplus', '3200', '3300', 'Surplus to retained earnings'],
        ],
    ];

    /** The form's scripts: show the fields a type uses, preview the new book value, check before saving. */
    public const TRANSACTION_FORM_SCRIPTS = [
        'var_fnc_expression_string' => <<<'JS'
            const valued = () => ['revaluation', 'devaluation'].includes(get('txn_type'));
            const leaving = () => ['sale', 'disposal'].includes(get('txn_type'));
            function byType() {
              const type = get('txn_type');
              const is = (...types) => types.includes(type);
              (valued() ? show : hide)('new_value');
              (valued() || is('disposal') ? hide : show)('amount');
              (is('purchase', 'capitalization', 'sale') ? show : hide)('offset_account');
              (is('purchase', 'sale') ? show : hide)('counterparty');
              (is('purchase', 'sale') ? show : hide)('invoice_no');
              (is('capitalization') ? show : hide)('work_order_id');
              (is('depreciation') ? show : hide)('period');
              (leaving() ? show : hide)('pl_amount');
            }
            function preview() {
              const before = num(get('book_value_before'));
              const type = get('txn_type');
              const after = valued() ? num(get('new_value'))
                : leaving() ? 0
                : ['purchase', 'capitalization'].includes(type) ? before + num(get('amount')) : before - num(get('amount'));
              set('book_value_after', round(after, 2));
              // A sale's gain (or loss, below zero); a disposal writes off what's left.
              if (leaving()) set('pl_amount', round((type === 'sale' ? num(get('amount')) : 0) - before, 2));
            }
            JS,
        'load_expression_string' => <<<'JS'
            ['book_value_before', 'book_value_after', 'reserve_amount', 'pl_amount', 'status'].forEach((f) => readonly(f));
            byType();
            JS,
        'event_expression_string' => <<<'JS'
            if (field === 'txn_type') byType();
            if (['txn_type', 'asset_id', 'amount', 'new_value', 'work_order_id'].includes(field)) preview();
            JS,
        'before_save_expression_string' => <<<'JS'
            if (get('status') === 'posted') {
              error('This transaction is posted and can’t be changed. Post a new one to correct it.');
            }
            const type = get('txn_type');
            const before = num(get('book_value_before'));
            if (valued()) {
              if (get('new_value') === '' || get('new_value') == null) error('Enter the new value of the asset.');
              if (type === 'revaluation' && num(get('new_value')) <= before) error('A revaluation raises the value: the new value must be above the book value (' + before + '). Use a devaluation to lower it.');
              if (type === 'devaluation' && num(get('new_value')) >= before) error('A devaluation lowers the value: the new value must be below the book value (' + before + '). Use a revaluation to raise it.');
            } else if (type !== 'disposal' && num(get('amount')) <= 0) {
              error(type === 'sale' ? 'Enter the sale price. To scrap the asset for nothing, use a disposal.' : 'Enter an amount above zero.');
            }
            JS,
        'after_save_expression_string' => null,
    ];

    private array $types = [];

    public function run(): void
    {
        $this->call(MetaTypeSeeder::class);
        $this->types = MetaType::pluck('id', 'code')->all();
        $app = Meta::where('code', 'eam_app')->first();
        if (! $app) {
            $this->command?->warn('Run EamDemoSeeder first: the finance screens belong to its Assets app.');

            return;
        }

        DB::transaction(function () use ($app) {
            $this->setupData();
            $post = $this->metas($app);
            $this->sampleLedger($post);
        });
    }

    /**
     * Posting SQL for the draft transactions a scope picks ({t} is the table or its alias),
     * in order: work out the amounts, write the journal, mark them "posting", move the asset
     * balances, mark them posted. A draft posts when it has an amount above zero (a disposal
     * needs none) and its asset isn't disposed; one that doesn't add up stays a draft.
     */
    public static function postingSql(string $scope): string
    {
        $t = 'eam_asset_transactions';
        $scoped = fn (string $alias) => str_replace('{t}', $alias, $scope);
        $draft = fn (string $alias) => "{$alias}.status = 'draft' AND {$scoped($alias)}";
        $valid = fn (string $alias) => "({$alias}.amount > 0 OR {$alias}.txn_type = 'disposal')
            AND (SELECT x.status FROM eam_assets x WHERE x.id = {$alias}.asset_id) <> 'disposed'";
        $posting = fn (string $alias) => "{$alias}.status = 'posting' AND {$scoped($alias)}";
        $asset = fn (string $column) => "(SELECT a.{$column} FROM eam_assets a WHERE a.id = {$t}.asset_id)";
        $least = fn (string $x, string $y) => "CASE WHEN {$x} < {$y} THEN {$x} ELSE {$y} END";
        $leaving = "('sale', 'disposal')";
        $adding = "('purchase', 'capitalization')";
        $sum = fn (string $expr) => "COALESCE((SELECT SUM({$expr}) FROM {$t} t WHERE t.asset_id = eam_assets.id AND {$posting('t')}), 0)";
        $first = fn (string $types) => "(SELECT MIN(t.txn_date) FROM {$t} t WHERE t.asset_id = eam_assets.id AND t.txn_type IN {$types} AND {$posting('t')})";
        $gone = "EXISTS (SELECT 1 FROM {$t} t WHERE t.asset_id = eam_assets.id AND t.txn_type IN {$leaving} AND {$posting('t')})";
        $part = "CASE r.part WHEN 'amount' THEN t.amount WHEN 'reserve' THEN t.reserve_amount WHEN 'surplus' THEN t.reserve_amount
                             WHEN 'pl' THEN t.pl_amount
                             WHEN 'gain' THEN CASE WHEN t.pl_amount > 0 THEN t.pl_amount ELSE 0 END
                             WHEN 'loss' THEN CASE WHEN t.pl_amount < 0 THEN 0 - t.pl_amount ELSE 0 END
                             WHEN 'cost' THEN t.cost_out WHEN 'depreciation' THEN t.depreciation_out WHEN 'impairment' THEN t.impairment_out
                             ELSE 0 END";
        // The offset account replaces the side the money comes from: what we owe for a
        // purchase, what we're owed for a sale.
        $offset = "COALESCE(NULLIF(t.offset_account, ''), %s)";
        $impairment = $asset('accumulated_impairment');
        $surplus = $asset('revaluation_surplus');
        $change = fn (string $column, string $expr) => "{$column} = CASE WHEN {$gone} THEN 0 ELSE {$column} + {$sum($expr)} END";

        return implode(";\n", [
            "UPDATE {$t}
            SET book_value_before = {$asset('carrying_amount')},
                amount = CASE txn_type WHEN 'revaluation' THEN new_value - {$asset('carrying_amount')}
                                       WHEN 'devaluation' THEN {$asset('carrying_amount')} - new_value
                                       WHEN 'disposal' THEN 0 ELSE amount END,
                cost_out = CASE WHEN txn_type IN {$leaving} THEN {$asset('capitalized_cost')} ELSE 0 END,
                depreciation_out = CASE WHEN txn_type IN {$leaving} THEN {$asset('accumulated_depreciation')} ELSE 0 END,
                impairment_out = CASE WHEN txn_type IN {$leaving} THEN {$asset('accumulated_impairment')} ELSE 0 END,
                updated_at = CURRENT_TIMESTAMP
            WHERE {$draft($t)}",
            "UPDATE {$t}
            SET pl_amount = CASE WHEN txn_type = 'revaluation' THEN {$least('amount', $impairment)}
                                 WHEN txn_type = 'devaluation' THEN amount - {$least('amount', $surplus)}
                                 WHEN txn_type IN {$leaving} THEN amount - book_value_before ELSE 0 END,
                reserve_amount = CASE WHEN txn_type = 'revaluation' THEN amount - {$least('amount', $impairment)}
                                      WHEN txn_type = 'devaluation' THEN {$least('amount', $surplus)}
                                      WHEN txn_type IN {$leaving} THEN {$asset('revaluation_amount')} ELSE 0 END,
                book_value_after = CASE WHEN txn_type IN {$leaving} THEN 0
                                        WHEN txn_type IN ('purchase', 'capitalization', 'revaluation') THEN book_value_before + amount
                                        ELSE book_value_before - amount END
            WHERE {$draft($t)}",
            "INSERT INTO eam_journal_entries (transaction_id, asset_id, entry_date, account_code, txn_type, debit, credit, memo, created_at, updated_at)
            SELECT t.id, t.asset_id, t.txn_date,
                   CASE WHEN s.side = 'D' AND r.part = 'amount' AND t.txn_type = 'sale' THEN ".sprintf($offset, 'r.debit_account')."
                        WHEN s.side = 'D' THEN r.debit_account
                        WHEN r.part = 'amount' AND t.txn_type IN {$adding} THEN ".sprintf($offset, 'r.credit_account')."
                        ELSE r.credit_account END,
                   t.txn_type,
                   CASE WHEN s.side = 'D' THEN {$part} ELSE 0 END,
                   CASE WHEN s.side = 'C' THEN {$part} ELSE 0 END,
                   t.txn_no, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM {$t} t
            JOIN eam_posting_rules r ON r.txn_type = t.txn_type
            CROSS JOIN (SELECT 'D' AS side UNION ALL SELECT 'C' AS side) s
            WHERE {$draft('t')} AND {$valid('t')} AND {$part} > 0",
            "UPDATE {$t} SET status = 'posting' WHERE {$draft($t)} AND {$valid($t)}",
            "UPDATE eam_assets
            SET capitalized_on = COALESCE(capitalized_on, {$first($adding)}),
                purchase_date = COALESCE(purchase_date, {$first("('purchase')")}),
                purchase_cost = CASE WHEN purchase_cost = 0 THEN {$sum("CASE WHEN t.txn_type = 'purchase' THEN t.amount ELSE 0 END")} ELSE purchase_cost END,
                {$change('capitalized_cost', "CASE WHEN t.txn_type IN {$adding} THEN t.amount ELSE 0 END")},
                {$change('accumulated_depreciation', "CASE WHEN t.txn_type = 'depreciation' THEN t.amount ELSE 0 END")},
                {$change('revaluation_amount', "CASE t.txn_type WHEN 'revaluation' THEN t.reserve_amount WHEN 'devaluation' THEN 0 - t.reserve_amount ELSE 0 END")},
                {$change('revaluation_surplus', "CASE t.txn_type WHEN 'revaluation' THEN t.reserve_amount WHEN 'devaluation' THEN 0 - t.reserve_amount ELSE 0 END")},
                {$change('accumulated_impairment', "CASE t.txn_type WHEN 'revaluation' THEN 0 - t.pl_amount WHEN 'devaluation' THEN t.pl_amount ELSE 0 END")},
                disposed_on = CASE WHEN {$gone} THEN {$first($leaving)} ELSE disposed_on END,
                status = CASE WHEN {$gone} THEN 'disposed' ELSE status END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN (SELECT t.asset_id FROM {$t} t WHERE {$posting('t')})",
            "UPDATE {$t}
            SET status = 'posted', posted_on = :today, posted_by = :user_id, updated_at = CURRENT_TIMESTAMP
            WHERE {$posting($t)}",
        ]);
    }

    /** One month's straight-line amount per asset ("p" is the first day of :period), MySQL. */
    private const DEPRECIATION_SELECT = <<<'SQL'
        SELECT a.id AS asset_id, a.asset_tag, a.name, a.carrying_amount, a.salvage_value,
               GREATEST(COALESCE(a.useful_life_years, c.useful_life_years, 5) * 12 - TIMESTAMPDIFF(MONTH, a.capitalized_on, p.first_day), 1) AS months_left,
               ROUND((a.carrying_amount - a.salvage_value)
                     / GREATEST(COALESCE(a.useful_life_years, c.useful_life_years, 5) * 12 - TIMESTAMPDIFF(MONTH, a.capitalized_on, p.first_day), 1), 2) AS amount,
               LAST_DAY(p.first_day) AS txn_date
        FROM eam_assets a
        LEFT JOIN eam_categories c ON c.id = a.category_id
        CROSS JOIN (SELECT CAST(CONCAT(:period, '-01') AS DATE) AS first_day) p
        WHERE a.status <> 'disposed' AND a.capitalized_on IS NOT NULL
          AND a.capitalized_on <= LAST_DAY(p.first_day)
          AND a.carrying_amount > a.salvage_value
          AND NOT EXISTS (SELECT 1 FROM eam_asset_transactions x
                          WHERE x.asset_id = a.id AND x.txn_type = 'depreciation' AND x.period = :period AND x.status <> 'cancelled')
        SQL;

    private function metas(Meta $app): Meta
    {
        $key = ['is_primary' => true, 'is_show' => false];
        $options = fn (array $list, string $default) => ['render_type' => 'select', 'default_value' => $default, 'json_config' => ['options' => $list]];
        $money = ['render_type' => 'money'];
        $assetLookup = Meta::where('code', 'eam_assets_lookup')->firstOrFail();
        $workOrderGrid = Meta::where('code', 'eam_work_orders_grid')->firstOrFail();

        // Chart of accounts and posting rules.
        $accountForm = $this->meta('eam_account_form', 'Account form', 'form', 'eam_accounts', 'An account in the chart of accounts.');
        $this->params($accountForm, [
            ['id', 'ID', 'integer', $key],
            ['code', 'Code', 'string', ['is_required' => true, 'is_unique' => true]],
            ['name', 'Name', 'string', ['is_required' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_required' => true] + $options(['asset', 'liability', 'equity', 'income', 'expense'], 'asset')],
        ]);
        $accountGrid = $this->meta('eam_accounts_grid', 'Accounts', 'datagrid', 'eam_accounts', 'The accounts fixed asset postings use.');
        $this->actions($accountGrid, $accountForm);
        $this->params($accountGrid, [
            ['id', 'ID', 'integer', $key],
            ['code', 'Code', 'string', ['is_criteria' => true]],
            ['name', 'Name', 'string', ['is_criteria' => true, 'is_label' => true]],
            ['kind', 'Kind', 'string', ['is_criteria' => true]],
        ]);
        $account = ['is_select' => true, 'ref_meta_id' => $accountGrid->id, 'render_type' => 'lookup', 'display_field' => 'name', 'value_field' => 'code'];

        $ruleForm = $this->meta('eam_posting_rule_form', 'Posting rule form', 'form', 'eam_posting_rules', 'Which accounts a part of a transaction posts to.');
        $ruleFields = [
            ['id', 'ID', 'integer', $key],
            ['txn_type', 'Transaction', 'string', ['is_required' => true] + $options(self::TXN_TYPES, 'capitalization')],
            ['part', 'Part', 'string', ['is_required' => true] + $options(['amount', 'reserve', 'pl'], 'amount')],
            ['debit_account', 'Debit', 'string', ['is_required' => true] + $account],
            ['credit_account', 'Credit', 'string', ['is_required' => true] + $account],
            ['description', 'Description', 'string'],
        ];
        $this->params($ruleForm, $ruleFields);
        $ruleGrid = $this->meta('eam_posting_rules_grid', 'Posting rules', 'datagrid', 'eam_posting_rules', 'Debit and credit accounts for each kind of transaction.');
        $this->actions($ruleGrid, $ruleForm);
        $this->params($ruleGrid, $ruleFields);

        $accounts = $this->meta('eam_accounts_tabs', 'Accounts & rules', 'tabs', null, 'The chart of accounts and how transactions post to it.');
        $this->params($accounts, [
            ['accounts', 'Accounts', 'string', ['ref_meta_id' => $accountGrid->id]],
            ['rules', 'Posting rules', 'string', ['ref_meta_id' => $ruleGrid->id]],
        ]);

        // Posting and the depreciation run.
        $post = $this->meta('eam_post_transaction', 'Post transaction', 'stage_query', 'eam_asset_transactions', 'Posts a draft transaction: works out its amounts, moves the asset’s balances and writes the journal.', null, null,
            self::postingSql('{t}.id = :txn_id'));
        $this->params($post, [['txn_id', 'Transaction', 'integer', ['is_required' => true]]]);

        $run = $this->meta('eam_run_depreciation', 'Run depreciation', 'stage_query', 'eam_asset_transactions', 'Posts one month’s straight-line depreciation for every asset not yet depreciated that month.', null, null,
            "INSERT INTO eam_asset_transactions (txn_no, txn_type, asset_id, txn_date, period, amount, status, reason, created_at, updated_at)
            SELECT CONCAT('DEPR-', :period, '-', LPAD(d.asset_id, 5, '0')), 'depreciation', d.asset_id, d.txn_date, :period, d.amount, 'draft',
                   CONCAT('Depreciation for ', :period), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            FROM (".self::DEPRECIATION_SELECT.") d
            WHERE d.amount > 0;\n".self::postingSql("{t}.txn_type = 'depreciation' AND {t}.period = :period"));
        $this->params($run, [['period', 'Month (YYYY-MM)', 'string', ['is_required' => true]]]);

        $preview = $this->meta('eam_depreciation_preview', 'Depreciation preview', 'datagrid', 'eam_assets', 'What a depreciation run would post for a month, asset by asset.', null, null, self::DEPRECIATION_SELECT);
        $this->params($preview, [
            ['asset_id', 'Asset', 'integer', $key],
            ['asset_tag', 'Tag', 'string'],
            ['name', 'Asset', 'string'],
            ['carrying_amount', 'Book value', 'decimal', $money],
            ['salvage_value', 'Salvage', 'decimal', $money],
            ['months_left', 'Months left', 'integer'],
            ['amount', 'Depreciation', 'decimal', $money],
            ['period', 'Month (YYYY-MM)', 'string', ['is_show' => false, 'is_criteria' => true, 'is_mandatory_criteria' => true]],
        ]);

        // On the 1st of each month, depreciation for the month before.
        $lastMonth = $this->meta('eam_depreciation_period', 'Last month', 'datagrid', 'eam_asset_transactions', 'One row: last month, for the monthly depreciation run.', null, null,
            "SELECT DATE_FORMAT(DATE_SUB(:today, INTERVAL 1 MONTH), '%Y-%m') AS period");
        $this->params($lastMonth, [['period', 'Month', 'string', ['is_primary' => true]]]);
        $monthly = Schedule::firstOrCreate(['code' => 'monthly'], ['name' => 'Every month']);
        MetaSchedule::firstOrCreate(['schedule_id' => $monthly->id, 'process_id' => $run->id, 'view_id' => $lastMonth->id]);

        // The transactions.
        $asset = ['is_select' => true, 'ref_meta_id' => $assetLookup->id, 'render_type' => 'lookup', 'display_field' => 'name'];
        $form = $this->meta('eam_transaction_form', 'Asset transaction form', 'form', 'eam_asset_transactions', 'Capitalize, depreciate, revalue or devalue an asset. Saved as a draft until posted.');
        $form->update(self::TRANSACTION_FORM_SCRIPTS);
        $autonumber = ['format' => 'FA-{yy}{MM}{seq:4}', 'rules' => array_map(
            fn ($type, $prefix) => ['field' => 'txn_type', 'value' => $type, 'format' => "{$prefix}-{yy}{MM}{seq:4}"],
            array_keys(self::PREFIXES), self::PREFIXES,
        )];
        $this->params($form, [
            ['id', 'ID', 'integer', $key],
            ['txn_type', 'Transaction', 'string', ['is_required' => true] + $options(self::TXN_TYPES, 'purchase')],
            ['txn_no', 'Number', 'string', ['is_required' => true, 'is_unique' => true, 'json_config' => ['autonumber' => $autonumber]]],
            ['asset_id', 'Asset', 'integer', ['is_required' => true, 'json_config' => ['fill' => ['book_value_before' => 'carrying_amount']]] + $asset],
            ['txn_date', 'Date', 'date', ['is_required' => true, 'default_value' => '{today}']],
            ['period', 'Month (YYYY-MM)', 'string'],
            ['work_order_id', 'Capitalize work order', 'integer', ['is_select' => true, 'ref_meta_id' => $workOrderGrid->id, 'render_type' => 'lookup', 'display_field' => 'wo_no', 'json_config' => ['fill' => ['amount' => 'total_cost']]]],
            ['amount', 'Amount (cost or sale price)', 'decimal', ['min_value' => '0', 'default_value' => '0'] + $money],
            ['new_value', 'New value (fair / recoverable)', 'decimal', ['min_value' => '0'] + $money],
            ['counterparty', 'Supplier / buyer', 'string'],
            ['invoice_no', 'Invoice', 'string'],
            ['offset_account', 'Paid from / to (account)', 'string', $account],
            ['book_value_before', 'Book value before', 'decimal', $money],
            ['book_value_after', 'Book value after', 'decimal', $money],
            ['reserve_amount', 'To / from surplus', 'decimal', $money],
            ['pl_amount', 'To profit or loss (gain + / loss −)', 'decimal', $money],
            ['status', 'Status', 'string', $options(self::TXN_STATUSES, 'draft')],
            ['reason', 'Reason', 'string'],
        ]);

        $columns = [
            ['id', 'ID', 'integer', $key],
            ['txn_no', 'Number', 'string', ['is_criteria' => true]],
            ['txn_type', 'Transaction', 'string', ['is_criteria' => true, 'json_config' => ['options' => self::TXN_TYPES]]],
            ['asset_id', 'Asset', 'integer', ['is_criteria' => true] + $asset],
            ['txn_date', 'Date', 'date'],
            ['period', 'Month', 'string', ['is_criteria' => true]],
            ['amount', 'Amount', 'decimal', $money],
            ['book_value_before', 'Before', 'decimal', $money],
            ['book_value_after', 'After', 'decimal', $money],
            ['counterparty', 'Supplier / buyer', 'string', ['is_criteria' => true]],
            ['reason', 'Reason', 'string'],
            ['status', 'Status', 'string', ['render_type' => 'status', 'is_criteria' => true, 'json_config' => ['options' => self::TXN_STATUSES]]],
        ];
        $grid = $this->meta('eam_transactions_grid', 'Asset transactions', 'datagrid', 'eam_asset_transactions', 'Capitalizations, depreciation, revaluations and devaluations.', null, ['filter_layout' => 'sidebar']);
        $this->actions($grid, $form);
        $this->button($grid, $post, 'Post', 'check', true, ['txn_id' => 'id'], 4);
        $this->button($grid, $run, 'Run depreciation', null, false, null, 5);
        $this->params($grid, $columns);

        $assetGrid = $this->meta('eam_asset_transactions_fin_grid', 'Asset finance', 'datagrid', 'eam_asset_transactions', 'The finance transactions of one asset.');
        $this->actions($assetGrid, $form);
        $this->button($assetGrid, $post, 'Post', 'check', true, ['txn_id' => 'id'], 4);
        $this->params($assetGrid, array_map(fn ($c) => $c[0] === 'asset_id' ? [$c[0], $c[1], $c[2], ['is_required' => true, 'is_show' => false]] : $c, $columns));

        // Registering an asset with a cost and a purchase date leaves a draft purchase to post.
        $assetForm = Meta::where('code', 'eam_asset_form')->firstOrFail();
        MetaProcessWorkflow::where('main_process_id', $assetForm->id)->delete();
        $step = MetaProcessWorkflow::create(['main_process_id' => $assetForm->id, 'process_id' => $form->id, 'order_number' => 1]);
        MetaProcessTransition::create(['main_process_id' => $assetForm->id, 'source_id' => null, 'target_id' => $step->id, 'criteria' => [
            ['field' => 'purchase_cost', 'op' => '>', 'value' => '0'],
            ['field' => 'purchase_date', 'op' => 'not_empty'],
        ]]);
        foreach (['txn_type' => '=purchase', 'asset_id' => 'id', 'amount' => 'purchase_cost', 'txn_date' => 'purchase_date', 'book_value_before' => '=0', 'reason' => '=Purchase (registered with the asset)'] as $target => $source) {
            MetaProcessBehaviour::create(['workflow_id' => $step->id, 'source_path' => $source, 'target_path' => $target]);
        }

        $bookValues = $this->bookValues();
        $journal = $this->journal();

        $workspace = Meta::where('code', 'eam_asset_workspace')->firstOrFail();
        MetaParam::updateOrCreate(
            ['main_meta_id' => $workspace->id, 'field_path' => 'finance', 'parent_id' => null],
            ['param_name' => 'finance', 'label_name' => 'Finance', 'data_type' => 'string', 'icon_cls' => 'receipt', 'ref_meta_id' => $assetGrid->id, 'order_number' => 5, 'is_show' => true, 'json_config' => ['scope' => ['asset_id' => 'id']]],
        );

        // A "Finance" group in the app's menu, before Setup.
        MetaParam::where('main_meta_id', $app->id)->where('field_path', 'finance')->whereNull('parent_id')->get()->each(function (MetaParam $group) {
            MetaParam::where('parent_id', $group->id)->delete();
            $group->delete();
        });
        $setupOrder = (int) MetaParam::where('main_meta_id', $app->id)->where('field_path', 'setup')->whereNull('parent_id')->value('order_number');
        $group = MetaParam::create(['main_meta_id' => $app->id, 'field_path' => 'finance', 'param_name' => 'finance', 'label_name' => 'Finance', 'data_type' => 'string', 'icon_cls' => 'receipt', 'order_number' => max($setupOrder - 1, 0), 'is_show' => true]);
        foreach ([
            ['transactions', 'Asset transactions', $grid],
            ['depreciation_preview', 'Depreciation preview', $preview],
            ['run_depreciation', 'Run depreciation', $run],
            ['book_values', 'Book values', $bookValues],
            ['journal', 'Journal', $journal],
            ['accounts', 'Accounts & rules', $accounts],
        ] as $i => [$field, $label, $meta]) {
            MetaParam::create(['main_meta_id' => $app->id, 'parent_id' => $group->id, 'field_path' => $field, 'param_name' => $field, 'label_name' => $label, 'data_type' => 'string', 'ref_meta_id' => $meta->id, 'order_number' => $i + 1, 'is_show' => true]);
        }

        return $post;
    }

    /** Each asset's balances, grouped by category. */
    private function bookValues(): Meta
    {
        $statement = $this->meta('eam_book_values', 'Book values', 'statement', 'eam_assets', 'Each asset’s cost, revaluation, depreciation and impairment, grouped by category.', null, [
            'group_by' => ['category_name'],
            'sort' => [['field' => 'asset_tag', 'dir' => 'asc']],
        ], <<<'SQL'
            SELECT a.id, COALESCE(c.name, 'No category') AS category_name, a.asset_tag, a.name, a.capitalized_on,
                   a.capitalized_cost, a.revaluation_amount, a.accumulated_depreciation, a.accumulated_impairment,
                   a.carrying_amount, a.revaluation_surplus, a.status
            FROM eam_assets a
            LEFT JOIN eam_categories c ON c.id = a.category_id
            WHERE a.capitalized_on IS NOT NULL AND a.status <> 'disposed'
              AND (:location_id IS NULL OR a.location_id = :location_id)
            SQL);
        $sum = ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']];
        $this->params($statement, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['category_name', 'Category', 'string'],
            ['asset_tag', 'Tag', 'string', ['json_config' => ['aggregate' => 'count']]],
            ['name', 'Asset', 'string'],
            ['capitalized_on', 'Capitalized', 'date'],
            ['capitalized_cost', 'Cost', 'decimal', $sum],
            ['revaluation_amount', 'Revaluation', 'decimal', $sum],
            ['accumulated_depreciation', 'Acc. depreciation', 'decimal', $sum],
            ['accumulated_impairment', 'Acc. impairment', 'decimal', $sum],
            ['carrying_amount', 'Book value', 'decimal', $sum],
            ['revaluation_surplus', 'Surplus', 'decimal', $sum],
            ['status', 'Status', 'string', ['render_type' => 'status']],
            ['location_id', 'Location', 'integer', ['is_show' => false, 'is_criteria' => true, 'is_select' => true, 'render_type' => 'lookup',
                'ref_meta_id' => Meta::where('code', 'eam_locations_grid')->value('id'), 'display_field' => 'name']],
        ]);

        return $statement;
    }

    /** Journal lines grouped by account, with debit, credit and balance totals. */
    private function journal(): Meta
    {
        $statement = $this->meta('eam_journal', 'Journal', 'statement', 'eam_journal_entries', 'Every fixed asset posting, by account.', null, [
            'group_by' => ['account_name'],
            'sort' => [['field' => 'entry_date', 'dir' => 'asc']],
        ], <<<'SQL'
            SELECT j.id, COALESCE(ac.code, j.account_code) AS account_code, COALESCE(ac.name, j.account_code) AS account_name,
                   j.entry_date, j.memo AS txn_no, j.txn_type, a.name AS asset_name, j.debit, j.credit, j.debit - j.credit AS balance
            FROM eam_journal_entries j
            LEFT JOIN eam_accounts ac ON ac.code = j.account_code
            LEFT JOIN eam_assets a ON a.id = j.asset_id
            WHERE (:from_date IS NULL OR j.entry_date >= :from_date)
              AND (:to_date IS NULL OR j.entry_date <= :to_date)
              AND (:txn_type IS NULL OR j.txn_type = :txn_type)
            SQL);
        $sum = ['render_type' => 'money', 'json_config' => ['aggregate' => 'sum']];
        $this->params($statement, [
            ['id', 'ID', 'integer', ['is_primary' => true, 'is_show' => false]],
            ['account_name', 'Account', 'string'],
            ['account_code', 'Code', 'string'],
            ['entry_date', 'Date', 'date'],
            ['txn_no', 'Transaction', 'string', ['json_config' => ['aggregate' => 'count']]],
            ['txn_type', 'Type', 'string', ['is_criteria' => true, 'render_type' => 'select', 'json_config' => ['options' => self::TXN_TYPES]]],
            ['asset_name', 'Asset', 'string'],
            ['debit', 'Debit', 'decimal', $sum],
            ['credit', 'Credit', 'decimal', $sum],
            ['balance', 'Balance', 'decimal', $sum],
            ['from_date', 'From', 'date', ['is_show' => false, 'is_criteria' => true]],
            ['to_date', 'To', 'date', ['is_show' => false, 'is_criteria' => true]],
        ]);

        return $statement;
    }

    private function button(Meta $grid, Meta $query, string $label, ?string $icon, bool $needsRow, ?array $params, int $order): void
    {
        MetaAction::create([
            'grid_meta_id' => $grid->id,
            'action' => 'stage_query',
            'label' => $label,
            'icon_cls' => $icon,
            'form_meta_id' => $query->id,
            'needs_row' => $needsRow,
            'show_in_row' => $needsRow,
            'order_number' => $order,
            'json_config' => $params ? ['params' => $params] : null,
        ]);
    }

    private function meta(string $code, string $name, string $type, ?string $table, string $description, ?Meta $opens = null, ?array $config = null, ?string $query = null): Meta
    {
        return Meta::updateOrCreate(['code' => $code], [
            'name' => $name,
            'type_id' => $this->types[$type],
            'table_name' => $table,
            'description' => $description,
            'ref_meta_id' => $opens?->id,
            'json_config' => $config,
            'query_string' => $query,
            'is_active' => true,
        ]);
    }

    /** Replaces a meta's params. Each row: [field_path, label, data_type, extra attributes]. */
    private function params(Meta $meta, array $rows): void
    {
        MetaParam::where('main_meta_id', $meta->id)->delete();

        foreach ($rows as $i => $row) {
            [$field, $label, $type] = $row;
            // The row's own attributes win, e.g. is_show false for a hidden filter.
            MetaParam::create(($row[3] ?? []) + [
                'main_meta_id' => $meta->id,
                'order_number' => $i + 1,
                'field_path' => $field,
                'param_name' => $field,
                'label_name' => $label,
                'data_type' => $type,
                'is_show' => true,
            ]);
        }
    }

    private function setupData(): void
    {
        $now = now();
        foreach (self::ACCOUNTS as [$code, $name, $kind]) {
            DB::table('eam_accounts')->updateOrInsert(['code' => $code], ['name' => $name, 'kind' => $kind, 'updated_at' => $now] + (DB::table('eam_accounts')->where('code', $code)->exists() ? [] : ['created_at' => $now]));
        }
        foreach (self::RULES as [$type, $part, $debit, $credit, $description]) {
            if (! DB::table('eam_posting_rules')->where('txn_type', $type)->where('part', $part)->exists()) {
                DB::table('eam_posting_rules')->insert(['txn_type' => $type, 'part' => $part, 'debit_account' => $debit, 'credit_account' => $credit, 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /**
     * Each asset's history, posted through the same SQL as the Post button: bought from a
     * supplier, depreciated to the end of last month as one opening entry, then a
     * revaluation, an impairment, a scrapped printer and a sold heater. Three drafts are left
     * to post by hand. Only when there are no transactions yet.
     */
    private function sampleLedger(Meta $post): void
    {
        if (DB::table('eam_asset_transactions')->exists()) {
            return;
        }
        $today = Carbon::today();
        $lastMonthEnd = $today->copy()->startOfMonth()->subDay();
        $lastPeriod = $lastMonthEnd->format('Y-m');
        $assets = DB::table('eam_assets as a')
            ->leftJoin('eam_categories as c', 'c.id', '=', 'a.category_id')
            ->whereNotNull('a.purchase_date')
            ->get(['a.id', 'a.name', 'a.status', 'a.manufacturer', 'a.purchase_date', 'a.purchase_cost', 'a.salvage_value', 'a.useful_life_years', 'c.useful_life_years as category_life']);
        $id = $assets->pluck('id', 'name');
        // Assets the register already shows as disposed are disposed through the ledger below.
        $scrapped = $assets->where('status', 'disposed')->pluck('id')->all();
        DB::table('eam_assets')->whereIn('id', $scrapped)->update(['status' => 'idle']);

        $seq = 0;
        $draft = function (string $type, int $asset, string $date, array $values) use (&$seq): int {
            return DB::table('eam_asset_transactions')->insertGetId($values + [
                'txn_no' => sprintf('%s-%s%04d', self::PREFIXES[$type], Carbon::parse($date)->format('ym'), ++$seq),
                'txn_type' => $type, 'asset_id' => $asset, 'txn_date' => $date, 'status' => 'draft',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $postOne = fn (int $txn) => $this->runSql($post->query_string, ['txn_id' => $txn]);

        foreach ($assets as $a) {
            $postOne($draft('purchase', $a->id, $a->purchase_date, [
                'amount' => $a->purchase_cost, 'offset_account' => '2100', 'counterparty' => "{$a->manufacturer} authorised dealer",
                'invoice_no' => sprintf('INV-%s-%03d', Carbon::parse($a->purchase_date)->format('Ymd'), $a->id), 'reason' => 'Purchase',
            ]));
        }
        foreach ($assets as $a) {
            $months = (int) round(Carbon::parse($a->purchase_date)->startOfMonth()->diffInMonths($lastMonthEnd->copy()->startOfMonth())) + 1;
            $life = ($a->useful_life_years ?? $a->category_life ?? 5) * 12;
            $amount = round(min(($a->purchase_cost - $a->salvage_value) * $months / $life, $a->purchase_cost - $a->salvage_value), 2);
            if ($amount > 0) {
                $postOne(DB::table('eam_asset_transactions')->insertGetId([
                    'txn_no' => sprintf('DEPR-%s-%05d', $lastPeriod, $a->id), 'txn_type' => 'depreciation', 'asset_id' => $a->id,
                    'txn_date' => $lastMonthEnd->toDateString(), 'period' => $lastPeriod, 'amount' => $amount, 'status' => 'draft',
                    'reason' => "Accumulated depreciation to {$lastMonthEnd->toDateString()} (opening balance)", 'created_at' => now(), 'updated_at' => now(),
                ]));
            }
        }

        $value = fn (string $name) => (float) DB::table('eam_assets')->where('id', $id[$name])->value('carrying_amount');
        $date = $today->copy()->subDays(10)->toDateString();
        if (isset($id['Rooftop AC unit'])) {
            $postOne($draft('revaluation', $id['Rooftop AC unit'], $date, ['new_value' => round($value('Rooftop AC unit') + 8000000, -3), 'reason' => 'Independent valuation']));
        }
        if (isset($id['Air compressor'])) {
            $postOne($draft('devaluation', $id['Air compressor'], $date, ['new_value' => round($value('Air compressor') * 0.6, -3), 'reason' => 'Poor condition, repeated failures (impairment test)']));
        }
        foreach ($scrapped as $asset) {
            $postOne($draft('disposal', $asset, $today->copy()->subDays(30)->toDateString(), ['reason' => 'Broken beyond repair, scrapped']));
        }
        if (isset($id['Warehouse heater'])) {
            $postOne($draft('sale', $id['Warehouse heater'], $today->copy()->subDays(5)->toDateString(), [
                'amount' => 2500000, 'offset_account' => '1010', 'counterparty' => 'Steppe Scrap Metals LLC', 'invoice_no' => 'SI-'.$today->format('ymd').'-01', 'reason' => 'Not needed after the plant got central heating',
            ]));
        }
        // Left as drafts, to post by hand.
        if (isset($id['CNC lathe'])) {
            $draft('capitalization', $id['CNC lathe'], $today->toDateString(), ['amount' => 12500000, 'offset_account' => '1600', 'reason' => 'Bar feeder upgrade (from construction in progress)']);
        }
        if (isset($id['Standby generator'])) {
            $draft('revaluation', $id['Standby generator'], $today->toDateString(), ['new_value' => round($value('Standby generator') + 15000000, -3), 'reason' => 'Year-end valuation', 'book_value_before' => $value('Standby generator')]);
        }
        if (isset($id['Delivery truck 1'])) {
            $draft('sale', $id['Delivery truck 1'], $today->toDateString(), [
                'amount' => 45000000, 'counterparty' => 'Gobi Logistics LLC', 'reason' => 'Fleet renewal', 'book_value_before' => $value('Delivery truck 1'),
                'pl_amount' => round(45000000 - $value('Delivery truck 1'), 2), 'book_value_after' => 0,
            ]);
        }
    }

    private function runSql(string $sql, array $values): void
    {
        foreach (StageQuery::statements($sql) as $statement) {
            [$statement, $bindings] = NamedSql::bind($statement, $values + NamedSql::builtins(null));
            DB::affectingStatement($statement, $bindings);
        }
    }
}
