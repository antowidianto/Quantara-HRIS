<?php
namespace App\Libraries\Approval\Adapters;
use App\Libraries\Approval\ApprovalDomainAdapterInterface;
use App\Libraries\Approval\PrivilegedActionApprovalService;
final class PrivilegedActionApprovalDomainAdapter implements ApprovalDomainAdapterInterface
{
    public function __construct(private readonly ?PrivilegedActionApprovalService $workflow=null){}
    public function module():string{return 'PRIVILEGED_ACTION';}
    public function apply(array $request,string $decision,int $actorUserId,string $note):array{$w=$this->workflow??new PrivilegedActionApprovalService();return $decision==='APPROVED'?$w->approve($request,$actorUserId):$w->reject((int)$request['entity_id'],(int)$request['company_id'],$actorUserId,$note);}
}
