<?php

declare(strict_types=1);
namespace PYH\Domain\Booking;

use Closure;
use RuntimeException;

final class BookingReferenceGenerator
{
    private const A='0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    /** @var Closure(int):string */
    private readonly Closure $randomBytes;

    /** @param (Closure(int):string)|null $randomBytes */
    public function __construct(?Closure $randomBytes=null)
    {
        $this->randomBytes=$randomBytes??static function(int $length):string {
            if ($length < 1) { throw new RuntimeException('Invalid entropy length.'); }
            return random_bytes($length);
        };
    }

    public function generate(string $productType,string $quoteReference): string
    {
        $bytes=($this->randomBytes)(10);
        if(strlen($bytes)!==10){throw new RuntimeException('Invalid booking reference entropy.');}
        $prefix=$productType==='Cruise'?'C':'P';$suffix='';
        foreach(str_split($bytes) as $b){$suffix.=self::A[ord($b)&31];}
        return 'PYH-'.$prefix.'-'.$suffix.'-'.substr(hash('sha256',$quoteReference),0,6);
    }
}
