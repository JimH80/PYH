<?php

declare(strict_types=1);
namespace PYH\Application;

use PDO;
use RuntimeException;
use PYH\Security\Actor;
use PYH\Security\PermissionEvaluator;
use PYH\Domain\Quote\Money;

abstract class BookingOperation
{
    public function __construct(protected readonly PDO $pdo, protected readonly PermissionEvaluator $permissions, protected readonly AuditService $audit) {}
    /** @return array<string,mixed> */
    protected function booking(Actor $actor,int $id,string $permission='bookings.view',bool $write=false):array
    {
        $this->permissions->assertAllowed($actor,$permission);
        if($permission==='bookings.finance_manage'){$this->permissions->assertAllowed($actor,'bookings.finance_view');}
        $b=(new BookingService($this->pdo,$this->permissions,$this->audit))->load($actor,$id,$write);
        if($write && !in_array($b['status'],['Booked','Amended'],true)){throw new RuntimeException('Booking is not operationally editable.');}
        return $b;
    }
    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    protected function atomic(callable $operation):mixed
    {
        $this->pdo->beginTransaction();
        try{$result=$operation();$this->pdo->commit();return $result;}catch(\Throwable $e){$this->pdo->rollBack();if($e instanceof \PDOException || $e instanceof \DomainException || $e instanceof \InvalidArgumentException){throw new RuntimeException('Invalid booking operation.',0,$e);}throw $e;}
    }
    /**
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     */
    protected function rows(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return array_values($s->fetchAll());}
    /**
     * @param list<mixed> $params
     * @return array<string,mixed>
     */
    protected function one(string $sql,array $params=[]):array{$rows=$this->rows($sql,$params);return $rows[0]??throw new RuntimeException('Record not found.');}
    /** @param array<string,mixed> $data */
    protected function insert(string $table,array $data):int{$this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));return (int)$this->pdo->lastInsertId();}
    /** @param array<string,mixed> $data */
    protected function change(string $table,int $id,array $data):void{$this->pdo->prepare('UPDATE '.$table.' SET '.implode(',',array_map(static fn(string $k):string=>$k.'=?',array_keys($data))).' WHERE id=?')->execute([...array_values($data),$id]);}
    /**
     * @param array<string,mixed> $data
     * @param list<string> $allowed
     */
    protected function fields(array $data,array $allowed):void{if(array_diff(array_keys($data),$allowed)!==[]){throw new RuntimeException('Unsupported fields.');}}
    protected function text(mixed $value,int $max=200,bool $required=true):?string{if(($value===null||$value==='')&&!$required){return null;}if(!is_string($value)||trim($value)===''||mb_strlen($value)>$max){throw new RuntimeException('Invalid text field.');}return trim($value);}
    protected function money(mixed $value,bool $positive=false):string{if(!is_string($value)||preg_match('/^\d{1,11}(?:\.\d{1,2})?$/',$value)!==1){throw new RuntimeException('Invalid monetary magnitude.');}$minor=Money::minor($value);if($positive&&$minor===0){throw new RuntimeException('Amount must be positive.');}return Money::decimal($minor);}
    /** @param list<string> $values */
    protected function choice(mixed $value,array $values):string{if(!is_string($value)||!in_array($value,$values,true)){throw new RuntimeException('Invalid selection.');}return $value;}
    protected function timestamp(mixed $value,bool $required=false):?string{if(($value===null||$value==='')&&!$required){return null;}if(!is_string($value)){throw new RuntimeException('Invalid UTC timestamp.');}$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));if($d===false||$d->format('Y-m-d H:i:s')!==$value){throw new RuntimeException('Invalid UTC timestamp.');}return $value;}
    protected function element(int $booking,mixed $id):?int{if($id===null||$id===''){return null;}$n=filter_var($id,FILTER_VALIDATE_INT);if($n===false||$n<1){throw new RuntimeException('Invalid element.');}$this->one('SELECT id FROM booking_elements WHERE id=? AND booking_id=?',[$n,$booking]);return $n;}
    protected function user(Actor $actor,mixed $id):?int{if($id===null||$id===''){return null;}$n=filter_var($id,FILTER_VALIDATE_INT);if($n===false||$n<1){throw new RuntimeException('Invalid user.');}$this->one('SELECT id FROM users WHERE id=? AND organisation_id=? AND is_active=1',[$n,$actor->organisationId]);return $n;}
    /** @param array<string,mixed> $details */
    protected function event(Actor $actor,int $booking,string $action,array $details=[]):void{$this->audit->record($actor->organisationId,$actor->userId,$action,'booking',$booking,null,$details);}
}
