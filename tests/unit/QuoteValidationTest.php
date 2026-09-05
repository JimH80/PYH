<?php
declare(strict_types=1);
namespace PYH\Tests\Unit;
use PHPUnit\Framework\TestCase;use PYH\Domain\Quote\ComplianceEvaluator;use PYH\Domain\Quote\ReadinessValidator;
final class QuoteValidationTest extends TestCase{
 public function testComplianceBlocksMissingEvidence():void{$r=(new ComplianceEvaluator())->evaluate([]);self::assertSame('Block',$r['result']);self::assertCount(6,$r['reasons']);}
 public function testCompliancePassesCompleteEvidence():void{$e=array_fill_keys(['total_price_clear','mandatory_charges_included','material_information_present','supplier_identity_present','deposit_balance_clear','significant_terms_present','availability_caveat_present'],true);self::assertSame('Pass',(new ComplianceEvaluator())->evaluate($e)['result']);}
 public function testReadinessReturnsGroupedHumanErrors():void{$r=(new ReadinessValidator())->validate([],[],[],false,'Block');self::assertArrayHasKey('overview',$r);self::assertArrayHasKey('travellers',$r);self::assertArrayHasKey('arrangements',$r);self::assertArrayHasKey('pricing',$r);self::assertArrayHasKey('compliance',$r);}
 public function testReadinessAcceptsValidAggregate():void{$q=['customer_id'=>1,'departure_date'=>'2027-01-01','return_date'=>'2027-01-02','expires_at_utc'=>'2026-12-01'];self::assertSame([],(new ReadinessValidator())->validate($q,[['is_lead'=>true]],[['inclusion_state'=>'Included']],true,'Pass'));}
}
