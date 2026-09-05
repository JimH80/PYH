<?php
declare(strict_types=1);
namespace PYH\Tests\Unit;
use PHPUnit\Framework\TestCase;use PYH\Domain\Quote\QuoteReferenceGenerator;
final class QuoteReferenceTest extends TestCase{public function testCollisionResistantDateFreeFormat():void{$g=new QuoteReferenceGenerator(static fn(int $n):string=>str_repeat("\x01",$n));$r=$g->generate();self::assertSame('PYH-Q-111111111111',$r);self::assertDoesNotMatchRegularExpression('/20\d\d/',$r);}}
