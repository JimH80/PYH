<?php
declare(strict_types=1);
namespace PYH\Tests\Security;
use PHPUnit\Framework\TestCase;use PYH\Application\ProposalRenderer;
final class ProposalDisclosureTest extends TestCase{
 public function testCustomerOutputEscapesXssAndHasNoCommercialFields():void{$s=['quote'=>['title'=>'<script>alert(1)</script>','reference'=>'PYH-Q-1','revision_number'=>1,'product_type'=>'Cruise & Stay','currency'=>'GBP','customer_introduction'=>'Hello','expires_at_utc'=>'2027-01-01'],'travellers'=>[['display_name'=>'Jane <Doe>','traveller_type'=>'Adult','is_lead'=>true]],'components'=>[['component_type'=>'Flight','title'=>'Journey','customer_description'=>'Safe','origin'=>'LHR','destination'=>'FNC']],'optional_extras'=>[],'pricing'=>['customer_total'=>'100.00','deposit'=>'10.00','balance'=>'90.00'],'inclusions'=>'Flights','compliance_text'=>'Clear price','consultant'=>['display_name'=>'Agent','email'=>'a@example.test']];$html=(new ProposalRenderer())->render($s);self::assertStringContainsString('GBP 100.00',$html);self::assertStringContainsString('Jane &lt;Doe&gt;',$html);self::assertStringNotContainsString('<script>',$html);foreach(['supplier_cost','commission','margin','internal_notes','audit_events','csrf','password'] as $forbidden){self::assertStringNotContainsString($forbidden,$html);}}
}
