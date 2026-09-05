<?php

declare(strict_types=1);

namespace PYH\Web;

use PDO;
use PYH\Application\AuditService;
use PYH\Application\AuthenticationService;
use PYH\Application\CoreCrmService;
use PYH\Application\DashboardService;
use PYH\Application\OrganisationService;
use PYH\Application\ProposalService;
use PYH\Application\QuoteService;
use PYH\Application\RoleAssignmentService;
use PYH\Application\SearchService;
use PYH\Application\TaskCommunicationService;
use PYH\Security\Actor;
use PYH\Security\ActorProvider;
use PYH\Security\Csrf;
use PYH\Security\Html;
use PYH\Security\PermissionEvaluator;
use PYH\Security\SessionAuthenticator;
use PYH\Security\ScopeEvaluator;
use PYH\Domain\Quote\QuoteStatus;
use RuntimeException;

final class WebApplication
{
    private readonly PermissionEvaluator $permissions;
    private readonly AuditService $audit;

    public function __construct(private readonly PDO $pdo, private readonly string $environment)
    {
        $this->permissions = new PermissionEvaluator();
        $this->audit = new AuditService($pdo, $environment);
    }

    public function run(): void
    {
        $this->startSession();
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($path === '/login') { $this->login(); return; }

        $session = new SessionAuthenticator();
        try {
            $userId = $session->requireUser();
            $identity = (new ActorProvider($this->pdo))->load($userId);
        } catch (RuntimeException) {
            header('Location: /login'); exit;
        }
        $actor = $identity['actor'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::assertValid(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null);
            if ($path === '/logout') { (new AuthenticationService($this->pdo, $session, $this->audit))->logout($actor->userId, $actor->organisationId); header('Location: /login'); return; }
            if (in_array($path, BookingWorkspace::ROUTES, true)) { $id=(new BookingWorkspace($this->pdo,$this->permissions,$this->audit))->mutate($path,$actor); $tab=match($path){'/booking/elements','/booking/amendments'=>'holiday','/booking/payments'=>'payments','/booking/documents'=>'documents','/booking/checklist'=>'checklist','/booking/adjustments'=>$this->permissions->allows($actor,'bookings.finance_view')?'finance':'overview',default=>'finance'}; header('Location: /booking?id='.$id.'&tab='.$tab.'&saved=1'); return; }
            if ($path === '/bookings/convert') {
                $id=(new \PYH\Application\QuoteBookingConversionService($this->pdo,$this->permissions,$this->audit))->convert($actor,(int)($_POST['quote_id']??0),(int)($_POST['handoff_id']??0));
                header('Location: /booking?id='.$id); return;
            }
            if ($path === '/booking') {
                $data=$_POST; unset($data['_csrf'],$data['id']);
                (new \PYH\Application\BookingService($this->pdo,$this->permissions,$this->audit))->update($actor,(int)($_POST['id']??0),$data);
                header('Location: /booking?id='.(int)($_POST['id']??0)); return;
            }
            $this->mutate($path, $actor, $identity['authority_level']);
            header('Location: ' . $path . '?saved=1'); return;
        }

        if ($path === '/booking/document') { $file=(new \PYH\Application\BookingDocumentService($this->pdo,$this->permissions,$this->audit))->download($actor,(int)($_GET['booking_id']??0),(int)($_GET['id']??0)); header('Content-Type: application/octet-stream'); header('X-Content-Type-Options: nosniff'); header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($file['filename'])); readfile($file['path']); return; }
        $this->page($path, $actor, $identity['name']);
    }

    private function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::assertValid(isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : null);
            (new AuthenticationService($this->pdo, new SessionAuthenticator(), $this->audit))->login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            header('Location: /'); return;
        }
        $this->layout('Sign in', '<form method="post" class="card form"><input type="hidden" name="_csrf" value="' . Html::escape(Csrf::token()) . '"><label>Email<input type="email" name="email" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button>Sign in</button></form>', null, null);
    }

    private function mutate(string $path, Actor $actor, int $authorityLevel): void
    {
        $crm = new CoreCrmService($this->pdo, $this->permissions, $this->audit);
        $operations = new TaskCommunicationService($this->pdo, $this->permissions, $this->audit);
        $organisation = new OrganisationService($this->pdo, $this->permissions, $this->audit);
        $data = $_POST;
        if ($path === '/quotes') {
            $quotes = new QuoteService($this->pdo, $this->permissions, $this->audit);
            $proposals = new ProposalService($this->pdo, $this->permissions, $this->audit, $quotes);
            $quoteId = (int) ($_POST['quote_id'] ?? 0); $operation = (string) ($_POST['_operation'] ?? 'create');
            if ($operation === 'create') { $quotes->create($actor, $data); return; }
            if ($operation === 'traveller') { $quotes->attachTraveller($actor, $quoteId, (int) $_POST['traveller_id'], (string) ($_POST['traveller_type'] ?? 'Adult'), isset($_POST['is_lead']), (string) ($_POST['proposal_notes'] ?? '')); return; }
            if ($operation === 'component') { $quotes->addComponent($actor, $quoteId, $data); return; }
            if ($operation === 'adjustment') { $quotes->addAdjustment($actor, $quoteId, (string) $_POST['adjustment_type'], (string) $_POST['amount'], (string) $_POST['reason'], (string) $_POST['visibility']); return; }
            if ($operation === 'compliance') { $evidence=[]; foreach(['total_price_clear','mandatory_charges_included','material_information_present','supplier_identity_present','deposit_balance_clear','significant_terms_present','availability_caveat_present'] as $field){$evidence[$field]=isset($_POST[$field]);} $quotes->reviewCompliance($actor,$quoteId,$evidence,'Actor'); return; }
            if ($operation === 'ready') { $quotes->transition($actor,$quoteId,QuoteStatus::Ready); return; }
            if ($operation === 'proposal') { $proposals->generate($actor,$quoteId); return; }
            if ($operation === 'send') { $proposals->recordSent($actor,$quoteId,(int)$_POST['proposal_version_id'],(string)$_POST['method'],isset($_POST['recipient_display'])?(string)$_POST['recipient_display']:null); return; }
            if ($operation === 'decision') { $proposals->recordDecision($actor,$quoteId,(int)$_POST['proposal_version_id'],QuoteStatus::from((string)$_POST['decision']),'Actor',(string)($_POST['evidence_note']??'')); return; }
            if ($operation === 'revision') { $quotes->createRevision($actor,$quoteId); return; }
            if ($operation === 'handoff') { $proposals->bookingHandoff($actor,$quoteId); return; }
        }
        if ($path === '/enquiries') {
            $data['destinations'] = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['destinations'] ?? '')))));
            $data['children_ages'] = array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) ($_POST['children_ages'] ?? ''))))));
            if (($_POST['_operation'] ?? null) === 'transition') { $crm->transitionEnquiry($actor, (int) ($_POST['id'] ?? 0), \PYH\Domain\Enquiry\EnquiryStatus::from((string) ($_POST['status'] ?? ''))); return; }
            if (($_POST['_operation'] ?? null) === 'reassign') { $crm->reassignEnquiry($actor, (int) ($_POST['id'] ?? 0), $this->nullableInt($_POST['assigned_location_id'] ?? null), $this->nullableInt($_POST['assigned_agent_id'] ?? null)); return; }
        }
        if ($path === '/customers' && ($_POST['_operation'] ?? null) === 'archive') { $crm->archiveCustomer($actor, (int) ($_POST['id'] ?? 0)); return; }
        if ($path === '/tasks' && ($_POST['_operation'] ?? null) === 'complete') { $operations->completeTask($actor, (int) ($_POST['id'] ?? 0)); return; }
        match ($path) {
            '/customers' => isset($_POST['id']) && $_POST['id'] !== '' ? $crm->updateCustomer($actor, (int) $_POST['id'], $data) : $crm->createCustomer($actor, $data),
            '/enquiries' => isset($_POST['id']) && $_POST['id'] !== '' ? $crm->updateEnquiry($actor, (int) $_POST['id'], $data) : $crm->createEnquiry($actor, $data),
            '/tasks' => isset($_POST['id']) && $_POST['id'] !== '' ? $operations->updateTask($actor, (int) $_POST['id'], $data) : $operations->createTask($actor, $data),
            '/communications' => $operations->logCommunication($actor, $_POST),
            '/locations' => $organisation->createLocation($actor, $data),
            '/team' => $organisation->createUser($actor, $data),
            '/consultants' => $organisation->saveConsultantProfile($actor, (int) ($_POST['user_id'] ?? 0), $data),
            '/organisation' => $organisation->updateOrganisation($actor, $data),
            '/roles' => (new RoleAssignmentService($this->pdo, $this->permissions, $this->audit))->assign($actor, (int) ($_POST['target_user_id'] ?? 0), (int) ($_POST['role_id'] ?? 0), $authorityLevel),
            default => throw new RuntimeException('Mutation not found.'),
        };
    }

    private function page(string $path, Actor $actor, string $name): void
    {
        $routes = [
            '/' => ['My Day', fn (): string => $this->dashboard($actor)],
            '/customers' => ['Customers', fn (): string => $this->customers($actor)],
            '/enquiries' => ['Enquiries', fn (): string => $this->enquiries($actor)],
            '/bookings' => ['Bookings', fn (): string => $this->bookings($actor)],
            '/booking' => ['Booking Overview', fn (): string => $this->bookingOverview($actor)],
            '/quotes' => ['Quotes', fn (): string => $this->quotes($actor)],
            '/quote' => ['Quote Builder', fn (): string => $this->quoteBuilder($actor)],
            '/proposal' => ['Proposal Preview', fn (): string => $this->proposal($actor)],
            '/tasks' => ['Tasks', fn (): string => $this->tasks($actor)],
            '/communications' => ['Communications', fn (): string => $this->communications($actor)],
            '/team' => ['Agents & Team', fn (): string => $this->table('users', $actor, 'users.view') . $this->form('/team', ['location_id', 'first_name', 'last_name', 'email', 'password', 'agent_code'])],
            '/consultants' => ['Consultant Profiles', fn (): string => $this->table('consultant_profiles', $actor, 'users.view') . $this->form('/consultants', ['user_id', 'display_name', 'title', 'biography', 'media_reference', 'phone', 'email'])],
            '/locations' => ['Locations', fn (): string => $this->table('locations', $actor, 'locations.view') . $this->form('/locations', ['name', 'internal_code', 'address_line_1', 'city', 'postcode', 'telephone', 'email'])],
            '/organisation' => ['Organisation', fn (): string => $this->table('organisations', $actor, 'organisation.view') . $this->form('/organisation', ['legal_name', 'trading_name', 'telephone', 'email', 'website', 'default_currency', 'timezone'])],
            '/roles' => ['Roles & Permissions', fn (): string => $this->table('roles', $actor, 'roles.view') . $this->form('/roles', ['target_user_id', 'role_id'])],
            '/search' => ['Global Search', fn (): string => $this->search($actor)],
        ];
        if (!isset($routes[$path])) { http_response_code(404); $this->layout('Not found', '<div class="card">Page not found.</div>', $name, $actor); return; }
        [$title, $render] = $routes[$path];
        $this->layout($title, ($render)(), $name, $actor);
    }

    private function dashboard(Actor $actor): string
    {
        $this->permissions->assertAllowed($actor, 'enquiries.view');
        $row = (new DashboardService($this->pdo, $this->permissions))->metrics($actor);
        $bookingCards=''; if ($this->permissions->allows($actor,'bookings.view')) {$attention=(new DashboardService($this->pdo,$this->permissions))->bookingAttention($actor);$bookingCards=$this->metric('Active bookings',(string)$attention['active_bookings']).$this->metric('Checklist overdue',(string)$attention['checklist_overdue']);}
        return '<div class="cards">' . $bookingCards . $this->metric('Active enquiries', (string) $row['active_enquiries']) . $this->metric('Tasks due', (string) $row['tasks_due']) . $this->metric('Recent customers', (string) $row['recent_customers']) . '</div>';
    }

    private function customers(Actor $actor): string
    {
        $this->permissions->assertAllowed($actor, 'customers.view');
        $rows = $this->scopedRows('customers', $actor, 'owning_location_id', 'owning_agent_id');
        return $this->rows($rows, ['id', 'first_name', 'last_name', 'email', 'phone']) . $this->form('/customers', ['id', 'title', 'first_name', 'last_name', 'email', 'phone', 'alternate_phone', 'address_line_1', 'city', 'postcode', 'preferred_contact_method', 'notes']) . $this->actionForm('/customers', 'archive', ['id'], 'Archive customer');
    }

    private function enquiries(Actor $actor): string
    {
        $this->permissions->assertAllowed($actor, 'enquiries.view');
        $rows = $this->scopedRows('enquiries', $actor, 'assigned_location_id', 'assigned_agent_id', "status IN ('New','Contacted','Quoted')");
        return '<p class="hint">Live workspace: New · Contacted · Quoted. Enter an ID to edit an existing brief.</p>' . $this->rows($rows, ['id', 'reference', 'status', 'product_type', 'customer_id']) . $this->enquiryForm() . $this->actionForm('/enquiries', 'transition', ['id', 'status'], 'Change enquiry status') . $this->actionForm('/enquiries', 'reassign', ['id', 'assigned_location_id', 'assigned_agent_id'], 'Reassign enquiry');
    }

    private function tasks(Actor $actor): string
    {
        $this->permissions->assertAllowed($actor, 'tasks.view');
        $rows = $this->scopedRows('tasks', $actor, 'location_id', 'assigned_user_id');
        return $this->rows($rows, ['id', 'title', 'priority', 'status', 'due_at_utc']) . $this->form('/tasks', ['id', 'title', 'description', 'due_at_utc', 'priority', 'status', 'related_entity_type', 'related_entity_id']) . $this->actionForm('/tasks', 'complete', ['id'], 'Complete task');
    }

    private function communications(Actor $actor): string
    {
        $this->permissions->assertAllowed($actor, 'communications.view');
        $scope = (new ScopeEvaluator())->sql($actor, 'c.owning_location_id', 'c.owning_agent_id', 'communications');
        $statement = $this->pdo->prepare("SELECT cm.id, cm.customer_id, cm.communication_type, cm.direction, cm.subject, cm.occurred_at_utc FROM communications cm JOIN customers c ON c.id=cm.customer_id WHERE cm.organisation_id=:org AND {$scope['sql']} ORDER BY cm.occurred_at_utc DESC LIMIT 50");
        $params = ['org' => $actor->organisationId, ...$scope['parameters']];
        $statement->execute($params);
        return $this->rows(array_values($statement->fetchAll()), ['id', 'customer_id', 'communication_type', 'direction', 'subject', 'occurred_at_utc']) . $this->form('/communications', ['customer_id', 'enquiry_id', 'direction', 'communication_type', 'subject', 'body', 'occurred_at_utc']);
    }

    private function search(Actor $actor): string
    {
        $query = (string) ($_GET['q'] ?? '');
        $rows = $query === '' ? [] : (new SearchService($this->pdo, $this->permissions))->search($actor, $query);
        return '<form method="get" class="card search"><label>Search securely<input name="q" value="' . Html::escape($query) . '" minlength="2"></label><button>Search</button></form>' . $this->rows($rows, ['result_type', 'id', 'label', 'detail']);
    }

    private function quotes(Actor $actor): string
    {
        $this->permissions->assertAllowed($actor,'quotes.view');$rows=$this->scopedRows('quotes',$actor,'location_id','assigned_agent_id');
        return $this->rows($rows,['id','reference','title','product_type','status','customer_total']).$this->form('/quotes',['customer_id','enquiry_id','title','product_type','destination_summary','departure_date','return_date','departure_point','currency','expires_at_utc','customer_introduction','customer_notes','internal_notes']);
    }

    private function quoteBuilder(Actor $actor): string
    {
        $id=(int)($_GET['id']??0);$quote=(new QuoteService($this->pdo,$this->permissions,$this->audit))->quote($actor,$id);$token=Html::escape(Csrf::token());$hidden='<input type="hidden" name="_csrf" value="'.$token.'"><input type="hidden" name="quote_id" value="'.$id.'">';
        $sections=['Overview','Travellers','Holiday Arrangements','Extras','Pricing','Review & Send'];$nav='<nav class="builder">';foreach($sections as $section){$nav.='<a href="#'.Html::escape(strtolower(str_replace(' ','-',$section))).'">'.Html::escape($section).'</a>';}$nav.='</nav>';
        $traveller='<section id="travellers" class="card"><h2>Travellers</h2><form method="post" action="/quotes" class="form grid">'.$hidden.'<input type="hidden" name="_operation" value="traveller"><label>Traveller ID<input name="traveller_id" required></label><label>Classification<select name="traveller_type"><option>Adult</option><option>Child</option><option>Infant</option></select></label><label><input type="checkbox" name="is_lead"> Lead traveller</label><button>Attach traveller</button></form></section>';
        $component='<section id="holiday-arrangements" class="card"><h2>Holiday Arrangements & Extras</h2><form method="post" action="/quotes" class="form grid">'.$hidden.'<input type="hidden" name="_operation" value="component">'.$this->controls(['component_type','title','display_order','supplier','supplier_reference','start_at_utc','end_at_utc','origin','destination','customer_description','inclusion_state','selling_price','supplier_cost','commission_amount']).'<button>Add arrangement</button></form></section>';
        $pricing='<section id="pricing" class="card"><h2>Pricing</h2><p>Customer total: '.Html::escape((string)$quote['currency'].' '.$quote['customer_total']).' · Optional: '.Html::escape((string)$quote['optional_total']).'</p><form method="post" action="/quotes" class="form grid">'.$hidden.'<input type="hidden" name="_operation" value="adjustment">'.$this->controls(['adjustment_type','amount','reason','visibility']).'<button>Add governed adjustment</button></form></section>';
        $checks='';foreach(['total_price_clear','mandatory_charges_included','material_information_present','supplier_identity_present','deposit_balance_clear','significant_terms_present','availability_caveat_present'] as $field){$checks.='<label><input type="checkbox" name="'.$field.'">'.Html::escape(ucwords(str_replace('_',' ',$field))).'</label>';}
        $review='<section id="review-&-send" class="card"><h2>Review & Send</h2><form method="post" action="/quotes" class="form">'.$hidden.'<input type="hidden" name="_operation" value="compliance">'.$checks.'<button>Run compliance</button></form>'.$this->quoteAction($hidden,'ready',[],'Mark Ready').$this->quoteAction($hidden,'proposal',[],'Generate proposal').$this->quoteAction($hidden,'send',['proposal_version_id','method','recipient_display'],'Record Sent').$this->quoteAction($hidden,'decision',['proposal_version_id','decision','evidence_note'],'Record decision').$this->quoteAction($hidden,'revision',[],'Create revision').$this->quoteAction($hidden,'handoff',[],'Generate booking handoff').'</section>';
        if ($quote['status']==='Accepted' && $this->permissions->allows($actor,'bookings.create')) {
            $h=$this->pdo->prepare("SELECT id FROM quote_booking_handoffs WHERE quote_id=? AND readiness_status='Ready' ORDER BY id DESC");$h->execute([$id]);
            foreach($h->fetchAll() as $handoff){$review.='<form method="post" action="/bookings/convert" class="card form">'.$hidden.'<input type="hidden" name="handoff_id" value="'.(int)$handoff['id'].'"><button>Create Booking</button></form>';}
        }
        return $nav.'<section id="overview" class="card"><span class="status">'.Html::escape((string)$quote['status']).'</span><h2>'.Html::escape((string)$quote['reference'].' · '.$quote['title']).'</h2></section>'.$traveller.$component.$pricing.$review;
    }

    private function bookings(Actor $actor): string
    {
        $service=new \PYH\Application\BookingService($this->pdo,$this->permissions,$this->audit);
        $out='';
        foreach($service->list($actor) as $row){
            $out.='<section class="card"><h2><a href="/booking?id='.(int)$row['id'].'">'.Html::escape((string)$row['booking_reference']).'</a></h2>';
            $row['balance']=$service->totals($actor,(int)$row['id'])['balance'];
            $out.=$this->rows([$row],['customer','product_type','supplier_name','departure_date','status','balance','assigned_agent']).'</section>';
        }
        return $out?:'<p class="card">No bookings yet.</p>';
    }

    private function bookingOverview(Actor $actor): string
    {
        return (new BookingWorkspace($this->pdo,$this->permissions,$this->audit))->render($actor,(int)($_GET['id']??0),(string)($_GET['tab']??'overview'));
    }

    private function proposal(Actor $actor): string
    {
        $quoteId=(int)($_GET['quote_id']??0);(new QuoteService($this->pdo,$this->permissions,$this->audit))->quote($actor,$quoteId);$id=(int)($_GET['id']??0);$s=$this->pdo->prepare('SELECT rendered_html FROM proposal_versions WHERE id=:id AND quote_id=:quote');$s->execute(['id'=>$id,'quote'=>$quoteId]);$html=$s->fetchColumn();if(!is_string($html)){throw new RuntimeException('Proposal not found.');}return '<iframe title="Customer proposal" sandbox="" srcdoc="'.Html::escape($html).'"></iframe>';
    }

    /** @param list<string> $fields */ private function controls(array $fields):string{$out='';foreach($fields as $field){$out.='<label>'.Html::escape(ucwords(str_replace('_',' ',$field))).'<input name="'.Html::escape($field).'"></label>';}return$out;}
    /** @param list<string> $fields */ private function quoteAction(string $hidden,string $operation,array $fields,string $label):string{return '<form method="post" action="/quotes" class="form grid">'.$hidden.'<input type="hidden" name="_operation" value="'.Html::escape($operation).'">'.$this->controls($fields).'<button>'.Html::escape($label).'</button></form>';}

    private function table(string $table, Actor $actor, string $permission): string
    {
        $this->permissions->assertAllowed($actor, $permission);
        $sql = match ($table) {
            'organisations' => 'SELECT * FROM organisations WHERE id=:org LIMIT 50',
            'consultant_profiles' => 'SELECT cp.* FROM consultant_profiles cp JOIN users u ON u.id=cp.user_id WHERE u.organisation_id=:org LIMIT 50',
            'roles' => 'SELECT * FROM roles WHERE organisation_id=:org OR organisation_id IS NULL LIMIT 50',
            default => "SELECT * FROM {$table} WHERE organisation_id=:org LIMIT 50",
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['org' => $actor->organisationId]);
        $rows = array_values($statement->fetchAll());
        $columns = $rows === [] ? [] : array_map(static fn (int|string $key): string => (string) $key, array_slice(array_keys($rows[0]), 0, 6));
        return $this->rows($rows, $columns);
    }

    /** @return list<array<string, mixed>> */
    private function scopedRows(string $table, Actor $actor, string $locationColumn, string $ownerColumn, string $extra = '1=1'): array
    {
        $scope = (new ScopeEvaluator())->sql($actor, $locationColumn, $ownerColumn, 'list');
        $statement = $this->pdo->prepare("SELECT * FROM {$table} WHERE organisation_id=:org AND {$extra} AND {$scope['sql']} ORDER BY id DESC LIMIT 50");
        $params = ['org' => $actor->organisationId, ...$scope['parameters']];
        $statement->execute($params);
        return array_values($statement->fetchAll());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     */
    private function rows(array $rows, array $columns): string
    {
        if ($rows === []) { return '<div class="card empty">No records yet.</div>'; }
        $head = implode('', array_map(static fn (string $v): string => '<th>' . Html::escape(ucwords(str_replace('_', ' ', $v))) . '</th>', $columns));
        $body = '';
        foreach ($rows as $row) { $body .= '<tr>' . implode('', array_map(static fn (string $key): string => '<td>' . Html::escape(is_scalar($row[$key] ?? null) ? (string) $row[$key] : '') . '</td>', $columns)) . '</tr>'; }
        return '<div class="card table-wrap"><table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    /** @param list<string> $fields */
    private function form(string $action, array $fields): string
    {
        $controls = '';
        foreach ($fields as $field) { $type = str_contains($field, 'password') ? 'password' : (str_contains($field, 'date') || str_contains($field, '_at') ? 'text' : ($field === 'email' ? 'email' : 'text')); $controls .= '<label>' . Html::escape(ucwords(str_replace('_', ' ', $field))) . '<input type="' . $type . '" name="' . Html::escape($field) . '"></label>'; }
        return '<details class="card"><summary>Create record</summary><form method="post" action="' . Html::escape($action) . '" class="form grid"><input type="hidden" name="_csrf" value="' . Html::escape(Csrf::token()) . '">' . $controls . '<button>Save</button></form></details>';
    }

    private function enquiryForm(): string
    {
        $token = Html::escape(Csrf::token());
        return '<details class="card"><summary>Create or edit guided enquiry</summary><form method="post" action="/enquiries" class="form"><input type="hidden" name="_csrf" value="' . $token . '"><fieldset><legend>Step 1 — Customer</legend><label>Existing enquiry ID (edit only)<input name="id"></label><label>Customer ID<input name="customer_id"></label></fieldset><fieldset><legend>Step 2 — Core holiday brief</legend><div class="grid"><label>Product type<input name="product_type" required></label><label>Departure point<input name="departure_point"></label><label>Destinations, comma separated<input name="destinations" required></label><label>Preferred start date<input name="preferred_start_date"></label><label>Flexibility<input name="flexibility"></label><label>Duration nights<input name="duration_nights"></label><label>Adults<input name="adults" value="1"></label><label>Children<input name="children" value="0"></label><label>Children ages<input name="children_ages"></label><label>Budget<input name="budget_amount"></label><label>Must-haves<input name="must_haves"></label></div></fieldset><fieldset><legend>Step 3 — Additional requirements</legend><div class="grid"><label>Accessibility<input name="accessibility_requirements"></label><label>Celebrations<input name="special_occasions"></label><label>Preferences<input name="preferences"></label><label>Additional notes<input name="additional_notes"></label><label>Preferred contact method<input name="preferred_contact_method"></label><label>Lead source<input name="lead_source"></label></div></fieldset><button>Save enquiry</button></form></details>';
    }

    /** @param list<string> $fields */
    private function actionForm(string $action, string $operation, array $fields, string $label): string
    {
        $controls = '';
        foreach ($fields as $field) { $controls .= '<label>' . Html::escape(ucwords(str_replace('_', ' ', $field))) . '<input name="' . Html::escape($field) . '" required></label>'; }
        return '<details class="card"><summary>' . Html::escape($label) . '</summary><form method="post" action="' . Html::escape($action) . '" class="form grid"><input type="hidden" name="_csrf" value="' . Html::escape(Csrf::token()) . '"><input type="hidden" name="_operation" value="' . Html::escape($operation) . '">' . $controls . '<button>' . Html::escape($label) . '</button></form></details>';
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function metric(string $label, string $value): string { return '<section class="card metric"><strong>' . Html::escape($value) . '</strong><span>' . Html::escape($label) . '</span></section>'; }

    private function layout(string $title, string $content, ?string $name, ?Actor $actor): void
    {
        $nav = '';
        if ($actor !== null) {
            $links = ['/' => ['My Day', 'enquiries.view'], '/customers' => ['Customers', 'customers.view'], '/enquiries' => ['Enquiries', 'enquiries.view'], '/quotes' => ['Quotes', 'quotes.view'], '/bookings' => ['Bookings', 'bookings.view'], '/tasks' => ['Tasks', 'tasks.view'], '/communications' => ['Communications', 'communications.view'], '/team' => ['Agents & Team', 'users.view'], '/consultants' => ['Consultants', 'users.view'], '/locations' => ['Locations', 'locations.view'], '/organisation' => ['Organisation', 'organisation.view'], '/roles' => ['Roles', 'roles.view'], '/search' => ['Search', null]];
            $nav = '<nav>';
            foreach ($links as $url => [$label, $permission]) {
                if ($permission === null || $this->permissions->allows($actor, $permission)) { $nav .= '<a href="' . $url . '">' . Html::escape($label) . '</a>'; }
            }
            $nav .= '<form method="post" action="/logout"><input type="hidden" name="_csrf" value="' . Html::escape(Csrf::token()) . '"><button>Log out</button></form></nav>';
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . Html::escape($title) . ' · PYH</title><link rel="stylesheet" href="/assets/app.css"></head><body>' . $nav . '<main><header><div><small>Plan Your Holiday V16</small><h1>' . Html::escape($title) . '</h1></div>' . ($name === null ? '' : '<span>' . Html::escape($name) . '</span>') . '</header>' . (isset($_GET['saved']) ? '<p role="status" class="saved">Saved successfully.</p>' : '') . $content . '</main></body></html>';
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'secure' => !in_array($this->environment, ['local', 'test'], true), 'samesite' => 'Lax']);
            session_start();
        }
    }
}
