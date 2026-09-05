<?php

declare(strict_types=1);

namespace PYH\Application;

use PDO;
use PYH\Domain\Quote\QuoteStatus;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use RuntimeException;

final class ProposalService
{
    public function __construct(private readonly PDO $pdo,private readonly PermissionEvaluator $permissions,private readonly AuditService $audit,private readonly QuoteService $quotes){}

    public function generate(Actor $actor,int $quoteId): int
    {
        $this->permissions->assertAllowed($actor,'quotes.send');$quote=$this->quotes->quote($actor,$quoteId);if(!in_array($quote['status'],['Ready','Draft'],true)){throw new RuntimeException('Only a draft or ready quote can generate a proposal.');}
        $snapshot=$this->customerSnapshot($quote);$json=json_encode($snapshot,JSON_THROW_ON_ERROR);$html=(new ProposalRenderer())->render($snapshot);$hash=hash('sha256',$json);
        $this->pdo->prepare('INSERT INTO proposal_versions (quote_id,version_number,snapshot,rendered_html,snapshot_sha256,generated_by_user_id) VALUES (:quote,:version,:snapshot,:html,:hash,:actor)')->execute(['quote'=>$quoteId,'version'=>$quote['revision_number'],'snapshot'=>$json,'html'=>$html,'hash'=>$hash,'actor'=>$actor->userId]);$id=(int)$this->pdo->lastInsertId();$this->audit->record($actor->organisationId,$actor->userId,'quote.proposal_generated','quote',$quoteId,null,['proposal_version_id'=>$id,'version'=>$quote['revision_number'],'sha256'=>$hash]);return$id;
    }

    public function recordSent(Actor $actor,int $quoteId,int $proposalId,string $method,?string $recipient,string $note=''):int
    {
        $this->permissions->assertAllowed($actor,'quotes.send');$q=$this->quotes->quote($actor,$quoteId);if($q['status']!=='Ready'){throw new RuntimeException('Quote must be Ready before sending.');}if($this->quotes->readiness($actor,$quoteId)!==[]){throw new RuntimeException('Quote is not ready.');}
        $p=$this->proposal($quoteId,$proposalId);if((int)$p['version_number']!==(int)$q['revision_number']){throw new RuntimeException('Only the current proposal version may be sent.');}
        $this->pdo->beginTransaction();try{$this->pdo->prepare('UPDATE proposal_versions SET finalised_at_utc=COALESCE(finalised_at_utc,UTC_TIMESTAMP(6)) WHERE id=:id')->execute(['id'=>$proposalId]);$this->pdo->prepare('INSERT INTO proposal_deliveries (proposal_version_id,method,recipient_display,delivery_status,delivery_note,sent_by_user_id) VALUES (:proposal,:method,:recipient,\'Recorded Sent\',:note,:actor)')->execute(['proposal'=>$proposalId,'method'=>$method,'recipient'=>$recipient,'note'=>$note?:null,'actor'=>$actor->userId]);$id=(int)$this->pdo->lastInsertId();$this->quotes->transition($actor,$quoteId,QuoteStatus::Sent);$this->audit->record($actor->organisationId,$actor->userId,'quote.proposal_sent','quote',$quoteId,null,['proposal_version_id'=>$proposalId,'method'=>$method]);$this->pdo->commit();return$id;}catch(\Throwable $e){if($this->pdo->inTransaction()){$this->pdo->rollBack();}throw$e;}
    }

    public function recordDecision(Actor $actor,int $quoteId,int $proposalId,QuoteStatus $decision,string $source,string $note=''):int
    {
        if(!in_array($decision,[QuoteStatus::Accepted,QuoteStatus::Declined,QuoteStatus::Expired],true)){throw new RuntimeException('Unsupported customer decision.');}$this->proposal($quoteId,$proposalId);$this->quotes->transition($actor,$quoteId,$decision);
        $this->pdo->prepare('INSERT INTO quote_decisions (quote_id,proposal_version_id,decision,evidence_note,source,recorded_by_user_id) VALUES (:quote,:proposal,:decision,:note,:source,:actor)')->execute(['quote'=>$quoteId,'proposal'=>$proposalId,'decision'=>$decision->value,'note'=>$note?:null,'source'=>$source,'actor'=>$actor->userId]);$id=(int)$this->pdo->lastInsertId();$this->audit->record($actor->organisationId,$actor->userId,'quote.'.strtolower($decision->value),'quote',$quoteId,null,['proposal_version_id'=>$proposalId,'source'=>$source]);return$id;
    }

    public function bookingHandoff(Actor $actor,int $quoteId):int
    {
        $this->permissions->assertAllowed($actor,'quotes.convert_ready');$q=$this->quotes->quote($actor,$quoteId);if($q['status']!=='Accepted'){throw new RuntimeException('Only an accepted quote can create a booking handoff.');}
        $decision=$this->one("SELECT * FROM quote_decisions WHERE quote_id=:id AND decision='Accepted' ORDER BY id DESC LIMIT 1",['id'=>$quoteId]);$proposal=$this->proposal($quoteId,(int)$decision['proposal_version_id']);$snapshot=json_decode((string)$proposal['snapshot'],true,512,JSON_THROW_ON_ERROR);
        $snapshot['commercial']=$this->all('SELECT id,supplier,supplier_reference,supplier_cost,commission_amount FROM quote_components WHERE quote_id=:id ORDER BY display_order',['id'=>$quoteId]);$snapshot['handoff']=['quote_id'=>$quoteId,'accepted_proposal_version_id'=>(int)$proposal['id'],'accepted_at_utc'=>$decision['decided_at_utc'],'agent_id'=>$q['assigned_agent_id']];$json=json_encode($snapshot,JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
        $this->pdo->prepare("INSERT INTO quote_booking_handoffs (quote_id,accepted_proposal_version_id,readiness_status,blocking_reasons,snapshot,snapshot_sha256,generated_by_user_id) VALUES (:quote,:proposal,'Ready','[]',:snapshot,:hash,:actor)")->execute(['quote'=>$quoteId,'proposal'=>$proposal['id'],'snapshot'=>$json,'hash'=>$hash,'actor'=>$actor->userId]);$id=(int)$this->pdo->lastInsertId();$this->audit->record($actor->organisationId,$actor->userId,'quote.booking_handoff_generated','quote',$quoteId,null,['handoff_id'=>$id,'proposal_version_id'=>$proposal['id'],'sha256'=>$hash]);return$id;
    }

    /**
     * @param array<string,mixed> $q
     * @return array<string,mixed>
     */
    private function customerSnapshot(array $q):array
    {
        $customer=$this->one('SELECT first_name,last_name,email FROM customers WHERE id=:id',['id'=>$q['customer_id']]);$travellers=$this->all("SELECT CONCAT(first_name_snapshot,' ',last_name_snapshot) display_name,traveller_id,display_order,title_snapshot,first_name_snapshot,last_name_snapshot,date_of_birth_snapshot,traveller_type,is_lead,proposal_notes FROM quote_travellers WHERE quote_id=:id ORDER BY display_order",['id'=>$q['id']]);
        $components=$this->all('SELECT id,component_type,title,start_at_utc,end_at_utc,origin,destination,customer_description,customer_notes,inclusion_state,selling_price FROM quote_components WHERE quote_id=:id AND (inclusion_state=\'Included\' OR is_selected=TRUE) ORDER BY display_order,start_at_utc',['id'=>$q['id']]);$optional=$this->all("SELECT title,selling_price FROM quote_components WHERE quote_id=:id AND inclusion_state='Optional' AND is_selected=FALSE ORDER BY display_order",['id'=>$q['id']]);
        $consultant=$this->one("SELECT COALESCE(cp.display_name,CONCAT(u.first_name,' ',u.last_name)) display_name,COALESCE(cp.email,u.email) email,cp.phone FROM users u LEFT JOIN consultant_profiles cp ON cp.user_id=u.id WHERE u.id=:id",['id'=>$q['assigned_agent_id']]);
        return ['quote'=>['reference'=>$q['reference'],'revision_number'=>(int)$q['revision_number'],'title'=>$q['title'],'product_type'=>$q['product_type'],'currency'=>$q['currency'],'destination_summary'=>$q['destination_summary'],'departure_date'=>$q['departure_date'],'return_date'=>$q['return_date'],'expires_at_utc'=>$q['expires_at_utc'],'customer_introduction'=>$q['customer_introduction'],'customer_notes'=>$q['customer_notes']],'customer'=>['display_name'=>$customer['first_name'].' '.$customer['last_name'],'email'=>$customer['email']],'travellers'=>$travellers,'components'=>$components,'optional_extras'=>$optional,'pricing'=>['customer_total'=>$q['customer_total'],'deposit'=>$q['deposit_amount'],'balance'=>$q['balance_amount'],'balance_due_date'=>$q['balance_due_date']],'inclusions'=>'Included arrangements shown above.','compliance_text'=>'All unavoidable charges are included in the displayed total. Optional extras are clearly separated.','consultant'=>$consultant,'generated_at_utc'=>gmdate('Y-m-d H:i:s')];
    }
    /** @return array<string,mixed> */ private function proposal(int $quote,int $id):array{$r=$this->one('SELECT * FROM proposal_versions WHERE id=:id AND quote_id=:quote',['id'=>$id,'quote'=>$quote]);return$r;}
    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function one(string $sql,array $p):array{$s=$this->pdo->prepare($sql);$s->execute($p);$r=$s->fetch();if(!is_array($r)){throw new RuntimeException('Record not found.');}return$r;}
    /**
     * @param array<string,mixed> $p
     * @return list<array<string,mixed>>
     */
    private function all(string $sql,array $p):array{$s=$this->pdo->prepare($sql);$s->execute($p);return array_values($s->fetchAll());}
}
